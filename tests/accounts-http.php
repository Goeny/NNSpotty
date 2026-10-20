<?php
declare(strict_types=1);

$accountRepo = new \NnSpotty\Users\UserRepository($pdo);
$management = httpFixture($httpPort, '/manage', $cookie);
check($management['status'] === 200 && str_contains($management['body'], 'Gebruikers en groepen')
    && str_contains($management['body'], 'group-save'), 'Admin opent gebruikers- en groepsbeheer');
$managementDenied = httpFixture($httpPort, '/manage', $readerCookie);
check($managementDenied['status'] === 403, 'Gewone gebruiker heeft geen beheerpagina');
$groupCreateResponse = httpFixture($httpPort, '/manage', $cookie, ['_csrf' => $token[1], 'action' => 'group-save',
    'name' => 'HTTP beperkte groep', 'permission_nzb_download' => '1']);
$httpCustomGroup = (int) $pdo->query("SELECT id FROM user_groups WHERE name = 'HTTP beperkte groep'")->fetchColumn();
check($groupCreateResponse['status'] === 302 && $httpCustomGroup > 0
    && str_contains(implode("\n", $groupCreateResponse['headers']), '#groups'), 'Admin maakt groep met rechten via UI-route');
$createAccountForm = ['_csrf' => $token[1], 'action' => 'create', 'username' => 'account-http', 'password' => 'temporary-password-http'];
httpFixture($httpPort, '/manage', $cookie, array_replace($createAccountForm, ['_csrf' => 'wrong']));
check($accountRepo->findByUsername('account-http') === null, 'Accountbeheer vereist CSRF');
httpFixture($httpPort, '/manage', $cookie, $createAccountForm);
check($accountRepo->findByUsername('account-http')['must_change_password'], 'UI maakt account met tijdelijk wachtwoord');
$accountCookie = '';
$accountLogin = httpFixture($httpPort, '/login', $accountCookie);
preg_match('/name="_csrf" value="([^"]+)"/', $accountLogin['body'], $accountToken);
httpFixture($httpPort, '/login', $accountCookie, ['_csrf' => $accountToken[1], 'username' => 'account-http', 'password' => 'temporary-password-http']);
check(str_contains(implode("\n", httpFixture($httpPort, '/spots', $accountCookie)['headers']), '/account/password'),
    'Tijdelijk wachtwoord dwingt wijziging af voor spottoegang');
$passwordPage = httpFixture($httpPort, '/account/password', $accountCookie);
preg_match('/name="_csrf" value="([^"]+)"/', $passwordPage['body'], $accountToken);
$changeAccountPassword = ['_csrf' => $accountToken[1], 'action' => 'password', 'current_password' => 'temporary-password-http',
    'new_password' => 'permanent-password-http', 'password_confirm' => 'permanent-password-http'];
httpFixture($httpPort, '/account/profile', $accountCookie, array_replace($changeAccountPassword, ['current_password' => 'wrong']));
check($accountRepo->findByUsername('account-http')['must_change_password'], 'Wachtwoord wijzigen controleert huidig wachtwoord');
httpFixture($httpPort, '/account/profile', $accountCookie, $changeAccountPassword);
check(!$accountRepo->findByUsername('account-http')['must_change_password']
    && httpFixture($httpPort, '/spots', $accountCookie)['status'] === 200, 'Verplicht wachtwoord wijzigen maakt eigen nieuwe sessie bruikbaar');
$profileResponse = httpFixture($httpPort, '/account/profile', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'profile',
    'display_name' => 'Publieke HTTP-naam', 'signature' => "Met groet\nHTTP", 'email' => 'private@example.invalid', 'username' => 'http-tester']);
check($profileResponse['status'] === 302 && $accountRepo->findByUsername('account-http')['display_name'] === 'Publieke HTTP-naam'
    && $accountRepo->findByUsername('http-tester')['display_name'] === 'http-tester',
    'Profiel bewerken is gebonden aan eigen sessie, niet formulierusername');
$profilePage = httpFixture($httpPort, '/preferences#profile', $accountCookie);
check(str_contains($profilePage['body'], 'Publieke HTTP-naam') && str_contains($profilePage['body'], 'gravatar_consent'),
    'Profieltab en header tonen gewijzigde profielnaam');
