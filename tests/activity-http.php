<?php

declare(strict_types=1);

$activitySocket = stream_socket_server('tcp://127.0.0.1:0', $activityErrno, $activityError);
if ($activitySocket === false) throw new RuntimeException($activityError, $activityErrno);
$activityPort = (int) substr(strrchr(stream_socket_get_name($activitySocket, false), ':'), 1);
fclose($activitySocket);
$activityDbConfig = [];
foreach (explode(';', substr($dsn, 6)) as $parameter) {
    $pair = explode('=', $parameter, 2);
    if (count($pair) === 2) $activityDbConfig[$pair[0]] = $pair[1];
}
$activityEnvironment = array_merge(getenv(), [
    'APP_URL' => 'http://127.0.0.1:' . $activityPort,
    'DB_HOST' => $activityDbConfig['host'] ?? '127.0.0.1',
    'DB_PORT' => $activityDbConfig['port'] ?? '3306',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => getenv('NNSPOTTY_TEST_USER') ?: 'root',
    'DB_PASSWORD' => getenv('NNSPOTTY_TEST_PASSWORD') ?: '',
    'DB_CHARSET' => 'utf8mb4',
]);
if (PHP_OS_FAMILY === 'Windows' && $activityEnvironment['DB_PASSWORD'] === '') {
    throw new RuntimeException('Gebruik op Windows een testdatabaseaccount met niet-leeg wachtwoord.');
}
$activityHttpLog = tmpfile();
if ($activityHttpLog === false) throw new RuntimeException('Kon HTTP-testlog niet openen.');
$activityHttpPipes = [];
$activityServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $activityPort, '-t', __DIR__ . '/../public'],
    [['pipe', 'r'], $activityHttpLog, $activityHttpLog], $activityHttpPipes, dirname(__DIR__), $activityEnvironment);
if (!is_resource($activityServer)) throw new RuntimeException('Kon activiteitentestserver niet starten.');

