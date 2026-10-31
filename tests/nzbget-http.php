<?php

declare(strict_types=1);

use NnSpotty\Downloader\NzbgetClient;
use NnSpotty\Downloader\NzbgetRejected;
use NnSpotty\Downloader\NzbgetRepository;
use NnSpotty\Downloader\SabRepository;
use NnSpotty\Downloader\Targets;
use NnSpotty\Usenet\SpotnetParser;

$nzbgetSocket = stream_socket_server('tcp://127.0.0.1:0', $nzbgetErrno, $nzbgetError);
if ($nzbgetSocket === false) {
    throw new RuntimeException($nzbgetError, $nzbgetErrno);
}
$nzbgetPort = (int) substr(strrchr(stream_socket_get_name($nzbgetSocket, false), ':'), 1);
fclose($nzbgetSocket);
$nzbgetLogFile = tempnam(sys_get_temp_dir(), 'nnspotty-nzbget-');
$nzbgetServerLog = tmpfile();
$nzbgetPipes = [];
$nzbgetServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $nzbgetPort, __DIR__ . '/nzbget-server.php'],
    [['pipe', 'r'], $nzbgetServerLog, $nzbgetServerLog], $nzbgetPipes, dirname(__DIR__),
    array_merge(getenv(), ['NNSPOTTY_NZBGET_LOG' => $nzbgetLogFile]));
