<?php

declare(strict_types=1);

use NnSpotty\Moderation\SpotterLists;
use NnSpotty\Usenet\SpotRepository;
use NnSpotty\Usenet\RetrieveService;

$moderation = new SpotterLists($pdo);
check(!$moderation->settings()['enabled'], 'Externe sleutelmoderatie vereist expliciete activatie; migratie gebruikt geen netwerk');
$firstKey = Fixtures::signingKey();
$secondKey = Fixtures::signingKey();
$firstModulus = openssl_pkey_get_details($firstKey)['rsa']['n'];
$secondModulus = openssl_pkey_get_details($secondKey)['rsa']['n'];
$firstHash = hash('sha256', $firstModulus);
$listXml = static fn (string $name, string $modulus): string => '<Keys><Key Name="' . $name . '">'
    . base64_encode($modulus) . '</Key></Keys>';
$whiteXml = $listXml('Andere displaynaam', $firstModulus);
$blackXml = $listXml('Testspotter', $secondModulus);
$fetches = 0;
$fetchLists = static function (string $type) use (&$fetches, &$whiteXml, &$blackXml): string {
    $fetches++;
    return $type === 'whitelist' ? $whiteXml : $blackXml;
};
check(!$moderation->sync(false, $fetchLists) && $fetches === 0, 'Uitgeschakeld betekent geen automatische externe requests');
$moderation->enable(true);
check($moderation->sync(false, $fetchLists) && $fetches === 2, 'Eerste activatie haalt beide lijsten op');
$savedSnapshot = $moderation->entries();
check($savedSnapshot[$firstHash] === 'whitelist' && !$moderation->sync(false, $fetchLists) && $fetches === 2,
    'Opgeslagen lijsten blijven 24 uur geldig; geen fetch per spot of per volgende run');
rejects(static fn () => $moderation->sync(true, static fn (string $type): string => $type === 'whitelist'
    ? $whiteXml : '<Keys><Key Name="bad">invalid</Key></Keys>'), 'Ongeldige tweede feed breekt de totale update af');
check($moderation->entries() === $savedSnapshot && $moderation->settings()['last_error'] !== null,
    'Mislukte update bewaart beide vorige snapshots en toont foutstatus');
$moderation->sync(true, $fetchLists);
check($moderation->settings()['last_error'] === null, 'Geslaagde herhaling wist synchronisatiefout');
$whiteXml = str_replace('</Keys>', '<Key Name="Ongeldige feedkey">invalid!</Key></Keys>', $whiteXml);
$moderation->sync(true, $fetchLists);
check(str_contains($moderation->settings()['last_warning'], '1 ongeldige sleutelvermeldingen')
    && $moderation->entries()[$firstHash] === 'whitelist',
    'Gemengde bron slaat bruikbare snapshot op met blijvend zichtbare sleutelwaarschuwing');
$whiteXml = $listXml('Andere displaynaam', $firstModulus);
$moderation->sync(true, $fetchLists);
$pdo->exec('UPDATE spotter_list_settings SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = 1');
check($moderation->sync(false, $fetchLists) && $moderation->settings()['last_warning'] === null,
    'Meer dan 24 uur oude lijsten worden automatisch vervangen; herstelde bron wist waarschuwing');
$blackXml = $listXml('Ook op blacklist', $firstModulus);
$moderation->sync(true, $fetchLists);
check($moderation->entries()[$firstHash] === 'blacklist', 'Blacklist wint als dezelfde sleutel ook op whitelist staat');