$accountRepo->manage('account-http', true, [$httpCustomGroup]);
check(httpFixture($httpPort, '/manage', $accountCookie)['status'] === 403
    && httpFixture($httpPort, '/spots/comment', $accountCookie, ['_csrf' => $accountToken[1]])['status'] === 403
    && httpFixture($httpPort, '/downloader', $accountCookie)['status'] === 403
    && !str_contains(httpFixture($httpPort, '/spots/view?key=' . $spotId, $accountCookie)['body'], 'data-comment-form'),
    'Groepsrechten wijzigen bestaande sessie direct, inclusief verborgen publicatieformulier en directe endpoints');
httpFixture($httpPort, '/manage', $cookie, ['_csrf' => $token[1], 'action' => 'group-save',
    'group_id' => (string) $httpCustomGroup, 'name' => 'HTTP beperkte groep', 'permission_nzb_download' => '1', 'permission_comments_publish' => '1']);
check(str_contains(httpFixture($httpPort, '/spots/view?key=' . $spotId, $accountCookie)['body'], 'data-comment-form'),
    'Aanpassen van groepsrechten wordt zonder nieuwe login toegepast');
if (extension_loaded('gd')) {
    $avatarImage = imagecreatetruecolor(16, 12);
    ob_start();
    imagepng($avatarImage);
    $avatarBytes = ob_get_clean();
    imagedestroy($avatarImage);
    $avatarBoundary = 'NNSpottyAvatar' . bin2hex(random_bytes(8));
    $avatarMultipart = '';
    foreach (['_csrf' => $accountToken[1], 'action' => 'avatar', 'source' => 'upload'] as $field => $value) {
        $avatarMultipart .= "--$avatarBoundary\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n";
    }
    $avatarMultipart .= "--$avatarBoundary\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"synthetic.png\"\r\nContent-Type: image/png\r\n\r\n"
        . $avatarBytes . "\r\n--$avatarBoundary--\r\n";
    $avatarContext = stream_context_create(['http' => ['method' => 'POST',
        'header' => "Cookie: $accountCookie\r\nContent-Type: multipart/form-data; boundary=$avatarBoundary\r\n",
        'content' => $avatarMultipart, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    file_get_contents('http://127.0.0.1:' . $httpPort . '/account/profile', false, $avatarContext);
    $avatarGet = httpFixture($httpPort, '/account/avatar?user=account-http', $accountCookie);
    $avatarInfo = getimagesizefromstring($avatarGet['body']);
    check($avatarGet['status'] === 200 && $avatarInfo[0] === 256 && $avatarInfo[1] === 256
        && str_contains(implode("\n", $avatarGet['headers']), 'nosniff'),
        'Echte multipartupload wordt als genormaliseerde databaseavatar geserveerd');
    check(str_contains(httpFixture($httpPort, '/spots', $accountCookie)['body'], 'class="account-avatar"'),
        'Header toont ingestelde profielfoto');
    $anonymousAvatarCookie = '';
    check(httpFixture($httpPort, '/account/avatar?user=account-http', $anonymousAvatarCookie)['status'] === 401,
        'Profielfoto is niet publiek opvraagbaar zonder login');
    httpFixture($httpPort, '/account/profile', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'avatar', 'source' => 'remove']);
    check(httpFixture($httpPort, '/account/avatar?user=account-http', $accountCookie)['status'] === 404,
        'Profielfoto verwijderen wist databaseavatar');
}
httpFixture($httpPort, '/account/profile', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'avatar',
    'source' => 'url', 'avatar_url' => 'https://127.0.0.1/secret']);
check(!$accountRepo->findByUsername('account-http')['has_avatar'], 'Interne remote avatar-URL wordt niet opgehaald of opgeslagen');
$groupsByKey = [];
foreach ($accountRepo->groups() as $group) $groupsByKey[$group['system_key']] = (int) $group['id'];
$accountRepo->manage('account-http', true, [$groupsByKey['moderators']]);
check(httpFixture($httpPort, '/manage', $accountCookie)['status'] === 200
    && !str_contains(httpFixture($httpPort, '/manage', $accountCookie)['body'], 'synthetic-provider-secret'),
    'Moderator krijgt beperkte beheerpagina zonder systeemcredentials');
check(httpFixture($httpPort, '/admin/settings', $accountCookie, ['_csrf' => $accountToken[1]])['status'] !== 200,
    'Moderator krijgt geen systeeminstellingen');
check(httpFixture($httpPort, '/metadata', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'settings'])['status'] === 403
    && httpFixture($httpPort, '/music-metadata', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'settings'])['status'] === 403,
    'Metadata-correctierecht geeft geen providerinstellingen vrij');