if (!is_resource($nzbgetServer)) {
    throw new RuntimeException('Kon NZBGet-fixture niet starten.');
}
$nzbgetRepo = new NzbgetRepository($pdo);
$nzbgetBase = 'http://127.0.0.1:' . $nzbgetPort . '/nzbget';
$nzbgetLogin = ['control_username' => 'fixture-user', 'control_password' => 'fixture-pass'];
$nzbgetEntries = static fn (): array => array_map(static fn ($line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR),
    file($nzbgetLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$nzbgetAppends = static fn (): array => array_values(array_filter($nzbgetEntries(), static fn (array $row): bool => $row['method'] === 'append'));
$nzbgetPost = static function (array $form, bool $reader = false) use ($httpPort, &$cookie, &$readerCookie, $token, $readerToken): array {
    $form += ['connection' => 'personal', 'client' => 'nzbget'];
    return $reader ? httpFixture($httpPort, '/downloader', $readerCookie, $form + ['_csrf' => $readerToken[1]])
        : httpFixture($httpPort, '/downloader', $cookie, $form + ['_csrf' => $token[1]]);
};
$nzbgetJson = static fn (string $query): array => json_decode(httpFixture($httpPort, '/downloader?client=nzbget&' . $query, $cookie)['body'], true);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $nzbgetPort, $probeErrno, $probeError, .1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'NZBGet-fixture is bereikbaar');
    check(httpFixture($httpPort, '/downloader?client=unknown', $cookie)['status'] === 400, 'Onbekende downloadclient faalt expliciet');
    check(httpFixture($httpPort, '/downloader?client=nzbget&connection=central', $readerCookie)['status'] === 403
        && $nzbgetPost(['action' => 'save', 'connection' => 'central', 'url' => $nzbgetBase] + $nzbgetLogin, true)['status'] === 403,
        'Gewone gebruiker kan centrale NZBGet niet zien of wijzigen');
    $blocked = $nzbgetPost(['action' => 'save', 'url' => $nzbgetBase, 'enabled' => '1'] + $nzbgetLogin, true);
    check($blocked['status'] === 302 && !$nzbgetRepo->settings('user:http-reader')['configured'] && $nzbgetEntries() === [],
        'Persoonlijke interne NZBGet-host wordt geweigerd zonder netwerkcontact of opslag');
    $nzbgetPost(['action' => 'save', 'url' => $nzbgetBase . '/jsonrpc', 'enabled' => '1'] + $nzbgetLogin);
    check(!$nzbgetRepo->settings('user:http-tester')['configured'], 'NZBGet-basis-URL mag niet al op /jsonrpc eindigen');
    $nzbgetPost(['action' => 'save', 'url' => $nzbgetBase, 'enabled' => '1', 'control_username' => 'bad:user', 'control_password' => 'x']);
    check(!$nzbgetRepo->settings('user:http-tester')['configured'], 'NZBGet-gebruikersnaam met dubbele punt wordt geweigerd');
    $saved = $nzbgetPost(['action' => 'save', 'connection' => 'central', 'url' => $nzbgetBase, 'enabled' => '1'] + $nzbgetLogin);
    check($saved['status'] === 302 && $nzbgetRepo->settings('central')['enabled'], 'Admin kan centrale interne NZBGet-host configureren');
    $nzbgetPost(['action' => 'save', 'connection' => 'central', 'url' => $nzbgetBase, 'enabled' => '1', 'control_username' => 'fixture-user', 'control_password' => '']);
    $preferencesHtml = httpFixture($httpPort, '/preferences', $cookie)['body'];
    $adminHtml = httpFixture($httpPort, '/admin', $cookie)['body'];
    check($nzbgetRepo->settings('central')['control_password'] === 'fixture-pass' && !str_contains($preferencesHtml, 'fixture-pass')
        && !str_contains($adminHtml, 'fixture-pass') && str_contains($adminHtml, 'id="nzbget-url-central"') && str_contains($preferencesHtml, 'id="nzbget-url-personal"'),
        'Leeg wachtwoordveld behoudt NZBGet-geheim; voorkeuren en admin renderen het nooit');
    $test = $nzbgetPost(['action' => 'test', 'connection' => 'central']);
    $testData = json_decode($test['body'], true);
    check($test['status'] === 200 && $testData['categories'] === ['movies', 'tv'] && str_contains($testData['message'], '24.3')
        && !str_contains($test['body'], 'secret-config-value') && !str_contains($test['body'], 'server-local-path'),
        'NZBGet-test toont versie en uitsluitend categorienamen, geen configuratiegeheimen');
    check(array_filter($nzbgetEntries(), static fn (array $row): bool => !$row['auth'] || $row['http'] !== 'POST' || $row['query'] !== '') === [],
        'NZBGet JSON-RPC gebruikt POST met Basic-auth, zonder geheimen in de URL');
    check($nzbgetPost(['action' => 'mapping', 'category_1' => 'tv'], true)['status'] === 403, 'NZBGet-categoriebeheer is alleen voor admins');
    $nzbgetPost(['action' => 'mapping', 'connection' => 'central', 'category_0' => 'movies', 'category_1' => 'tv']);
    check($nzbgetRepo->settings('central')['mappings']['Series'] === 'tv' && (new SabRepository($pdo))->settings('central')['mappings'] === [],
        'NZBGet-mapping staat los van de SABnzbd-mapping');
    $nzbgetSpot = SpotnetParser::spot('nzbget-fixture@example.invalid', Fixtures::headers('nzbget-fixture@example.invalid',
        str_replace('<Sub>1a01</Sub>', '<Sub>1a09</Sub><Sub>1z01</Sub>', Fixtures::xml('NZBGet Fixture S01E01'))));
    $spots->saveSpot($nzbgetSpot, 'free.pt');
    $pdo->prepare('INSERT INTO spotnet_nzbs (spot_id, nzb_data) VALUES (?, ?)')->execute([$nzbgetSpot['id'], Fixtures::nzb()]);
    $prepared = $nzbgetJson('action=prepare&connection=central&id=' . $nzbgetSpot['id']);
    check($prepared['category'] === 'tv' && $prepared['client'] === 'NZBGet' && strlen($prepared['token']) === 32,
        'NZBGet-voorbereiding toont client en effectieve categorie');
    $sabPrepared = json_decode(httpFixture($httpPort, '/downloader?action=prepare&connection=central&id=' . $nzbgetSpot['id'], $cookie)['body'], true);
    check(isset($sabPrepared['error']), 'Uitgeschakelde SABnzbd blijft gescheiden van ingeschakelde NZBGet');
    $sendForm = ['action' => 'send', 'connection' => 'central', 'id' => $nzbgetSpot['id'], 'token' => $prepared['token'], 'revision' => $prepared['revision']];
    $sabRevision = $sendForm;
    $sabRevision['client'] = 'sabnzbd';
    check($nzbgetPost($sabRevision)['status'] === 400 && $nzbgetAppends() === [], 'NZBGet-bevestiging is niet bruikbaar voor een andere client');
    $sent = $nzbgetPost($sendForm);
    $appends = $nzbgetAppends();
    check($sent['status'] === 201 && count($appends) === 1 && $appends[0]['valid_nzb'] && $appends[0]['category'] === 'tv'
        && $appends[0]['name'] === 'NZBGet Fixture S01E01.nzb' && $appends[0]['params'] === 10,
        'NZBGet append ontvangt base64-NZB, categorie en releasenaam (v16+-signatuur)');
    check($nzbgetPost($sendForm)['status'] === 409 && count($nzbgetAppends()) === 1, 'Replay van NZBGet-bevestiging veroorzaakt geen tweede upload');
    $status = httpFixture($httpPort, '/downloader?client=nzbget&connection=central', $cookie);
    $queue = json_decode($status['body'], true);
    check($status['status'] === 200 && $queue['status'] === 'Downloading' && (float) $queue['speed'] === 2097152.0 && $queue['total'] === 2
        && (float) $queue['slots'][0]['percentage'] === 40.0 && $queue['slots'][0]['eta'] === '0:00:30' && $queue['slots'][1]['eta'] === '0:00:55'
        && $queue['slots'][1]['status'] === 'Queued' && $queue['client'] === 'NZBGet',
        'NZBGet-wachtrij wordt genormaliseerd naar snelheid, voortgang en cumulatieve ETA');
    check($queue['history'][0]['client'] === 'nzbget' && $queue['history'][0]['nzo_id'] === '4242' && $queue['history'][0]['status'] === 'sent',
        'NZBGet-verzendhistorie bewaart client en NZBGet-ID');
    check(!str_contains($status['body'], 'secret-not-to-forward') && !str_contains($status['body'], 'server-local-path')
        && in_array('Cache-Control: private, no-store', $status['headers'], true), 'NZBGet-status filtert geheime velden en is niet cachebaar');
    $sabHistory = json_decode(httpFixture($httpPort, '/downloader?action=history&connection=central', $cookie)['body'], true)['history'];
    check(array_filter($sabHistory, static fn (array $row): bool => $row['title'] === 'NZBGet Fixture S01E01') === [],
        'NZBGet-historie lekt niet naar de SABnzbd-historie');
    check((int) $pdo->query("SELECT COUNT(*) FROM activity_history WHERE source = 'nzbget'")->fetchColumn() === 1,
        'Geslaagde NZBGet-verzending telt als download in History/statistieken');
    $nzbgetRepo->save('central', null, 'http://127.0.0.1:' . $nzbgetPort . '/invalid', 'fixture-user', 'fixture-pass', true, true);
    check(httpFixture($httpPort, '/downloader?client=nzbget&connection=central', $cookie)['status'] === 502, 'Onvolledige NZBGet-wachtrij wordt geen leeg succes');
    foreach (['reject' => 'failed', 'unknown' => 'unknown'] as $path => $expected) {
        $nzbgetRepo->save('central', null, 'http://127.0.0.1:' . $nzbgetPort . '/' . $path, 'fixture-user', 'fixture-pass', true, true);
        $attempt = $nzbgetJson('action=prepare&connection=central&id=' . $nzbgetSpot['id']);
        $failed = $nzbgetPost(['token' => $attempt['token'], 'revision' => $attempt['revision']] + $sendForm);
        $history = $nzbgetJson('action=history&connection=central')['history'];
        check($failed['status'] === 502 && $history[0]['status'] === $expected, 'NZBGet-uploadfout bewaart geweigerd/onbekend: ' . $path);
    }
    $nzbgetRepo->save('central', null, 'http://127.0.0.1:' . $nzbgetPort . '/restricted', 'fixture-user', 'fixture-pass', true, true);
    $restricted = json_decode($nzbgetPost(['action' => 'test', 'connection' => 'central'])['body'], true);
    check($restricted['categories'] === [] && str_contains($restricted['message'], 'niet lezen') && !str_contains(json_encode($restricted), 'secret-not-to-forward'),
        'Beperkte NZBGet-gebruiker: test slaagt zonder categorieen en zonder remote fouttekst');
    $nzbgetRepo->saveMappings([]);
    $uncategorized = $nzbgetJson('action=prepare&connection=central&id=' . $nzbgetSpot['id']);
    $before = count($nzbgetAppends());
    $sentWithout = $nzbgetPost(['token' => $uncategorized['token'], 'revision' => $uncategorized['revision']] + $sendForm);
    $lastAppend = $nzbgetAppends();
    check($uncategorized['category'] === 'Geen (NZBGet-standaard)' && $sentWithout['status'] === 201 && count($lastAppend) === $before + 1
        && end($lastAppend)['category'] === '', 'Zonder mapping verstuurt NZBGet zonder categorie, ook als config niet leesbaar is');
    $nzbgetRepo->save('central', null, 'http://127.0.0.1:' . $nzbgetPort . '/old', 'fixture-user', 'fixture-pass', true, true);
    check($nzbgetPost(['action' => 'test', 'connection' => 'central'])['status'] === 502, 'NZBGet ouder dan versie 16 wordt geweigerd');
    foreach (['redirect' => RuntimeException::class, 'nzbget' => NzbgetRejected::class] as $path => $expectedException) {
        try {
            (new NzbgetClient('http://127.0.0.1:' . $nzbgetPort . '/' . $path, 'fixture-user', $path === 'nzbget' ? 'wrong-password' : 'fixture-pass', true))->version();
            check(false, 'Onveilig of onjuist NZBGet-verzoek moet falen');
        } catch (RuntimeException $exception) {
            check($exception instanceof $expectedException && !str_contains($exception->getMessage(), 'wrong-password')
                && !str_contains($exception->getMessage(), 'secret-not-to-forward'), 'NZBGet-redirect/weigering faalt zonder geheimen of remote tekst: ' . $path);
        }
    }

    $nzbgetRepo->save('central', null, $nzbgetBase, 'fixture-user', 'fixture-pass', true, true);
    $targets = new Targets($pdo);
    check($targets->overview('http-tester', true)['default'] === 'central:nzbget' && $targets->overview('http-reader', false)['default'] === 'personal:sabnzbd',
        'Zonder voorkeur is de eerste ingeschakelde toegestane client de standaard');
    $detail = httpFixture($httpPort, '/spots/view?key=' . $nzbgetSpot['id'], $cookie)['body'];
    check(str_contains($detail, 'data-download-target="central:nzbget"') && str_contains($detail, '>NZBGet</button>') && !str_contains($detail, 'send-menu'),
        'Detailknop verstuurt naar de standaardclient; zonder alternatief geen keuzemenu');
    (new SabRepository($pdo))->save('user:http-tester', 'http-tester', 'http://127.0.0.1:' . $nzbgetPort . '/sab', str_repeat('a', 32), true, true);
    $default = $nzbgetPost(['action' => 'default', 'target' => 'personal:sabnzbd']);
    $overview = $targets->overview('http-tester', true);
    check($default['status'] === 302 && $overview['default'] === 'personal:sabnzbd'
        && count(array_filter($overview['targets'], static fn (array $target): bool => $target['enabled'])) === 2,
        'Gebruiker kiest zelf de standaard downloadclient');
    $detail = httpFixture($httpPort, '/spots/view?key=' . $nzbgetSpot['id'], $cookie)['body'];
    check(str_contains($detail, 'data-download-target="personal:sabnzbd"') && str_contains($detail, 'send-menu')
        && str_contains($detail, 'data-download-target="central:nzbget">Verzenden naar Centrale NZBGet (admin)')
        && str_contains($detail, '<option value="personal:sabnzbd" selected>'),
        'Detailpagina biedt "Verzenden naar..." voor eenmalig een andere ingeschakelde client');
    $preferences = httpFixture($httpPort, '/preferences', $cookie)['body'];
    check(str_contains($preferences, 'Standaard downloadclient') && str_contains($preferences, 'value="central:nzbget"'), 'Voorkeuren tonen keuze voor standaard downloadclient');
    $nzbgetPost(['action' => 'default', 'target' => 'central:nzbget'], true);
    $nzbgetPost(['action' => 'default', 'target' => 'personal:unknown']);
    check($targets->preference('http-reader') === null && $targets->preference('http-tester') === 'personal:sabnzbd',
        'Standaardclient accepteert alleen toegestane bekende doelen');
    $nzbgetPost(['action' => 'delete', 'connection' => 'central']);
    check(!$nzbgetRepo->settings('central')['configured'] && count($nzbgetJson('action=history&connection=central')['history']) === 4,
        'NZBGet-koppeling verwijderen wist host en wachtwoord maar behoudt historie');
    $auditJson = json_encode($pdo->query("SELECT details_json FROM admin_audit_logs WHERE action LIKE 'nzbget.%' OR action = 'downloader.default'")->fetchAll(), JSON_THROW_ON_ERROR);
    check(!str_contains($auditJson, 'fixture-pass'), 'NZBGet-audit bevat geen wachtwoord');
    $deletedUser = new \NnSpotty\Users\UserRepository($pdo);
    $deletedUser->create('nzbget-deleted-user', 'fixture-password', false);
    $nzbgetRepo->save('user:nzbget-deleted-user', 'nzbget-deleted-user', 'https://93.184.215.14', 'fixture-user', 'fixture-pass', true, false);
    $targets->saveDefault('nzbget-deleted-user', 'personal:nzbget', false);
    $deletedUser->delete('nzbget-deleted-user');
    check(!$nzbgetRepo->settings('user:nzbget-deleted-user')['configured'] && $targets->preference('nzbget-deleted-user') === null,
        'Userverwijdering verwijdert persoonlijke NZBGet-koppeling en standaardclient');
    (new SabRepository($pdo))->delete('user:http-tester');
    $pdo->exec('DELETE FROM downloader_preferences');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$nzbgetSpot['id']]);
} finally {
    proc_terminate($nzbgetServer);
    foreach ($nzbgetPipes as $pipe) fclose($pipe);
    proc_close($nzbgetServer);
    fclose($nzbgetServerLog);
    unlink($nzbgetLogFile);
}
