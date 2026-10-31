<?php
declare(strict_types=1);

use NnSpotty\Users\Permissions;
use NnSpotty\Users\UserRepository;

$accountFixture = ['username' => 'reader', 'active' => true, 'must_change_password' => false,
    'is_admin' => false, 'permissions' => ['comments.publish', 'retrieve.run']];
check(Permissions::allows($accountFixture, 'comments.publish') && !Permissions::allows($accountFixture, 'users.manage'),
    'Accountrechten staan alleen toegewezen functies toe');
check(!Permissions::allows(null, 'comments.publish')
    && !Permissions::allows(array_replace($accountFixture, ['active' => false]), 'comments.publish')
    && !Permissions::allows(array_replace($accountFixture, ['must_change_password' => true]), 'comments.publish'),
    'Anoniem, inactief en tijdelijk wachtwoord hebben geen functierechten');
check(Permissions::allows(array_replace($accountFixture, ['is_admin' => true]), 'groups.manage'),
    'Beschermde beheerder heeft alle rechten');
$profileFixture = UserRepository::profileValues('  Publieke naam  ', "regel 1\r\nregel 2", '');
check($profileFixture === ['display_name' => 'Publieke naam', 'signature' => "regel 1\nregel 2", 'email' => ''],
    'Profielnaam en handtekening worden begrensd en genormaliseerd');
foreach ([['', '', ''], ["naam\nander", '', ''], [str_repeat('n', 81), '', ''],
    ['naam', "1\n2\n3\n4", ''], ['naam', str_repeat('s', 201), ''], ['naam', '', 'geen-mail'],
    ["naam\u{1f600}", '', ''], ['naam', "\u{1f600}", '']] as $invalidProfile) {
    rejects(static fn () => UserRepository::profileValues(...$invalidProfile), 'Ongeldig profiel expliciet afgewezen');
}
foreach (['kort', str_repeat('p', 73), "nul\0wachtwoord"] as $invalidPassword) {
    rejects(static fn () => UserRepository::validatePassword($invalidPassword), 'Ongeldig nieuw wachtwoord afgewezen');
}
UserRepository::validatePassword('lang-genoeg-wachtwoord');
$profileHtml = \NnSpotty\Users\View::profile($accountFixture + ['display_name' => '<name>', 'signature' => '<signature>', 'email' => 'private@example.invalid'], '');
check(str_contains($profileHtml, '&lt;name&gt;') && str_contains($profileHtml, '&lt;signature&gt;')
    && str_contains($profileHtml, 'enctype="multipart/form-data"') && str_contains($profileHtml, 'gravatar_consent'),
    'Profielinvoer is escaped met upload en expliciete Gravatar-toestemming');
\NnSpotty\UI\SpotLayout::account($accountFixture + ['display_name' => 'Public reader', 'has_avatar' => true, 'signature' => 'Groeten']);
$accountHeader = \NnSpotty\UI\SpotLayout::render('Test', 'reader', false, '', 'inhoud');
check(str_contains($accountHeader, 'Public reader') && str_contains($accountHeader, 'account-avatar')
    && str_contains($accountHeader, 'action="/admin/retrieve"') && !str_contains($accountHeader, 'href="/admin"'),
    'Header toont publieke naam/avatar en retrieve voor bevoegde niet-admin zonder systeembeheerlink');
$limitedHeader = \NnSpotty\UI\SpotLayout::render('Test', 'reader', true, '', 'inhoud');
check(!str_contains($limitedHeader, 'data-download-open=') && !str_contains($limitedHeader, '<option value="central:'),
    'Actuele accountrechten gaan voor legacy renderer-adminvlag en verbergen verboden downloadacties');
\NnSpotty\UI\SpotLayout::account(null);