$spotterBefore = (new \NnSpotty\Moderation\SpotterLists($pdo))->settings()['enabled'];
$moderatorSpotterResponse = httpFixture($httpPort, '/admin/spotter-lists', $accountCookie,
    ['_csrf' => $accountToken[1], 'action' => 'settings'] + ($spotterBefore ? ['enabled' => '1'] : []));
check($moderatorSpotterResponse['status'] === 302, 'Moderator kan spotterinstelling beheren zonder providercredentials of sync');
$blockedCreate = httpFixture($httpPort, '/manage', $accountCookie, ['_csrf' => $accountToken[1], 'action' => 'create',
    'username' => 'must-not-exist', 'password' => 'synthetic-invalid-user']);
check($blockedCreate['status'] === 403 && $accountRepo->findByUsername('must-not-exist') === null,
    'Moderator mag geen gebruikers aanmaken via directe POST');
check(!str_contains(httpFixture($httpPort, '/manage', $accountCookie)['body'], 'private@example.invalid'),
    'Beperkte beheerpagina lekt geen prive-profielgegevens');
$accountRepo->saveGroup($httpCustomGroup, 'HTTP beperkte groep', ['settings.manage']);
$accountRepo->manage('account-http', true, [$httpCustomGroup]);
$limitedSettings = httpFixture($httpPort, '/admin', $accountCookie);
check($limitedSettings['status'] === 200 && !str_contains($limitedSettings['body'], 'id="sab-url-central"')
    && str_contains($limitedSettings['body'], 'uitsluitend voor beheerders'),
    'Systeeminstellingenrecht geeft geen centrale downloadercredentials of interne netwerkprivileges');
$accountRepo->manage('account-http', false, [$groupsByKey['moderators']]);
check(httpFixture($httpPort, '/spots', $accountCookie)['status'] === 302, 'Deactiveren blokkeert bestaande browsersessie direct');
$accountRepo->manage('account-http', true, [$groupsByKey['users']]);
check(httpFixture($httpPort, '/spots', $accountCookie)['status'] === 302, 'Reactiveren herstelt ingetrokken oude sessie niet');
httpFixture($httpPort, '/login', $accountCookie, ['_csrf' => $accountToken[1], 'username' => 'account-http', 'password' => 'permanent-password-http']);
check(httpFixture($httpPort, '/spots', $accountCookie)['status'] === 200, 'Gereactiveerd account kan opnieuw inloggen');
httpFixture($httpPort, '/manage', $cookie, ['_csrf' => $token[1], 'action' => 'reset-password',
    'username' => 'account-http', 'password' => 'reset-password-http']);
check(httpFixture($httpPort, '/spots', $accountCookie)['status'] === 302
    && $accountRepo->findByUsername('account-http')['must_change_password'],
    'Adminwachtwoordreset trekt bestaande sessie in en verplicht opnieuw wijzigen');
$accountRepo->delete('account-http');
httpFixture($httpPort, '/manage', $cookie, ['_csrf' => $token[1], 'action' => 'group-delete',
    'group_id' => (string) $httpCustomGroup, 'confirm' => '1']);
check((int) $pdo->query("SELECT COUNT(*) FROM user_groups WHERE id = $httpCustomGroup")->fetchColumn() === 0,
    'Admin verwijdert lege eigen groep via bevestigde UI-route');
$accountAudit = $pdo->query("SELECT details_json FROM admin_audit_logs WHERE action LIKE 'accounts.%' OR action = 'user.password'")->fetchAll(PDO::FETCH_COLUMN);
check($accountAudit !== [] && !str_contains(implode('', $accountAudit), 'password-http'), 'Accountaudit bevat geen wachtwoorden');
