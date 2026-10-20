<?php

declare(strict_types=1);

use NnSpotty\Users\UserRepository;

$httpSocket = stream_socket_server('tcp://127.0.0.1:0', $httpErrno, $httpError);
if ($httpSocket === false) {
    throw new RuntimeException($httpError, $httpErrno);
}
$httpPort = (int) substr(strrchr(stream_socket_get_name($httpSocket, false), ':'), 1);
fclose($httpSocket);
$dbConfig = [];
foreach (explode(';', substr($dsn, 6)) as $parameter) {
    $pair = explode('=', $parameter, 2);
    if (count($pair) === 2) {
        $dbConfig[$pair[0]] = $pair[1];
    }
}
$environment = array_merge(getenv(), [
    'APP_URL' => 'http://127.0.0.1:' . $httpPort,
    'DB_HOST' => $dbConfig['host'] ?? '127.0.0.1',
    'DB_PORT' => $dbConfig['port'] ?? '3306',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => getenv('NNSPOTTY_TEST_USER') ?: 'root',
    'DB_PASSWORD' => getenv('NNSPOTTY_TEST_PASSWORD') ?: '',
    'DB_CHARSET' => 'utf8mb4',
    'APP_TIMING' => 'true',
]);
if (PHP_OS_FAMILY === 'Windows' && $environment['DB_PASSWORD'] === '') {
    throw new RuntimeException('HTTP-integratietests op Windows vereisen een testdatabasegebruiker met een niet-leeg wachtwoord; Windows kan lege child-process environmentvariabelen verwijderen.');
}
$password = bin2hex(random_bytes(20));
(new UserRepository($pdo))->create('http-tester', $password, true);
$httpPipes = [];
$httpLog = tmpfile();
if ($httpLog === false) {
    throw new RuntimeException('Kon tijdelijk HTTP-testlog niet openen.');
}
$httpServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', __DIR__ . '/../public'],
    [['pipe', 'r'], $httpLog, $httpLog],
    $httpPipes,
    dirname(__DIR__),
    $environment
);
if (!is_resource($httpServer)) {
    throw new RuntimeException('Kon HTTP fixture niet starten.');
}

