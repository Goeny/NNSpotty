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
    'DB_HOST' => $dbConfig['host'] ?? '127.0.0.1',
    'DB_PORT' => $dbConfig['port'] ?? '3306',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => getenv('NNSPOTTY_TEST_USER') ?: 'root',
    'DB_PASSWORD' => getenv('NNSPOTTY_TEST_PASSWORD') ?: '',
    'DB_CHARSET' => 'utf8mb4',
]);
if (PHP_OS_FAMILY === 'Windows' && $environment['DB_PASSWORD'] === '') {
    throw new RuntimeException('HTTP-integratietests op Windows vereisen een testdatabasegebruiker met een niet-leeg wachtwoord; Windows kan lege child-process environmentvariabelen verwijderen.');
}
$password = bin2hex(random_bytes(20));
(new UserRepository($pdo))->create('http-tester', $password, true);
$httpPipes = [];
$httpServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', __DIR__ . '/../public'],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
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
    $anonymous = httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie);
    check($anonymous['status'] === 401, 'Afbeelding-endpoint vereist login');
    $login = httpFixture($httpPort, '/login', $cookie);
    preg_match('/name="_csrf" value="([^"]+)"/', $login['body'], $token);
    check($login['status'] === 200 && isset($token[1]), 'Loginpagina levert een CSRF token');
    $loginResult = httpFixture($httpPort, '/login', $cookie, ['_csrf' => $token[1], 'username' => 'http-tester', 'password' => $password]);
    check($loginResult['status'] === 302, 'Sessie-login via echte HTTP-route');
    $list = httpFixture($httpPort, '/spots', $cookie);
    check($list['status'] === 200 && substr_count($list['body'], '>Details</a>') === 3, 'HTTP-overzicht bevat de drie individuele spots');
    $detail = httpFixture($httpPort, '/spots/view?key=' . $spotId, $cookie);
    check($detail['status'] === 200 && str_contains($detail['body'], 'Reacties (3)') && str_contains($detail['body'], 'Een omschrijving'), 'HTTP-detail toont omschrijving en gekoppelde reacties');
    $imageResponse = httpFixture($httpPort, '/spots/image?key=' . $spotId, $cookie);
    check($imageResponse['status'] === 200 && $imageResponse['body'] === Fixtures::image()
        && in_array('Content-Type: image/png', $imageResponse['headers'], true), 'HTTP-afbeelding levert de juiste bytes en MIME');
    check($spots->image($spotId) !== null, 'HTTP-afbeelding wordt bij een cache-miss via NNTP geladen en opgeslagen');
    $before = count($spots->comments($spotId));
    httpFixture($httpPort, '/spots/comment', $cookie, ['spot_id' => $spotId, 'comment' => 'Niet opslaan']);
    check(count($spots->comments($spotId)) === $before, 'Reactie zonder CSRF wordt niet opgeslagen');
    preg_match('/name="_csrf" value="([^"]+)"/', $detail['body'], $token);
    $response = httpFixture($httpPort, '/spots/comment', $cookie, ['_csrf' => $token[1], 'spot_id' => $spotId, 'comment' => 'Via de echte route']);
    check($response['status'] === 302 && count($spots->comments($spotId)) === $before + 1, 'Lokale reactie plaatsen via echte HTTP-route');
    $admin = httpFixture($httpPort, '/admin', $cookie);
    check($admin['status'] === 200 && str_contains($admin['body'], 'comments:free.usenet') && !str_contains($admin['body'], 'alt.binaries.movies'), 'Admin toont de juiste Spotnet-configuratie');
    httpFixture($httpPort, '/admin/settings', $cookie, [
        '_csrf' => $token[1], 'server' => '127.0.0.1', 'port' => '119', 'retention' => '30',
        'encryption' => 'none', 'explicit_groups' => 'movies:alt.binaries.movies',
        'discover_max_groups' => '0', 'max_articles_per_group' => '100',
    ]);
    check($state->getUsenetSettings()['explicit_groups'] === 'spots:free.pt,comments:free.usenet', 'Admin weigert algemene headergroepen');
    check(httpFixture($httpPort, '/preferences', $cookie)['status'] === 200, 'Bestaande preferences blijven werken');
} finally {
    proc_terminate($httpServer);
    foreach ($httpPipes as $pipe) {
        fclose($pipe);
    }
    proc_close($httpServer);
}