$privateFixture = tmpfile();
if ($privateFixture === false) throw new RuntimeException('Tijdelijke synthetische sleutel kon niet worden geopend.');
if (!openssl_pkey_export($firstKey, $privatePem, null, ['config' => __DIR__ . '/openssl-test.cnf'])) {
    throw new RuntimeException('Synthetische testsleutel kon niet worden geexporteerd.');
}
fwrite($privateFixture, $privatePem);
$fixturePath = stream_get_meta_data($privateFixture)['uri'];
$moderationServer = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php', '--sign-spots=' . $fixturePath],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $moderationPipes);
if (!is_resource($moderationServer)) throw new RuntimeException('Ondertekende NNTP-fixture kon niet starten.');
$moderationState = new \NnSpotty\State\AppStateRepository($pdo);
$savedUsenet = $moderationState->getUsenetSettings();
try {
    $moderationPort = (int) trim((string) fgets($moderationPipes[1]));
    check($moderationPort > 0, 'Ondertekende NNTP-fixture is bereikbaar');
    $usenet = $savedUsenet;
    $usenet['server'] = '127.0.0.1';
    $usenet['port'] = $moderationPort;
    $usenet['retention'] = 0;
    $usenet['retrieve_after'] = null;
    $usenet['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
    $moderationState->saveUsenetSettings($usenet);
    $repository = new SpotRepository($pdo);
    $messages = [];
    $logger = static function (string $message) use (&$messages): void { $messages[] = $message; };
    $result = (new RetrieveService($pdo, $moderationState))->run('all', null, true, $logger);
    check($repository->countSpots(null) === 1 && $repository->getSpot(hash('sha256', 'spot-one@example.invalid')) === null
        && $repository->getSpot(hash('sha256', 'spot-two@example.invalid'))['signature_state'] === 'missing'
        && str_contains($result['message'], '3 wegens blacklist.')
        && count(array_filter($messages, static fn (string $line): bool => str_contains($line, 'blacklist=1'))) > 0,
        'Echte retrieve slaat gecontroleerde blacklisted spots over, behoudt gelijknamige onbevestigde spot en rapporteert aantallen');
    $blackXml = $listXml('Andere sleutel', $secondModulus);
    $moderation->sync(true, $fetchLists);
    (new RetrieveService($pdo, $moderationState))->run('all', null, true, $logger);
    $signedId = hash('sha256', 'spot-one@example.invalid');
    check($repository->getSpot($signedId)['spotter_status'] === 'whitelist'
        && in_array('whitelist', array_column($repository->batch('Beeld', 10, false, [], '')['spots'], 'spotter_status'), true),
        'Whitelist komt op detail en beperkte lijstprojectie via gecontroleerde sleutel, niet de feednaam');
    $moderation->enable(false);
    check($repository->getSpot($signedId)['spotter_status'] === 'unlisted',
        'Uitschakelen verwijdert badges zonder spots of identiteit te verwijderen');
    $moderation->enable(true);
    $blackXml = $listXml('Nu blacklist', $firstModulus);
    $moderation->sync(true, $fetchLists);
    check($repository->getSpot($signedId)['spotter_status'] === 'blacklist',
        'Nieuwe blackliststatus is direct zichtbaar op eerder opgeslagen spot; geen automatische verwijdering');
    $pdo->exec('DELETE FROM spotnet_spots');
    $pdo->exec('DELETE FROM retrieval_cursors');
    $usenet['max_articles_per_group'] = 2000;
    $moderationState->saveUsenetSettings($usenet);
    $incremental = (new RetrieveService($pdo, $moderationState))->run('since-last', null, true, $logger);
    check((int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn() === 1505
        && $repository->countSpots(null) === 1 && str_contains($incremental['message'], '3 wegens blacklist.'),
        'Incrementele cursor kan voorbij beoordeelde blacklisted artikelen voortgaan');
    $pdo->exec('UPDATE spotter_list_settings SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id = 1');
    $failingFetch = static function (string $type): string { throw new RuntimeException('Synthetische Spotlist-transportfout'); };
    $cachedResult = (new RetrieveService($pdo, $moderationState, $failingFetch))->run('all', null, true, $logger);
    check($repository->countSpots(null) === 1 && str_contains($cachedResult['message'], 'Spotlist-update mislukt; laatst opgeslagen lijsten gebruikt')
        && $moderation->settings()['last_error'] === 'Synthetische Spotlist-transportfout',
        'Automatische fetchfout gebruikt vorige blacklist en meldt de afwijking in status en runbericht');
    $pdo->exec('DELETE FROM spotter_keys');
    $pdo->exec('UPDATE spotter_list_settings SET updated_at = NULL WHERE id = 1');
    try {
        (new RetrieveService($pdo, $moderationState, $failingFetch))->run('all', null, true, $logger);
        check(false, 'Actieve moderatie zonder geldige snapshot moet retrieve weigeren');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'Spotterlijsten ontbreken')
            && $pdo->query('SELECT status FROM retrieval_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed'
            && $repository->countSpots(null) === 1,
            'Actieve moderatie zonder eerdere snapshot faalt zichtbaar voor NNTP-import');
    }
} finally {
    $moderationState->saveUsenetSettings($savedUsenet);
    $moderation->enable(false);
    $pdo->exec('DELETE FROM spotnet_spots');
    $pdo->exec('DELETE FROM spotnet_comments');
    $pdo->exec('DELETE FROM retrieval_runs');
    $pdo->exec('DELETE FROM retrieval_cursors');
    $pdo->exec('DELETE FROM spotter_keys');
    $pdo->exec('UPDATE spotter_list_settings SET updated_at = NULL, attempted_at = NULL, last_error = NULL, last_warning = NULL WHERE id = 1');
    proc_terminate($moderationServer);
    foreach ($moderationPipes as $pipe) fclose($pipe);
    proc_close($moderationServer);
    fclose($privateFixture);
}