/** @return array{status: int, body: string, headers: list<string>} */
function httpFixture(int $port, string $path, string &$cookie, ?array $form = null): array
{
    $context = stream_context_create(['http' => [
        'method' => $form === null ? 'GET' : 'POST',
        'header' => "Cookie: " . $cookie . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $form === null ? '' : http_build_query($form),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $headers = $http_response_header;
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) {
            $cookie = $match[1];
        }
    }
    return ['status' => (int) explode(' ', $headers[0])[1], 'body' => $body, 'headers' => $headers];
}

try {
    $cliPipes = [];
    $cli = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/console', 'retrieve', '--comments-only', '--days=7', '--verbose'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $cliPipes,
        dirname(__DIR__),
        $environment
    );
    if (!is_resource($cli)) {
        throw new RuntimeException('Kon gerichte retrieve CLI-test niet starten.');
    }
    fclose($cliPipes[0]);
    $cliOutput = stream_get_contents($cliPipes[1]);
    $cliError = stream_get_contents($cliPipes[2]);
    fclose($cliPipes[1]);
    fclose($cliPipes[2]);
    check(proc_close($cli) === 0 && $cliError === '' && str_contains($cliOutput, 'comments free.usenet:')
        && !str_contains($cliOutput, 'spots free.pt:') && str_contains($cliOutput, 'groups=1'),
        'Console geeft --comments-only werkelijk door aan retrieval met uitsluitend de testdatabase');
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $httpPort, $connectErrno, $connectError, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'Echte HTTP-app gestart met uitsluitend de testdatabase');
    $cookie = '';
    $rootPage = httpFixture($httpPort, '/', $cookie);
    check($rootPage['status'] === 302 && in_array('Location: /spots', $rootPage['headers'], true),
        'Voormalige homepage verwijst rechtstreeks naar spotsoverzicht');
    $anonymous = httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie);
    check($anonymous['status'] === 401 && httpFixture($httpPort, '/retrieve/status', $cookie)['status'] === 401,
        'Afbeelding- en retrieve-status-endpoints vereisen login');
    check(httpFixture($httpPort, '/spots/facets?category=Beeld', $cookie)['status'] === 401
        && httpFixture($httpPort, '/spots/preview?key=' . $spotId, $cookie)['status'] === 401
        && httpFixture($httpPort, '/spots?batch=1', $cookie)['status'] === 401, 'Asynchrone preview en batches vereisen login zonder HTML-redirect');
    $login = httpFixture($httpPort, '/login', $cookie);
    preg_match('/name="_csrf" value="([^"]+)"/', $login['body'], $token);
    check($login['status'] === 200 && isset($token[1]), 'Loginpagina levert een CSRF token');
    $loginResult = httpFixture($httpPort, '/login', $cookie, ['_csrf' => $token[1], 'username' => 'http-tester', 'password' => $password]);
    check($loginResult['status'] === 302 && in_array('Location: /spots', $loginResult['headers'], true),
        'Geslaagde sessie-login opent rechtstreeks het overzicht');
    check(httpFixture($httpPort, '/spots/facets?category=Beeld', $cookie)['status'] === 409,
        'Nieuwtellers zonder browsegrens melden een fout en starten niet zelf een bezoek');
    $loggedInLogin = httpFixture($httpPort, '/login', $cookie);
    check($loggedInLogin['status'] === 302 && in_array('Location: /spots', $loggedInLogin['headers'], true),
        'Ingelogde bezoeker van loginpagina gaat direct naar overzicht');
    $retrieveStatus = json_decode(httpFixture($httpPort, '/retrieve/status', $cookie)['body'], true, 512, JSON_THROW_ON_ERROR);
    check(isset($retrieveStatus['running'], $retrieveStatus['last_status']) && $retrieveStatus['last_status'] === 'completed',
        'HTTP-status toont laatste retrieve, inclusief uitgevoerd via console');
    check(httpFixture($httpPort, '/admin/retrieve', $cookie, [])['status'] === 302,
        'Handmatige retrieve weigert een ontbrekende CSRF-token zonder een run te starten');
    $list = httpFixture($httpPort, '/spots', $cookie);
    check($list['status'] === 200 && substr_count($list['body'], 'data-preview=') === 3
        && str_contains($list['body'], 'aria-hidden="true" inert'), 'HTTP-overzicht bevat drie individuele spots en een gesloten detailpaneel');
    $headList = httpFixture($httpPort, '/spots?category=Beeld', $cookie);
    check($headList['status'] === 200 && str_contains($headList['body'], '>WEBRip<span')
        && str_contains($headList['body'], '>Sciencefiction<span') && !str_contains($headList['body'], '>Erotica<span'), 'Hoofdcategorie toont volledige sidebar zonder verborgen erotica');
    check(str_contains($headList['body'], 'data-facets-url=') && str_contains($headList['body'], 'Nieuwtellers laden...')
        && count(array_filter($headList['headers'], static fn (string $header): bool => str_starts_with($header, 'Server-Timing: session;dur='))) === 1,
        'Pagina levert filters direct en laadt nieuwtellers apart; admin kan veilige timings bekijken');
    $soundList = httpFixture($httpPort, '/spots?category=Geluid', $cookie);
    check($soundList['status'] === 200 && str_contains($soundList['body'], '>FLAC<span')
        && !str_contains($soundList['body'], '>WEBRip<span'), 'Hoofdcategorielink wisselt de bijbehorende sidebar via echte HTTP-route');
    $firstVisitPage = httpFixture($httpPort, '/spots?category=Beeld&q=Visit+Route+Fixture', $cookie);
    $firstVisitCounts = json_decode(httpFixture($httpPort, '/spots/facets?category=Beeld&q=Visit+Route+Fixture', $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['counts'];
    check($firstVisitCounts === [], 'Eerste browsersessie begint met nul nieuwe spots, niet het volledige historische totaal');
    foreach (['category[]=Beeld', 'category=onbekend', 'category=Beeld&subcategory=invalid', 'category=Beeld&q=' . str_repeat('x', 201)] as $invalidFacetQuery) {
        check(httpFixture($httpPort, '/spots/facets?' . $invalidFacetQuery, $cookie)['status'] === 400,
            'Nieuwtellerendpoint weigert ongeldige categorie, filters of zoektekst');
    }
    $routeVisitSpot = \NnSpotty\Usenet\SpotnetParser::spot('visit-route@example.invalid', Fixtures::headers('visit-route@example.invalid'));
    $routeVisitSpot['title'] = 'Visit Route Fixture';
    $routeVisitSpot['subcategories'] = ['1z00', '1a09'];
    $routeVisitSpot['posted_at'] = '2000-01-01 00:00:00';
    $spots->saveSpot($routeVisitSpot, 'free.pt');
    $visitPath = '/spots?category=Beeld&q=Visit+Route+Fixture';
    $visitPage = httpFixture($httpPort, $visitPath, $cookie);
    $visitCountPath = '/spots/facets?category=Beeld&q=Visit+Route+Fixture';
    $visitCounts = json_decode(httpFixture($httpPort, $visitCountPath, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['counts'];
    check($visitCounts['0:z0'] === 1
        && !str_contains($visitPage['body'], 'individuele spots'),
        'Nieuw geimporteerde oude post telt als nieuw sinds bezoek, niet op basis van publicatiedatum');
    httpFixture($httpPort, $visitPath, $cookie);
    $repeatCounts = json_decode(httpFixture($httpPort, $visitCountPath, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['counts'];
    check($repeatCounts['0:z0'] === 1
        && str_contains(httpFixture($httpPort, '/spots/view?key=' . $routeVisitSpot['id'], $cookie)['body'], 'data-facets-url='),
        'Herladen, filters en detailnavigatie houden de huidige sessiegrens vast');
    $visitBeforeBatch = $pdo->query("SELECT last_visit_at FROM user_browse_visits WHERE username = 'http-tester'")->fetchColumn();
    httpFixture($httpPort, '/spots?batch=1', $cookie);
    httpFixture($httpPort, $visitCountPath, $cookie);
    check($pdo->query("SELECT last_visit_at FROM user_browse_visits WHERE username = 'http-tester'")->fetchColumn() === $visitBeforeBatch,
        'Achtergrondbijladen markeert geen nieuw bezoek of activiteit');
    $newVisitCookie = '';
    $newVisitLogin = httpFixture($httpPort, '/login', $newVisitCookie);
    preg_match('/name="_csrf" value="([^"]+)"/', $newVisitLogin['body'], $newVisitToken);
    httpFixture($httpPort, '/login', $newVisitCookie, ['_csrf' => $newVisitToken[1], 'username' => 'http-tester', 'password' => $password]);
    $nextVisitPage = httpFixture($httpPort, $visitPath, $newVisitCookie);
    $nextVisitCounts = json_decode(httpFixture($httpPort, $visitCountPath, $newVisitCookie)['body'], true, 512, JSON_THROW_ON_ERROR)['counts'];
    check($nextVisitCounts === []
        && !str_contains($nextVisitPage['body'], 'individuele spots'), 'Nieuwe browsersessie gebruikt laatste browseactiviteit als grens zonder totaaltelling');
    check(json_decode(httpFixture($httpPort, $visitCountPath, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['counts']['0:z0'] === 1,
        'Nieuwe login op tweede apparaat wist de teller van een al geopende sessie niet');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$routeVisitSpot['id']]);
    $oldDescription = $spots->getSpot($spotId)['description'];
    $descriptionUpdate = $pdo->prepare('UPDATE spotnet_spots SET description = :description WHERE id = :id');
    $descriptionUpdate->execute(['description' => '<p>Route <strong>opmaak</strong></p><script>unsafeFixture()</script>', 'id' => $spotId]);
    $formattedPreviewResponse = httpFixture($httpPort, '/spots/preview?key=' . $spotId, $cookie);
    $formattedPreview = json_decode($formattedPreviewResponse['body'], true, 512, JSON_THROW_ON_ERROR)['html'];
    $formattedDetail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check(str_contains($formattedPreview, '<p>Route <strong>opmaak</strong></p>')
        && str_contains($formattedDetail['body'], '<p>Route <strong>opmaak</strong></p>')
        && !str_contains($formattedPreview, 'unsafeFixture') && !str_contains($formattedDetail['body'], 'unsafeFixture'), 'Preview en detail renderen dezelfde opgeschoonde HTML via echte HTTP-routes');
    check(in_array('Cache-Control: private, no-store', $formattedPreviewResponse['headers'], true), 'Asynchrone preview heeft geen gedeelde of blijvende browsercache');
    $descriptionUpdate->execute(['description' => '10 afleveringen[br]Snowfall.2017.S03.mkv[br][br][b]Multisubs[/b]', 'id' => $spotId]);
    $bbPreview = json_decode(httpFixture($httpPort, '/spots/preview?key=' . $spotId, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['html'];
    $bbDetail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check(str_contains($bbPreview, '10 afleveringen<br>Snowfall.2017.S03.mkv<br><br><strong>Multisubs</strong>')
        && str_contains($bbDetail['body'], '10 afleveringen<br>Snowfall.2017.S03.mkv<br><br><strong>Multisubs</strong>'), 'Spotnet-opmaak wordt via echte HTTP-routes in preview en detail vertaald');
    $descriptionUpdate->execute(['description' => $oldDescription, 'id' => $spotId]);
    $stylesheet = httpFixture($httpPort, '/assets/spotty.css', $cookie);
    check($stylesheet['status'] === 200 && str_contains($stylesheet['body'], '.browse-grid')
        && count(array_filter($stylesheet['headers'], static fn (string $header): bool => stripos($header, 'Content-Type: text/css') === 0)) === 1, 'Lokale stylesheet wordt met CSS MIME-type geserveerd');
    $detail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check($detail['status'] === 200 && str_contains($detail['body'], 'Reacties (3)') && str_contains($detail['body'], 'Een omschrijving'), 'HTTP-detail toont omschrijving en gekoppelde reacties');
    check(str_contains($detail['body'], '<dt>Gepost met</dt><dd>Spotnet 1.9.0.8</dd>')
        && str_contains($detail['body'], '<dt>Hoofdcategorie</dt><dd>Beeld</dd>'), 'Extra detailmetadata blijft zichtbaar via echte HTTP-route');
    $remoteComment = $pdo->prepare('SELECT id, body FROM spotnet_comments WHERE spot_id = :id LIMIT 1');
    $remoteComment->execute(['id' => $spotId]);
    $remoteComment = $remoteComment->fetch();
    $updateComment = $pdo->prepare('UPDATE spotnet_comments SET body = :body WHERE id = :id');
    $updateComment->execute(['body' => '[b]Usenet-opmaak[/b][br]Een nieuwe regel [img=buigen][img=respekt]', 'id' => $remoteComment['id']]);
    $state->addComment($spotId, 'http-tester', '[b]Lokale tekst[/b]<script>localUnsafe()</script>[img=respect]');
    $localMarkupId = $pdo->lastInsertId();
    $commentDetail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check(str_contains($commentDetail['body'], '<strong>Usenet-opmaak</strong><br>Een nieuwe regel')
        && str_contains($commentDetail['body'], '[b]Lokale tekst[/b]&lt;script&gt;localUnsafe()&lt;/script&gt;')
        && !str_contains($commentDetail['body'], '<script>localUnsafe'), 'Usenet-reacties krijgen veilige Spotnet-opmaak; lokale reacties blijven geescapete tekst');
    check(preg_match_all('/<span class="spot-emoticon(?: |")/', $commentDetail['body']) === 3
        && str_contains($commentDetail['body'], \NnSpotty\UI\SpotEmoticons::render('[img=buigen]'))
        && substr_count($commentDetail['body'], \NnSpotty\UI\SpotEmoticons::render('[img=respekt]')) === 2,
        'Echte detailroute vertaalt oude emoticoncodes in lokale en Usenet-reacties zonder GIF-verzoeken');
    $updateComment->execute(['body' => $remoteComment['body'], 'id' => $remoteComment['id']]);
    $pdo->prepare('DELETE FROM post_comments WHERE id = :id')->execute(['id' => $localMarkupId]);
    $imageResponse = httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie);
    check($imageResponse['status'] === 200 && $imageResponse['body'] === Fixtures::image()
        && in_array('Content-Type: image/png', $imageResponse['headers'], true), 'HTTP-afbeelding levert de juiste bytes en MIME');
    check($spots->image($spotId) !== null, 'HTTP-afbeelding wordt bij een cache-miss via NNTP geladen en opgeslagen');
    require __DIR__ . '/session-concurrency.php';
    $before = count($spots->comments($spotId));
    httpFixture($httpPort, '/spots/comment', $cookie, ['spot_id' => $spotId, 'comment' => 'Niet opslaan']);
    check(count($spots->comments($spotId)) === $before, 'Reactie zonder CSRF wordt niet opgeslagen');
    preg_match('/name="_csrf" value="([^"]+)"/', $detail['body'], $token);
    $response = httpFixture($httpPort, '/spots/comment', $cookie, ['_csrf' => $token[1], 'spot_id' => $spotId, 'comment' => 'Via de echte route']);
    check($response['status'] === 302 && count($spots->comments($spotId)) === $before + 1, 'Lokale reactie plaatsen via echte HTTP-route');
    $admin = httpFixture($httpPort, '/admin', $cookie);
    check($admin['status'] === 200 && str_contains($admin['body'], 'comments:free.usenet') && !str_contains($admin['body'], 'alt.binaries.movies'), 'Admin toont de juiste Spotnet-configuratie');
    check(str_contains($admin['body'], 'spotter-lists-enabled') && str_contains($admin['body'], 'Lijsten nu bijwerken')
        && str_contains($admin['body'], 'Oude naamlijsten: alleen registratie'),
        'Admin onderscheidt werkelijke sleutelmoderatie van oude inactieve naamlijsten');
    $httpSpotters = new \NnSpotty\Moderation\SpotterLists($pdo);
    httpFixture($httpPort, '/admin/spotter-lists', $cookie, ['action' => 'settings', 'enabled' => '1']);
    check(!$httpSpotters->settings()['enabled'], 'Sleutelmoderatie wijzigen vereist CSRF');
    httpFixture($httpPort, '/admin/spotter-lists', $cookie, ['_csrf' => $token[1], 'action' => 'settings', 'enabled' => '1']);
    check((bool) $httpSpotters->settings()['enabled'], 'Admin kan sleutelmoderatie expliciet inschakelen');
    $invalidSpotterSettings = httpFixture($httpPort, '/admin/spotter-lists', $cookie,
        ['_csrf' => $token[1], 'action' => 'settings', 'enabled' => ['1']]);
    check($invalidSpotterSettings['status'] === 302 && $httpSpotters->settings()['enabled'],
        'Ongeldige sleutelinstelling wijzigt de opgeslagen keuze niet');
    httpFixture($httpPort, '/admin/spotter-lists', $cookie, ['_csrf' => $token[1], 'action' => 'settings']);
    check(!$httpSpotters->settings()['enabled'], 'Admin kan sleutelmoderatie uitschakelen zonder database-inhoud te verwijderen');
    foreach (['status', 'enable', 'disable'] as $spotterCommand) {
        $spotterCli = proc_open([PHP_BINARY, __DIR__ . '/../bin/console', 'spotters', $spotterCommand],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $spotterCliPipes, dirname(__DIR__), $environment);
        if (!is_resource($spotterCli)) throw new RuntimeException('Spotterlijst-CLI kon niet starten.');
        fclose($spotterCliPipes[0]);
        $spotterOutput = stream_get_contents($spotterCliPipes[1]);
        $spotterError = stream_get_contents($spotterCliPipes[2]);
        fclose($spotterCliPipes[1]);
        fclose($spotterCliPipes[2]);
        check(proc_close($spotterCli) === 0 && $spotterError === ''
            && str_contains($spotterOutput, '"updated_at"')
            && (bool) $httpSpotters->settings()['enabled'] === ($spotterCommand === 'enable'),
            'Werkelijke console ondersteunt spotters ' . $spotterCommand . ' met opgeslagen status');
    }
    check(str_contains($admin['body'], 'id="retrieve_comments"') && str_contains($admin['body'], 'id="show_comments"')
        && str_contains($admin['body'], 'id="images_enabled"'), 'Admin biedt afzonderlijke schakelaars voor ophalen, reacties tonen en afbeeldingen');
    check(str_contains($admin['body'], 'id="retrieve_after"') && str_contains($admin['body'], 'name="retrieve_after"'),
        'Admin biedt een optionele kalenderdatum voor de retrievegrens');
    $originalHttpUsenetSettings = $state->getUsenetSettings();
    httpFixture($httpPort, '/admin/settings', $cookie, [
        '_csrf' => $token[1], 'server' => '127.0.0.1', 'port' => '119', 'retention' => '30',
        'retrieve_after' => '2019-01-02', 'encryption' => 'none', 'explicit_groups' => 'movies:alt.binaries.movies',
        'discover_max_groups' => '0', 'max_articles_per_group' => '100',
    ]);
    check($state->getUsenetSettings()['explicit_groups'] === 'spots:free.pt,comments:free.usenet', 'Admin weigert algemene headergroepen');
    $retrieveAfterBeforeInvalid = $state->getUsenetSettings()['retrieve_after'];
    httpFixture($httpPort, '/admin/settings', $cookie, [
        '_csrf' => $token[1], 'server' => '127.0.0.1', 'port' => '119', 'retention' => '30',
        'retrieve_after' => '2025-02-30', 'encryption' => 'none', 'explicit_groups' => 'spots:free.pt,comments:free.usenet',
        'discover_max_groups' => '0', 'max_articles_per_group' => '100',
    ]);
    check($state->getUsenetSettings()['retrieve_after'] === $retrieveAfterBeforeInvalid,
        'Admin weigert ongeldige kalenderdatums zonder ze op te slaan');
    $validSettingsResponse = httpFixture($httpPort, '/admin/settings', $cookie, [
        '_csrf' => $token[1], 'server' => '127.0.0.1', 'port' => '119', 'retention' => '30',
        'retrieve_after' => '2019-01-02', 'encryption' => 'none', 'explicit_groups' => 'spots:free.pt,comments:free.usenet',
        'discover_max_groups' => '0', 'max_articles_per_group' => '100',
    ]);
    check($validSettingsResponse['status'] === 302 && $state->getUsenetSettings()['retrieve_after'] === '2019-01-02',
        'Admin slaat een geldige retrieve-begindatum op');
    $pipelineForm = [
        '_csrf' => $token[1], 'server' => '127.0.0.1', 'port' => '119', 'retention' => '30',
        'encryption' => 'none', 'explicit_groups' => 'spots:free.pt,comments:free.usenet',
        'discover_max_groups' => '0', 'max_articles_per_group' => '100',
    ];
    check(str_contains($admin['body'], 'name="pipeline_depth"') && str_contains($admin['body'], 'max="128"')
        && $state->getUsenetSettings()['pipeline_depth'] === 32, 'Admin biedt pipeline-diepte 1-128 met standaard 32 voor oudere formulieren');
    foreach (['1', '32', '128'] as $depth) {
        httpFixture($httpPort, '/admin/settings', $cookie, $pipelineForm + ['pipeline_depth' => $depth]);
        check($state->getUsenetSettings()['pipeline_depth'] === (int) $depth
            && str_contains(httpFixture($httpPort, '/admin', $cookie)['body'], 'max="128" value="' . $depth . '"'),
            'Admin slaat pipeline-diepte op en toont actuele waarde ' . $depth);
    }
    foreach (['0', '129', '-1', '1.5', '32oops', ['32'], ''] as $depth) {
        $before = $state->getUsenetSettings();
        $invalidResponse = httpFixture($httpPort, '/admin/settings', $cookie, $pipelineForm + ['pipeline_depth' => $depth]);
        check($invalidResponse['status'] === 302 && $state->getUsenetSettings() === $before
            && str_contains(implode("\n", $invalidResponse['headers']), 'status='),
            'Admin weigert ongeldige pipeline-invoer met expliciete feedback en zonder opslaan');
    }
    $state->saveUsenetSettings($originalHttpUsenetSettings);
    check(httpFixture($httpPort, '/preferences', $cookie)['status'] === 200, 'Bestaande preferences blijven werken');
    $defaults = $state->globalSpotColumns();
    $globalForm = ['_csrf' => $token[1], 'column_0_size' => '1'];
    httpFixture($httpPort, '/admin/columns', $cookie, ['column_0_size' => '1']);
    check($state->globalSpotColumns() === $defaults, 'Globale kolommen vereisen CSRF');
    $globalResult = httpFixture($httpPort, '/admin/columns', $cookie, $globalForm);
    check($globalResult['status'] === 302 && $state->globalSpotColumns()[0] === ['title', 'size'], 'Admin slaat kolommen per hoofdcategorie in database op');
    $minimalList = httpFixture($httpPort, '/spots?category=Beeld', $cookie);
    check(str_contains($minimalList['body'], '<th scope="col" class="column-size">')
        && !str_contains($minimalList['body'], '<th scope="col" class="column-cover">'), 'Overzicht gebruikt globale kolommen zonder persoonlijke override');
    $personalForm = ['_csrf' => $token[1], 'username' => 'http-reader', 'column_mode_0' => 'custom', 'column_0_poster' => '1',
        'column_mode_1' => 'inherit', 'column_mode_2' => 'inherit', 'column_mode_3' => 'inherit'];
    httpFixture($httpPort, '/preferences/columns', $cookie, array_diff_key($personalForm, ['_csrf' => true]));
    check($state->personalSpotColumns('http-tester') === [], 'Persoonlijke kolommen vereisen CSRF');
    httpFixture($httpPort, '/preferences/columns', $cookie, $personalForm);
    check($state->personalSpotColumns('http-tester') === [0 => ['title', 'poster']]
        && $state->personalSpotColumns('http-reader') === [], 'Persoonlijke kolommen zijn uitsluitend gekoppeld aan de sessieusername');
    $state->saveGlobalSpotColumns([0 => ['title', 'genre'], 1 => ['title'], 2 => ['title'], 3 => ['title']]);
    check($state->effectiveSpotColumns('http-tester', 'Beeld') === ['title', 'poster']
        && $state->effectiveSpotColumns('http-reader', 'Beeld') === ['title', 'genre'], 'Adminwijzigingen gelden voor inheriting gebruikers maar overschrijven persoonlijke keuzes niet');
    $customBatch = httpFixture($httpPort, '/spots?batch=1&category=Beeld&subcategory=0%3Aa1%2C0%3Az0', $cookie);
    $batchData = json_decode($customBatch['body'], true, 512, JSON_THROW_ON_ERROR);
    check($customBatch['status'] === 200 && $batchData['count'] === 3 && $batchData['next'] === null
        && str_contains($batchData['html'], 'column-poster') && !str_contains($batchData['html'], 'column-size'), 'Asynchrone batch gebruikt EN-filters en dezelfde persoonlijke kolommen');
    $zeroBatch = json_decode(httpFixture($httpPort, '/spots?batch=1&subcategory=0%3Aa1%2C0%3Aa9', $cookie)['body'], true, 512, JSON_THROW_ON_ERROR);
    check($zeroBatch === ['html' => '', 'count' => 0, 'next' => null], 'Lege asynchrone batch geeft een expliciet einde zonder nepresultaat');
    $invalidForm = $personalForm;
    $invalidForm['column_0_size'] = ['1'];
    httpFixture($httpPort, '/preferences/columns', $cookie, $invalidForm);
    check($state->personalSpotColumns('http-tester') === [0 => ['title', 'poster']], 'Ongeldige kolomarrays wijzigen opgeslagen voorkeuren niet');
    $personalForm['column_mode_0'] = 'inherit';
    httpFixture($httpPort, '/preferences/columns', $cookie, $personalForm);
    check($state->personalSpotColumns('http-tester') === [] && $state->effectiveSpotColumns('http-tester', 'Beeld') === ['title', 'genre'], 'Reset verwijdert de override en gebruikt de actuele adminstandaard');
    $state->saveGlobalSpotColumns($defaults);
    foreach (['/spots?subcategory[]=0:a9', '/spots?q[]=text', '/spots?cursor=invalid', '/spots/preview?key[]=invalid'] as $invalidPath) {
        check(httpFixture($httpPort, $invalidPath, $cookie)['status'] === 400, 'Ongeldige scalaire browse-invoer expliciet geweigerd: ' . $invalidPath);
    }
    $javascript = httpFixture($httpPort, '/assets/spots.js', $cookie);
    check($javascript['status'] === 200 && str_contains($javascript['body'], 'IntersectionObserver'), 'Lazy-load JavaScript wordt via de echte HTTP-server geleverd');
    $previousPreferences = $state->getPreferences('http-tester');
    $state->savePreferences('http-tester', ['theme' => 'default', 'items_per_page' => 10, 'show_erotica' => false]);
    for ($n = 0; $n < 25; $n++) {
        $messageId = 'http-batch-' . $n . '@example.invalid';
        $batchSpot = \NnSpotty\Usenet\SpotnetParser::spot($messageId, Fixtures::headers($messageId));
        $batchSpot['title'] = 'HTTP batch fixture ' . $n;
        $spots->saveSpot($batchSpot, 'free.pt');
    }
    $batchUrl = '/spots?batch=1&category=Beeld&subcategory=0%3Aa1%2C0%3Az0&q=HTTP+batch';
    $seen = [];
    $sizes = [];
    do {
        $batchResponse = httpFixture($httpPort, $batchUrl, $cookie);
        $payload = json_decode($batchResponse['body'], true, 512, JSON_THROW_ON_ERROR);
        check($batchResponse['status'] === 200 && in_array('Cache-Control: private, no-store', $batchResponse['headers'], true), 'Iedere vervolgbatch is authenticated JSON zonder blijvende cache');
        $sizes[] = $payload['count'];
        preg_match_all('/data-spot-id="([a-f0-9]+)"/', $payload['html'], $ids);
        $seen = array_merge($seen, $ids[1]);
        $batchUrl = $payload['next'];
        if ($batchUrl !== null) {
            check(str_contains($batchUrl, 'subcategory=0%3Aa1%2C0%3Az0') && str_contains($batchUrl, 'q=HTTP+batch')
                && str_contains($batchUrl, 'cursor='), 'HTTP vervolg-URL behoudt alle filters en zoektekst plus keyset');
        }
    } while ($batchUrl !== null);
    check($sizes === [10, 10, 5] && count($seen) === 25 && count(array_unique($seen)) === 25, 'HTTP lazy-load contract levert alle resultaten exact eenmaal en stopt bij einde');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'http-batch-%@example.invalid'");
    $state->savePreferences('http-tester', $previousPreferences);

    $adult = \NnSpotty\Usenet\SpotnetParser::spot('http-adult@example.invalid', Fixtures::headers('http-adult@example.invalid',
        str_replace('<Sub>1a01</Sub>', '<Sub>1a09</Sub><Sub>1d23</Sub>', Fixtures::xml('Verborgen HTTP-testspot'))));
    $spots->saveSpot($adult, 'free.pt');
    $spots->saveImage($adult['id'], 'image/png', Fixtures::image());
    $state->addComment($adult['id'], 'http-tester', 'Verborgen HTTP-reactie');
    $adultKey = rawurlencode($adult['id']);
    $assertHidden = static function (string $scenario) use ($httpPort, &$cookie, $adultKey, $adult, $spots, $token): void {
        $list = httpFixture($httpPort, '/spots?spot=' . $adultKey, $cookie);
        check($list['status'] === 200 && !str_contains($list['body'], 'Verborgen HTTP-testspot')
            && !str_contains($list['body'], '>Erotica<') && substr_count($list['body'], 'data-preview=') === 3, $scenario . ': lijst, preview en aantallen afgeschermd');
        check(httpFixture($httpPort, '/spots/preview?key=' . $adultKey, $cookie)['status'] === 404, $scenario . ': asynchrone preview geweigerd');
        $batch = httpFixture($httpPort, '/spots?batch=1&subcategory=0%3Ad75', $cookie);
        check($batch['status'] === 200 && json_decode($batch['body'], true)['count'] === 0
            && in_array('Cache-Control: private, no-store', $batch['headers'], true), $scenario . ': asynchrone batch kan erotica niet ontsluiten');
        check(!str_contains(httpFixture($httpPort, '/spots?category=Erotica&subcategory=0%3Aa9&q=Verborgen', $cookie)['body'], 'Verborgen HTTP-testspot'), $scenario . ': filters en zoeken kunnen niet ontsluiten');
        check(httpFixture($httpPort, '/spots/view?key=' . $adultKey, $cookie)['status'] === 404, $scenario . ': directe detail-link geweigerd');
        check(httpFixture($httpPort, '/spots/image?key=' . $adultKey, $cookie)['status'] === 404, $scenario . ': ook gecachte afbeelding geweigerd');
        $before = count($spots->comments($adult['id']));
        check(httpFixture($httpPort, '/spots/comment', $cookie, ['_csrf' => $token[1], 'spot_id' => $adult['id'], 'comment' => 'Niet opslaan'])['status'] === 404
            && httpFixture($httpPort, '/admin/comments', $cookie, ['_csrf' => $token[1], 'post_id' => $adult['id'], 'comment' => 'Niet opslaan'])['status'] === 404
            && count($spots->comments($adult['id'])) === $before, $scenario . ': beide reactieroutes geweigerd');
    };
    check(!$state->eroticaAllowed() && !$state->getPreferences('http-tester')['show_erotica'], 'Erotica standaard globaal en persoonlijk uit');
    $assertHidden('Globaal uit');
    httpFixture($httpPort, '/admin/content', $cookie, ['allow_erotica' => '1']);
    check(!$state->eroticaAllowed(), 'Globale instelling vereist CSRF');
    httpFixture($httpPort, '/admin/content', $cookie, ['_csrf' => $token[1], 'allow_erotica' => 'bogus']);
    check(!$state->eroticaAllowed(), 'Ongeldige globale zichtbaarheidswaarde wordt geweigerd');
    httpFixture($httpPort, '/admin/content', $cookie, ['_csrf' => $token[1], 'allow_erotica' => '1']);
    check($state->eroticaAllowed(), 'Admin kan erotica globaal toestaan');
    $assertHidden('Globaal aan, gebruiker uit');
    httpFixture($httpPort, '/preferences', $cookie, ['_csrf' => $token[1], 'theme' => 'plex-light', 'items_per_page' => '10', 'show_erotica' => '1']);
    check($state->canViewErotica('http-tester') && !$state->canViewErotica('http-reader'), 'Persoonlijke opt-in opgeslagen op username, niet globaal');
    $visible = httpFixture($httpPort, '/spots?category=Erotica&subcategory=0%3Aa9&q=Verborgen', $cookie);
    check($visible['status'] === 200 && str_contains($visible['body'], 'Verborgen HTTP-testspot')
        && substr_count($visible['body'], 'data-preview=') === 1 && str_contains($visible['body'], 'data-bs-theme="light"'), 'Opt-in toont juiste gefilterde spot en opgeslagen thema');
    check(httpFixture($httpPort, '/spots/view?key=' . $adultKey, $cookie)['status'] === 200
        && httpFixture($httpPort, '/spots/image?key=' . $adultKey, $cookie)['status'] === 200, 'Opt-in ontsluit detail en afbeelding');
    check(in_array('Cache-Control: private, no-store', $visible['headers'], true)
        && in_array('Cache-Control: private, no-store', httpFixture($httpPort, '/spots/image?key=' . $adultKey, $cookie)['headers'], true), 'Zichtbaarheid kan worden ingetrokken zonder private browsercache');
    $before = count($spots->comments($adult['id']));
    httpFixture($httpPort, '/spots/comment', $cookie, ['_csrf' => $token[1], 'spot_id' => $adult['id'], 'comment' => 'Toegestane reactie']);
    check(count($spots->comments($adult['id'])) === $before + 1, 'Opt-in staat reageren op zichtbare erotica toe');
    httpFixture($httpPort, '/admin/content', $cookie, ['_csrf' => $token[1]]);
    check(!$state->canViewErotica('http-tester') && $state->getPreferences('http-tester')['show_erotica'], 'Globale blokkade overrulet opgeslagen persoonlijke opt-in');
    $assertHidden('Globaal uit, gebruiker aan, ook voor admin');
    check(!str_contains(httpFixture($httpPort, '/admin', $cookie)['body'], 'Verborgen HTTP-reactie'), 'Adminoverzicht verbergt afgeschermde reacties');
    $lockedPrefs = httpFixture($httpPort, '/preferences', $cookie);
    check(str_contains($lockedPrefs['body'], 'globaal uitgeschakeld') && str_contains($lockedPrefs['body'], 'checked disabled'), 'Voorkeuren tonen globale blokkade en opgeslagen opt-in');
    httpFixture($httpPort, '/preferences', $cookie, ['_csrf' => $token[1], 'theme' => 'plex-dark', 'items_per_page' => '25']);
    check($state->getPreferences('http-tester')['show_erotica'], 'Ander thema opslaan wist een globaal geblokkeerde opt-in niet');

    (new UserRepository($pdo))->create('http-reader', $password, false);
    $readerCookie = '';
    $readerLogin = httpFixture($httpPort, '/login', $readerCookie);
    preg_match('/name="_csrf" value="([^"]+)"/', $readerLogin['body'], $readerToken);
    httpFixture($httpPort, '/login', $readerCookie, ['_csrf' => $readerToken[1], 'username' => 'http-reader', 'password' => $password]);
    foreach (['sync', 'settings'] as $spotterAction) {
        check(httpFixture($httpPort, '/admin/spotter-lists', $readerCookie,
            ['_csrf' => $readerToken[1], 'action' => $spotterAction, 'enabled' => '1'])['status'] === 403
            && !$httpSpotters->settings()['enabled'], 'Gewone gebruiker kan geen spotterlijsten activeren of synchroniseren');
    }
    $readerPage = httpFixture($httpPort, '/spots', $readerCookie);
    check(count(array_filter($readerPage['headers'], static fn (string $header): bool => str_starts_with($header, 'Server-Timing:'))) === 0,
        'Server-Timing wordt niet aan gewone gebruikers getoond');
    $hiddenFacetResponse = httpFixture($httpPort, '/spots/facets?category=Erotica', $readerCookie);
    check(json_decode($hiddenFacetResponse['body'], true, 512, JSON_THROW_ON_ERROR)['counts'] === []
        && in_array('Cache-Control: private, no-store', $hiddenFacetResponse['headers'], true),
        'Asynchrone tellers lekken geen verborgen erotica en zijn niet cachebaar');
    check(httpFixture($httpPort, '/admin/content', $readerCookie, ['_csrf' => $readerToken[1], 'allow_erotica' => '1'])['status'] === 403
        && !$state->eroticaAllowed(), 'Gewone gebruiker kan globale instelling niet wijzigen');
    check(httpFixture($httpPort, '/admin/columns', $readerCookie, ['_csrf' => $readerToken[1]])['status'] === 403
        && $state->globalSpotColumns() === $defaults, 'Gewone gebruiker kan globale kolominstellingen niet wijzigen');
    check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action IN ('admin.columns.updated', 'user.columns.updated')")->fetchColumn() === 3, 'Kolomwijzigingen en reset worden geaudit');
    $contentDefaults = $state->spotContentSettings();
    check(httpFixture($httpPort, '/admin/spot-content', $readerCookie, ['_csrf' => $readerToken[1]])['status'] === 403
        && $state->spotContentSettings() === $contentDefaults, 'Alleen admin mag ophalen en weergave van inhoud wijzigen');
    httpFixture($httpPort, '/admin/spot-content', $cookie, []);
    check($state->spotContentSettings() === $contentDefaults, 'Inhoudsinstellingen wijzigen vereist CSRF');
    foreach ([['images_enabled' => 'invalid'], ['retrieve_comments' => ['1']], ['show_comments' => ['1']]] as $invalidContent) {
        $result = httpFixture($httpPort, '/admin/spot-content', $cookie, ['_csrf' => $token[1]] + $invalidContent);
        check($result['status'] === 302 && $state->spotContentSettings() === $contentDefaults
            && str_contains(implode("\n", $result['headers']), 'Ongeldige'), 'Ongeldige inhoudsinstelling wordt gemeld zonder de eerdere keuzes te wijzigen');
    }
    $savedComments = count($spots->comments($spotId));
    $savedImage = $spots->image($spotId);
    $disabled = httpFixture($httpPort, '/admin/spot-content', $cookie, ['_csrf' => $token[1]]);
    check($disabled['status'] === 302 && $state->spotContentSettings() === ['retrieve_comments' => false, 'show_comments' => false, 'images_enabled' => false], 'Admin kan ophalen en weergave uitschakelen en in database opslaan');
    $hiddenDetail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    $hiddenPreview = json_decode(httpFixture($httpPort, '/spots/preview?key=' . $spotId, $cookie)['body'], true, 512, JSON_THROW_ON_ERROR)['html'];
    check($hiddenDetail['status'] === 200 && str_contains($hiddenDetail['body'], 'Reacties zijn door de beheerder uitgeschakeld.')
        && !str_contains($hiddenDetail['body'], 'action="/spots/comment"') && !str_contains($hiddenDetail['body'], 'Via de echte route')
        && !str_contains($hiddenDetail['body'], '/spots/image?')
        && !str_contains($hiddenPreview, '/spots/image?') && !str_contains($hiddenPreview, 'Volledige spot en reacties'), 'Detail en preview laden geen verborgen afbeeldingen of reacties en tonen correcte uitleg');
    $disabledImage = httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie);
    check($disabledImage['status'] === 403 && in_array('Cache-Control: private, no-store', $disabledImage['headers'], true)
        && $spots->image($spotId) === $savedImage, 'Ook gecachte afbeeldingen zijn geblokkeerd zonder de cache te wissen');
    try {
        (new \NnSpotty\Usenet\SpotImageService($spots, $state))->load($spots->getSpot($spotId));
        check(false, 'Directe afbeeldingservice moet uitschakeling respecteren');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'uitgeschakeld'), 'Ook directe afbeeldingservice omzeilt de instelling niet');
    }
    foreach (['/spots?category=Beeld', '/spots?batch=1&category=Beeld'] as $path) {
        $result = httpFixture($httpPort, $path, $cookie);
        $body = str_contains($path, 'batch=1') ? json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR)['html'] : $result['body'];
        check(!str_contains($body, 'column-cover') && !str_contains($body, '/spots/image?'), 'Verborgen afbeeldingen ontbreken ook in lijst en vervolgbatch');
    }
    foreach (['/spots/comment', '/admin/comments'] as $path) {
        $result = httpFixture($httpPort, $path, $cookie, ['_csrf' => $token[1], 'spot_id' => $spotId, 'post_id' => $spotId, 'comment' => 'Verboden lokale reactie']);
        check($result['status'] === 403 && count($spots->comments($spotId)) === $savedComments, 'Beide lokale reactieroutes respecteren de globale uitschakeling');
    }
    $hiddenAdmin = httpFixture($httpPort, '/admin', $cookie);
    check(!str_contains($hiddenAdmin['body'], 'action="/admin/comments"') && !str_contains($hiddenAdmin['body'], 'Via de echte route'), 'Admin toont geen uitgeschakelde reacties of actief reactieformulier');
    httpFixture($httpPort, '/admin/spot-content', $cookie, ['_csrf' => $token[1], 'show_comments' => '1', 'images_enabled' => '1']);
    $pausedDetail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check(str_contains($pausedDetail['body'], 'Usenet-reacties ophalen is uitgeschakeld')
        && str_contains($pausedDetail['body'], 'Via de echte route')
        && str_contains($pausedDetail['body'], 'data-image-lightbox'), 'Reacties tonen staat los van ophalen; eerder opgeslagen inhoud blijft bruikbaar');
    httpFixture($httpPort, '/admin/spot-content', $cookie, ['_csrf' => $token[1], 'retrieve_comments' => '1', 'show_comments' => '1', 'images_enabled' => '1']);
    check($state->spotContentSettings() === $contentDefaults
        && httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie)['body'] === $savedImage['image_data'], 'Opnieuw inschakelen herstelt toegang tot de bestaande afbeeldingcache');
    $presentationScript = httpFixture($httpPort, '/assets/presentation.js', $cookie);
    check($presentationScript['status'] === 200 && str_contains($presentationScript['body'], 'showModal'), 'Gedeelde lightbox-JavaScript wordt door de HTTP-server geleverd');
    check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin.spot_content.updated'")->fetchColumn() === 3, 'Inhoudsinstellingen hebben auditregistratie, ongeldige wijzigingen niet');
    httpFixture($httpPort, '/admin/content', $cookie, ['_csrf' => $token[1], 'allow_erotica' => '1']);
    check(!str_contains(httpFixture($httpPort, '/spots', $readerCookie)['body'], 'Verborgen HTTP-testspot'), 'Opt-in van admin lekt niet naar tweede gebruiker');
    httpFixture($httpPort, '/preferences', $readerCookie, ['_csrf' => $readerToken[1], 'theme' => 'default', 'items_per_page' => '10', 'show_erotica' => '1', 'username' => 'http-tester']);
    check($state->canViewErotica('http-reader') && $state->getPreferences('http-tester')['theme'] === 'plex-dark', 'Preference-account kan niet via formulierusername worden vervalst');
    httpFixture($httpPort, '/preferences', $readerCookie, ['_csrf' => $readerToken[1], 'theme' => 'default', 'items_per_page' => '10']);
    check(!$state->canViewErotica('http-reader') && httpFixture($httpPort, '/spots/image?key=' . $adultKey, $readerCookie)['status'] === 404, 'Persoonlijke opt-out trekt afbeeldingsrechten direct in');
    check(httpFixture($httpPort, '/spots?subcategory=invalid', $cookie)['status'] === 400
        && httpFixture($httpPort, '/spots?q=' . str_repeat('a', 201), $cookie)['status'] === 400, 'Ongeldige facet- en zoekinvoer wordt expliciet geweigerd');
    check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin.content.updated'")->fetchColumn() >= 3, 'Globale zichtbaarheidswijzigingen hebben auditregistratie');
    require __DIR__ . '/favorites-http.php';
    $pdo->prepare('DELETE FROM post_comments WHERE post_id = ?')->execute([$adult['id']]);
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$adult['id']]);
    $state->setEroticaAllowed(false);
    require __DIR__ . '/newznab-http.php';
    require __DIR__ . '/sab-http.php';
    require __DIR__ . '/tvdb-http.php';
    require __DIR__ . '/music-http.php';
} catch (Throwable $exception) {
    rewind($httpLog);
    fwrite(STDERR, stream_get_contents($httpLog));
    throw $exception;
} finally {
    proc_terminate($httpServer);
    foreach ($httpPipes as $pipe) {
        fclose($pipe);
    }
    proc_close($httpServer);
    fclose($httpLog);
}
