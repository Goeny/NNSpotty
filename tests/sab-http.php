<?php

declare(strict_types=1);

use NnSpotty\Downloader\SabRepository;
use NnSpotty\Downloader\SabClient;
use NnSpotty\Usenet\SpotnetParser;

$sabSocket = stream_socket_server('tcp://127.0.0.1:0', $sabErrno, $sabError);
if ($sabSocket === false) {
    throw new RuntimeException($sabError, $sabErrno);
}
$sabPort = (int) substr(strrchr(stream_socket_get_name($sabSocket, false), ':'), 1);
fclose($sabSocket);
$sabLogFile = tempnam(sys_get_temp_dir(), 'nnspotty-sab-');
$sabServerLog = tmpfile();
$sabPipes = [];
$sabServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $sabPort, __DIR__ . '/sab-server.php'],
    [['pipe', 'r'], $sabServerLog, $sabServerLog], $sabPipes, dirname(__DIR__),
    array_merge(getenv(), ['NNSPOTTY_SAB_LOG' => $sabLogFile]));
if (!is_resource($sabServer)) {
    throw new RuntimeException('Kon SABnzbd-fixture niet starten.');
}
$sabRepo = new SabRepository($pdo);
$sabKey = str_repeat('a', 32);
$sabBase = 'http://127.0.0.1:' . $sabPort . '/sabnzbd';
$sabEntries = static fn (): array => array_map(static fn ($line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR),
    file($sabLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$sabPost = static function (array $form, bool $reader = false) use ($httpPort, &$cookie, &$readerCookie, $token, $readerToken): array {
    if ($reader) {
        return httpFixture($httpPort, '/downloader', $readerCookie, $form + ['_csrf' => $readerToken[1], 'connection' => 'personal']);
    }
    return httpFixture($httpPort, '/downloader', $cookie, $form + ['_csrf' => $token[1], 'connection' => 'personal']);
};
try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $sabPort, $probeErrno, $probeError, .1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'SABnzbd-fixture is bereikbaar');
    $anonymousCookie = '';
    check(httpFixture($httpPort, '/downloader', $anonymousCookie)['status'] === 401
        && httpFixture($httpPort, '/downloader', $cookie, ['action' => 'send'])['status'] === 403, 'Downloader vereist browsersessie en POST-CSRF');
    check(httpFixture($httpPort, '/downloader?connection=central', $readerCookie)['status'] === 403
        && $sabPost(['action' => 'save', 'connection' => 'central', 'url' => $sabBase, 'api_key' => $sabKey], true)['status'] === 403,
        'Gewone gebruiker kan centrale wachtrij niet zien of centrale instellingen wijzigen');
    $blocked = $sabPost(['action' => 'save', 'url' => $sabBase, 'api_key' => $sabKey, 'enabled' => '1'], true);
    check($blocked['status'] === 302 && !$sabRepo->settings('user:http-reader')['enabled'] && $sabEntries() === [],
        'Persoonlijke interne host wordt geweigerd zonder netwerkcontact of opslag');
    $saved = $sabPost(['action' => 'save', 'connection' => 'central', 'url' => $sabBase, 'api_key' => $sabKey, 'enabled' => '1']);
    check($saved['status'] === 302 && $sabRepo->settings('central')['enabled'], 'Admin kan centrale interne SABnzbd-host configureren');
    $sabPost(['action' => 'save', 'url' => $sabBase, 'api_key' => $sabKey, 'enabled' => '1', 'username' => 'http-reader']);
    check($sabRepo->settings('user:http-tester')['enabled'] && !$sabRepo->settings('user:http-reader')['enabled'],
        'Persoonlijke verbinding komt uit sessie, niet uit vervalste username');
    $sabPost(['action' => 'save', 'url' => $sabBase, 'api_key' => '', 'enabled' => '1']);
    check($sabRepo->settings('user:http-tester')['api_key'] === $sabKey
        && !str_contains(httpFixture($httpPort, '/preferences', $cookie)['body'], $sabKey)
        && !str_contains(httpFixture($httpPort, '/admin', $cookie)['body'], $sabKey), 'Leeg keyveld behoudt geheim; voorkeuren en admin renderen de opgeslagen key nooit');
    $test = $sabPost(['action' => 'test', 'connection' => 'central']);
    check($test['status'] === 200 && json_decode($test['body'], true)['categories'] === ['*', 'movies', 'tv', 'music', 'books', 'xxx'],
        'Verbindingstest haalt echte SABnzbd-categorienamen via POST op');
    check($sabPost(['action' => 'mapping', 'category_0' => 'movies'], true)['status'] === 403, 'Categoriebeheer is alleen voor admins');
    $mappingForm = ['action' => 'mapping', 'connection' => 'central', 'category_0' => 'movies', 'category_1' => 'tv', 'category_5' => 'music'];
    $sabPost($mappingForm);
    check($sabRepo->settings('central')['mappings']['Films'] === 'movies'
        && $sabRepo->settings('user:http-tester')['mappings']['Series'] === 'tv', 'Adminmapping geldt ook voor persoonlijke koppelingen');
    $sabSpot = SpotnetParser::spot('sab-fixture@example.invalid', Fixtures::headers('sab-fixture@example.invalid',
        str_replace('<Sub>1a01</Sub>', '<Sub>1a09</Sub><Sub>1z01</Sub>', Fixtures::xml('SAB Fixture S01E01'))));
    $spots->saveSpot($sabSpot, 'free.pt');
    $pdo->prepare('INSERT INTO spotnet_nzbs (spot_id, nzb_data) VALUES (?, ?)')->execute([$sabSpot['id'], Fixtures::nzb()]);
    $prepare = httpFixture($httpPort, '/downloader?action=prepare&connection=central&id=' . $sabSpot['id'], $cookie);
    $prepared = json_decode($prepare['body'], true);
    check($prepare['status'] === 200 && $prepared['category'] === 'tv' && strlen($prepared['token']) === 32,
        'Voorbereiding toont effectieve categorie en geeft een nieuwe downloadbevestiging');
    $sendForm = ['action' => 'send', 'connection' => 'central', 'id' => $sabSpot['id'], 'token' => $prepared['token'], 'revision' => $prepared['revision']];
    $sent = $sabPost($sendForm);
    $uploads = array_values(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile'));
    check($sent['status'] === 201 && count($uploads) === 1 && $uploads[0]['valid_nzb']
        && $uploads[0]['cat'] === 'tv' && $uploads[0]['title'] === 'SAB Fixture S01E01',
        'Werkelijke multipart NZB-upload stuurt correcte categorie en releasebenaming, geen remote NZB-URL');
    check($uploads[0]['query'] === '', 'SABnzbd API-key staat niet in URL/querystring');
    check($sabPost($sendForm)['status'] === 409
        && count(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile')) === 1,
        'Replay van bevestiging veroorzaakt geen tweede upload');
    $status = httpFixture($httpPort, '/downloader?connection=central', $cookie);
    $queueData = json_decode($status['body'], true);
    check($status['status'] === 200 && (float) $queueData['speed'] === 1024.5 * 1024 && $queueData['slots'][0]['percentage'] === 42.3
        && $queueData['history'][0]['status'] === 'sent' && $queueData['history'][0]['nzo_id'] === 'SABnzbd_nzo_fixture',
        'Paneelstatus geeft echte snelheid, voortgang en persistente verzendhistorie');
    check(!str_contains($status['body'], 'secret-not-to-forward') && !str_contains($status['body'], 'server-local-path')
        && in_array('Cache-Control: private, no-store', $status['headers'], true), 'Status filtert geheime SAB-velden en is niet cachebaar');
    $personalStatus = json_decode(httpFixture($httpPort, '/downloader', $cookie)['body'], true);
    check($personalStatus['history'] === [], 'Centrale verzendhistorie lekt niet naar persoonlijke koppeling');
    check(httpFixture($httpPort, '/downloader?offset=-1', $cookie)['status'] === 400
        && httpFixture($httpPort, '/downloader?offset[]=1', $cookie)['status'] === 400,
        'Ongeldige wachtrijpaginering faalt expliciet');
    $sabRepo->save('user:http-reader', 'http-reader', $sabBase, $sabKey, true, true);
    $contactBefore = count($sabEntries());
    check(httpFixture($httpPort, '/downloader', $readerCookie)['status'] === 400
        && $sabPost(['action' => 'send', 'id' => $sabSpot['id'], 'token' => bin2hex(random_bytes(16))], true)['status'] === 400
        && count($sabEntries()) === $contactBefore, 'Huidige rol wordt bij iedere actie opnieuw gecontroleerd, ook voor eerder opgeslagen interne persoonlijke hosts');
    $sabPost(['action' => 'mapping', 'connection' => 'central', 'category_1' => 'missing']);
    $missing = $sabPost($sendForm + []);
    check($missing['status'] === 400 && str_contains($missing['body'], 'gewijzigd'), 'Gewijzigde categorie/host maakt een oude downloadbevestiging ongeldig');
    $missingPrepare = json_decode(httpFixture($httpPort, '/downloader?action=prepare&connection=central&id=' . $sabSpot['id'], $cookie)['body'], true);
    $missingForm = $sendForm;
    $missingForm['revision'] = $missingPrepare['revision'];
    $missingForm['token'] = $missingPrepare['token'];
    $missing = $sabPost($missingForm);
    check($missing['status'] === 400 && count(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile')) === 1,
        'Ontbrekende SAB-categorie blokkeert upload zonder stille fallback');
    $sabPost($mappingForm);
    $sabRepo->saveMappings([]);
    $defaultPrepare = json_decode(httpFixture($httpPort, '/downloader?action=prepare&id=' . $sabSpot['id'], $cookie)['body'], true);
    $defaultSend = $sabPost(['action' => 'send', 'id' => $sabSpot['id'], 'token' => $defaultPrepare['token'], 'revision' => $defaultPrepare['revision']]);
    $lastUpload = array_values(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile'));
    check($defaultSend['status'] === 201 && end($lastUpload)['cat'] === '*', 'Lege categorietoewijzing stuurt expliciet SABnzbd Default mee');
    $sabPost($mappingForm);
    foreach (['reject' => 'failed', 'unknown' => 'unknown'] as $path => $expectedStatus) {
        $sabRepo->save('central', null, 'http://127.0.0.1:' . $sabPort . '/' . $path, $sabKey, true, true);
        $failedForm = $sendForm;
        $failedForm['token'] = bin2hex(random_bytes(16));
        $updatedPrepare = json_decode(httpFixture($httpPort, '/downloader?action=prepare&connection=central&id=' . $sabSpot['id'], $cookie)['body'], true);
        $failedForm['revision'] = $updatedPrepare['revision'];
        $failed = $sabPost($failedForm);
        check($failed['status'] === 502 && $sabRepo->history('central', false)[0]['status'] === $expectedStatus,
            'Uploadfout bewaart onderscheid geweigerd/onbekend: ' . $path);
        $beforeReplay = count(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile'));
        check($sabPost($failedForm)['status'] === 409
            && count(array_filter($sabEntries(), static fn ($row): bool => $row['mode'] === 'addfile')) === $beforeReplay,
            'Onzekere/geweigerde poging wordt niet automatisch opnieuw verstuurd');
    }
    foreach (['redirect', 'huge'] as $invalidPath) {
        try {
            (new SabClient('http://127.0.0.1:' . $sabPort . '/' . $invalidPath, $sabKey, true))->call('queue');
            check(false, 'Onveilig transport moet falen');
        } catch (RuntimeException $exception) {
            check(true, 'Redirect of te grote API-respons faalt expliciet: ' . $invalidPath);
        }
    }
    try {
        (new SabClient($sabBase, str_repeat('b', 32), true))->categories();
        check(false, 'Ongeldige SABnzbd-key moet falen');
    } catch (\NnSpotty\Downloader\SabRejected $exception) {
        check(!str_contains($exception->getMessage(), str_repeat('b', 32)), 'SABnzbd-weigering toont geen geheime key of ruwe remote fouttekst');
    }
    $sabRepo->save('central', null, 'http://127.0.0.1:' . $sabPort . '/invalid', $sabKey, true, true);
    check(httpFixture($httpPort, '/downloader?connection=central', $cookie)['status'] === 502, 'Onvolledige wachtrij wordt geen leeg succes');
    $sabRepo->save('central', null, $sabBase, $sabKey, false, true);
    check(httpFixture($httpPort, '/downloader?connection=central', $cookie)['status'] === 400, 'Uitgeschakelde koppeling blokkeert wachtrij en verzending');
    check(count(json_decode(httpFixture($httpPort, '/downloader?action=history&connection=central', $cookie)['body'], true)['history']) === 3,
        'Lokale verzendhistorie blijft beschikbaar als koppeling uit staat of onbereikbaar is');
    $sabRepo->save('central', null, $sabBase, $sabKey, true, true);
    $adultSab = $sabSpot;
    $adultSab['id'] = hash('sha256', 'adult-sab');
    $adultSab['message_id'] = 'adult-sab@example.invalid';
    $adultSab['subcategories'] = ['1a09', '1d75'];
    $spots->saveSpot($adultSab, 'free.pt');
    check(httpFixture($httpPort, '/downloader?action=prepare&connection=central&id=' . $adultSab['id'], $cookie)['status'] === 400
        && $sabPost(['action' => 'send', 'connection' => 'central', 'id' => $adultSab['id'], 'token' => bin2hex(random_bytes(16))])['status'] === 400,
        'Globale erotica-blokkade geldt ook voor direct versturen door admin');
    $sabPost(['action' => 'delete', 'connection' => 'central']);
    check($sabRepo->settings('central')['api_key'] === '' && count($sabRepo->history('central', false)) === 3,
        'Koppeling verwijderen wist host/key maar behoudt historie');
    $auditJson = json_encode($pdo->query("SELECT details_json FROM admin_audit_logs WHERE action LIKE 'sabnzbd.%'")->fetchAll(), JSON_THROW_ON_ERROR);
    check(!str_contains($auditJson, $sabKey), 'SABnzbd-audit bevat geen API-key');
    $sabRepo->delete('user:http-tester');
    $sabRepo->delete('user:http-reader');
    $deletedUser = new \NnSpotty\Users\UserRepository($pdo);
    $deletedUser->create('sab-deleted-user', 'fixture-password', false);
    $sabRepo->save('user:sab-deleted-user', 'sab-deleted-user', $sabBase, $sabKey, true, true);
    $deletedUser->delete('sab-deleted-user');
    check($sabRepo->settings('user:sab-deleted-user')['api_key'] === '', 'Userverwijdering verwijdert ook persoonlijke SABnzbd-host en key');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (?, ?)')->execute([$sabSpot['id'], $adultSab['id']]);
    $sabRepo->saveMappings([]);
} finally {
    proc_terminate($sabServer);
    foreach ($sabPipes as $pipe) fclose($pipe);
    proc_close($sabServer);
    fclose($sabServerLog);
    unlink($sabLogFile);
}
