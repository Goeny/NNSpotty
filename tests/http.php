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
    $stylesheet = httpFixture($httpPort, '/assets/spotty.css', $cookie);
    check($stylesheet['status'] === 200 && str_contains($stylesheet['body'], '.browse-grid')
        && count(array_filter($stylesheet['headers'], static fn (string $header): bool => stripos($header, 'Content-Type: text/css') === 0)) === 1, 'Lokale stylesheet wordt met CSS MIME-type geserveerd');
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

    $adult = \NnSpotty\Usenet\SpotnetParser::spot('http-adult@example.invalid', Fixtures::headers('http-adult@example.invalid',
        str_replace('<Sub>1a01</Sub>', '<Sub>1a09</Sub><Sub>1d23</Sub>', Fixtures::xml('Verborgen HTTP-testspot'))));
    $spots->saveSpot($adult, 'free.pt');
    $spots->saveImage($adult['id'], 'image/png', Fixtures::image());
    $state->addComment($adult['id'], 'http-tester', 'Verborgen HTTP-reactie');
    $adultKey = rawurlencode($adult['id']);
    $assertHidden = static function (string $scenario) use ($httpPort, &$cookie, $adultKey, $adult, $spots, $token): void {
        $list = httpFixture($httpPort, '/spots?spot=' . $adultKey, $cookie);
        check($list['status'] === 200 && !str_contains($list['body'], 'Verborgen HTTP-testspot')
            && !str_contains($list['body'], '>Erotica<') && substr_count($list['body'], '>Details</a>') === 3, $scenario . ': lijst, preview en aantallen afgeschermd');
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
        && substr_count($visible['body'], '>Details</a>') === 1 && str_contains($visible['body'], 'data-bs-theme="light"'), 'Opt-in toont juiste gefilterde spot en opgeslagen thema');
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
    check(httpFixture($httpPort, '/admin/content', $readerCookie, ['_csrf' => $readerToken[1], 'allow_erotica' => '1'])['status'] === 403
        && !$state->eroticaAllowed(), 'Gewone gebruiker kan globale instelling niet wijzigen');
    httpFixture($httpPort, '/admin/content', $cookie, ['_csrf' => $token[1], 'allow_erotica' => '1']);
    check(!str_contains(httpFixture($httpPort, '/spots', $readerCookie)['body'], 'Verborgen HTTP-testspot'), 'Opt-in van admin lekt niet naar tweede gebruiker');
    httpFixture($httpPort, '/preferences', $readerCookie, ['_csrf' => $readerToken[1], 'theme' => 'default', 'items_per_page' => '10', 'show_erotica' => '1', 'username' => 'http-tester']);
    check($state->canViewErotica('http-reader') && $state->getPreferences('http-tester')['theme'] === 'plex-dark', 'Preference-account kan niet via formulierusername worden vervalst');
    httpFixture($httpPort, '/preferences', $readerCookie, ['_csrf' => $readerToken[1], 'theme' => 'default', 'items_per_page' => '10']);
    check(!$state->canViewErotica('http-reader') && httpFixture($httpPort, '/spots/image?key=' . $adultKey, $readerCookie)['status'] === 404, 'Persoonlijke opt-out trekt afbeeldingsrechten direct in');
    check(httpFixture($httpPort, '/spots?subcategory=invalid', $cookie)['status'] === 400
        && httpFixture($httpPort, '/spots?q=' . str_repeat('a', 201), $cookie)['status'] === 400, 'Ongeldige facet- en zoekinvoer wordt expliciet geweigerd');
    check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'admin.content.updated'")->fetchColumn() >= 3, 'Globale zichtbaarheidswijzigingen hebben auditregistratie');
    $pdo->prepare('DELETE FROM post_comments WHERE post_id = ?')->execute([$adult['id']]);
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$adult['id']]);
    $state->setEroticaAllowed(false);
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