function activityHttp(int $port, string $path, string &$cookie, ?array $form = null, string $method = 'GET'): array
{
    $context = stream_context_create(['http' => [
        'method' => $form !== null ? 'POST' : $method,
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $form === null ? '' : http_build_query($form),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookie = $match[1];
    }
    return ['status' => (int) explode(' ', $http_response_header[0])[1], 'body' => $body, 'headers' => $http_response_header];
}

$activityHttpUsers = new \NnSpotty\Users\UserRepository($pdo);
$activityHttpRepo = new \NnSpotty\Activity\Repository($pdo);
$activityHttpSpots = new \NnSpotty\Usenet\SpotRepository($pdo);
$activityHttpSpot = \NnSpotty\Usenet\SpotnetParser::spot('activity-http@example.invalid',
    Fixtures::headers('activity-http@example.invalid', Fixtures::xml('Activity HTTP fixture')));
$activityHttpSpots->saveSpot($activityHttpSpot, 'free.pt');
$pdo->prepare('INSERT INTO spotnet_nzbs (spot_id, nzb_data) VALUES (?, ?)')->execute([$activityHttpSpot['id'], Fixtures::nzb()]);
$activityHttpUsers->create('activity-http-admin', 'synthetic-activity-password', true);
$activityHttpUsers->create('activity-http-reader', 'synthetic-activity-password', false);
$activityOriginalApi = $state->apiEnabled();
$activityCookies = ['admin' => '', 'reader' => '', 'anonymous' => ''];
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $activityPort, $errno, $error, .1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    check($ready, 'Activiteitentestserver is bereikbaar');
    foreach (['admin', 'reader'] as $role) {
        $login = activityHttp($activityPort, '/login', $activityCookies[$role]);
        preg_match('/name="_csrf" value="([^"]+)"/', $login['body'], $activityToken);
        $result = activityHttp($activityPort, '/login', $activityCookies[$role],
            ['_csrf' => $activityToken[1], 'username' => 'activity-http-' . $role, 'password' => 'synthetic-activity-password']);
        check($result['status'] === 302, 'HTTP-activiteitentest login ' . $role);
    }
    foreach (['/history', '/statistics'] as $path) {
        check(activityHttp($activityPort, $path, $activityCookies['anonymous'])['status'] === 302, $path . ' vereist login');
    }
    $history = activityHttp($activityPort, '/history', $activityCookies['reader']);
    check($history['status'] === 200 && str_contains($history['body'], 'Mijn historie')
        && !str_contains($history['body'], 'href="/statistics"')
        && in_array('Cache-Control: private, no-store', $history['headers'], true),
        'Lezer heeft private eigen historie zonder statistiekenlink');
    check(activityHttp($activityPort, '/statistics', $activityCookies['reader'])['status'] === 403
        && activityHttp($activityPort, '/statistics', $activityCookies['reader'], method: 'HEAD')['status'] === 403,
        'Directe statistieken-GET en HEAD vereisen recht');
    $stats = activityHttp($activityPort, '/statistics', $activityCookies['admin']);
    check($stats['status'] === 200 && str_contains($stats['body'], 'Meest bezochte categorieen')
        && str_contains($stats['body'], 'href="/statistics"') && in_array('Cache-Control: private, no-store', $stats['headers'], true),
        'Admin krijgt grafieken en statistiekenlink');
    check(str_contains(activityHttp($activityPort, '/manage', $activityCookies['admin'])['body'], 'Statistieken bekijken'),
        'Statistiekenrecht is beschikbaar in de bestaande groepsrechtenmatrix');
    foreach (['/history?tab[]=view', '/history?tab=unknown', '/history?page=0', '/history?page[]=1',
        '/statistics?days=999', '/statistics?days[]=30'] as $path) {
        check(activityHttp($activityPort, $path, $activityCookies['admin'])['status'] === 400, 'Ongeldige activiteitspagina-invoer afgewezen: ' . $path);
    }
    $id = $activityHttpSpot['id'];
    activityHttp($activityPort, '/spots/preview?key=' . $id, $activityCookies['reader']);
    activityHttp($activityPort, '/spots/view?key=' . $id, $activityCookies['reader'], method: 'HEAD');
    activityHttp($activityPort, '/spots/view?key=' . str_repeat('0', 64), $activityCookies['reader']);
    check($activityHttpRepo->history('activity-http-reader', 'view', true, 1)['items'] === [],
        'Preview, HEAD en ontbrekende spot registreren geen bekijkactie');
    activityHttp($activityPort, '/spots/view?key=' . $id, $activityCookies['reader']);
    activityHttp($activityPort, '/spots/view?key=' . $id, $activityCookies['reader']);
    check((int) $activityHttpRepo->history('activity-http-reader', 'view', true, 1)['items'][0]['action_count'] === 2,
        'Volledige detailpagina registreert iedere geslaagde GET');
    $own = activityHttp($activityPort, '/history?username=activity-http-reader', $activityCookies['admin']);
    check(!str_contains($own['body'], 'Activity HTTP fixture'), 'Historie-URL kan geen andere gebruiker selecteren, ook niet als admin');
    $nzb = activityHttp($activityPort, '/spots/nzb?key=' . $id, $activityCookies['reader']);
    activityHttp($activityPort, '/spots/nzb?key=' . $id, $activityCookies['reader'], method: 'HEAD');
    $state->setApiEnabled(true);
    $key = (new \NnSpotty\Newznab\ApiKeys($pdo))->rotate('activity-http-reader');
    $apiPath = '/api?' . http_build_query(['t' => 'get', 'id' => $id, 'apikey' => $key]);
    $apiNzb = activityHttp($activityPort, $apiPath, $activityCookies['anonymous']);
    activityHttp($activityPort, $apiPath, $activityCookies['anonymous'], method: 'HEAD');
    activityHttp($activityPort, '/api?t=search&apikey=' . $key, $activityCookies['anonymous']);
    activityHttp($activityPort, '/api?t=get&id=' . $id . '&apikey=wrong', $activityCookies['anonymous']);
    $downloads = $activityHttpRepo->history('activity-http-reader', 'download', true, 1)['items'];
    check($nzb['status'] === 200 && $apiNzb['body'] === Fixtures::nzb() && count($downloads) === 1
        && (int) $downloads[0]['action_count'] === 2 && $downloads[0]['sources'] === 'newznab,nzb',
        'Echte browser-/API-download telt op keyeigenaar; HEAD, zoeken en foute key tellen niet');
    check(str_contains(activityHttp($activityPort, '/history?tab=download', $activityCookies['reader'])['body'], 'Newznab API, NZB-bestand'),
        'Gedownloadtab toont echte browser-/API-bronnen');
    require __DIR__ . '/activity-sab-http.php';
    $activityHttpUsers->saveGroup(null, 'Activiteit HTTP statistieken', ['statistics.view']);
    $group = (int) $pdo->query("SELECT id FROM user_groups WHERE name = 'Activiteit HTTP statistieken'")->fetchColumn();
    $activityHttpUsers->manage('activity-http-reader', true, [$group]);
    check(activityHttp($activityPort, '/statistics?days=7', $activityCookies['reader'])['status'] === 200,
        'Toekennen van groepsrecht maakt statistieken direct beschikbaar in bestaande sessie');
    check(activityHttp($activityPort, '/spots/nzb?key=' . $id, $activityCookies['reader'])['status'] === 403
        && (int) $activityHttpRepo->history('activity-http-reader', 'download', true, 1)['items'][0]['action_count'] === 2,
        'Geweigerde download wordt niet geregistreerd');
    $usersGroup = (int) $pdo->query("SELECT id FROM user_groups WHERE system_key = 'users'")->fetchColumn();
    $activityHttpUsers->manage('activity-http-reader', true, [$usersGroup]);
    check(activityHttp($activityPort, '/statistics', $activityCookies['reader'])['status'] === 403
        && !str_contains(activityHttp($activityPort, '/history', $activityCookies['reader'])['body'], 'href="/statistics"'),
        'Intrekken van groepsrecht blokkeert pagina en link direct');
    $activityHttpUsers->deleteGroup($group);
} finally {
    proc_terminate($activityServer);
    foreach ($activityHttpPipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($activityServer);
    fclose($activityHttpLog);
    $state->setApiEnabled($activityOriginalApi);
    $pdo->exec("DELETE FROM app_users WHERE username IN ('activity-http-admin', 'activity-http-reader')");
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$activityHttpSpot['id']]);
    $activityHttpSpots->refreshBrowseCounts();
}
