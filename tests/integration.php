<?php

declare(strict_types=1);

use NnSpotty\Database\Migrator;
use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\RetrieveService;
use NnSpotty\Usenet\SpotImageService;
use NnSpotty\Usenet\SpotRepository;
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Usenet\SpotCategories;

$dsn = getenv('NNSPOTTY_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('Stel NNSPOTTY_TEST_DSN in op een afzonderlijke MySQL/MariaDB testserver, plus NNSPOTTY_TEST_USER en NNSPOTTY_TEST_PASSWORD.');
}
$pdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'nnspotty_test_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$pdo->exec('USE `' . $database . '`');
$pipes = [];
$server = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($server)) {
    $pdo->exec('DROP DATABASE `' . $database . '`');
    throw new RuntimeException('Kon NNTP testserver niet starten.');
}
try {
    $port = (int) trim(fgets($pipes[1]));
    check($port > 0, 'Lokale NNTP fixture is gestart en bereikbaar');
    $migrator = new Migrator($pdo);
    $migrator->migrate();
    $migrator->migrate();
    check(count(array_filter($migrator->status(), static fn (array $row): bool => !$row['executed'])) === 0, 'Alle migraties werken en zijn herhaalbaar');
    $state = new AppStateRepository($pdo);
    $settings = $state->getUsenetSettings();
    $settings['server'] = '127.0.0.1';
    $settings['port'] = $port;
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
    $settings['max_articles_per_group'] = 100;
    $state->saveUsenetSettings($settings);
    $spots = new SpotRepository($pdo);
    $log = [];
    $logger = static function (string $message) use (&$log): void {
        $log[] = $message;
    };
    $service = new RetrieveService($pdo, $state);
    $firstRun = $service->run('since-last', null, true, $logger);
    check($firstRun['inserted'] === 2, 'Incrementele run is begrensd per groep en haalt reacties op');
    $cursor = (int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn();
    check($cursor === 799, 'Cursor bevat het echte laatste verwerkte artikelnummer');
    $service->run('since-last', null, true, $logger);
    check($spots->countSpots(null) === 1, 'Volgende incrementele run slaat geen tussengelegen spot over');
    $log = [];
    $days = $service->run('days', 30, true, $logger);
    check($spots->countSpots(null) === 2, '--days vindt alle recente spots buiten de incrementele artikellimiet');
    check($days['inserted'] === 1, 'Herladen is idempotent, oude spots worden gefilterd');
    $spotBatches = array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'free.pt batch ')));
    check(count($spotBatches) === 4
        && str_contains($spotBatches[0], '1256-1505')
        && str_contains($spotBatches[1], '1006-1255')
        && str_contains($spotBatches[2], '756-1005')
        && str_contains($spotBatches[3], '700-755'), '--days scant nieuwste ranges eerst, volledig en zonder gaten of overlap');
    check(str_contains($spotBatches[0], 'te_oud=1') && str_contains($spotBatches[2], 'opgeslagen=1'),
        'Oude batch stopt de scan niet: recente spot met lager artikelnummer blijft vindbaar');
    check(str_contains($spotBatches[2], 'datums=2009-01-01 12:00:00..')
        && str_contains($spotBatches[2], 'bestaand=1')
        && str_contains($spotBatches[2], 'geen_spot=1')
        && str_contains($spotBatches[2], 'ongeldig=2')
        && str_contains($spotBatches[2], 'verdwenen=1'), 'Batchlog verklaart datums en elke reden voor niet opslaan');
    check(count(array_filter($log, static fn (string $line): bool => str_contains($line, '918 overgeslagen: XOVER 756-1005')
        && str_contains($line, '2/9 velden'))) === 1, 'Afgebroken overview-regel wordt gemeld, andere spots worden opgeslagen');
    check((int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn() === 1505,
        'Volledig geslaagde omgekeerde scan zet cursor op het groepseinde');
    $spotId = hash('sha256', 'spot-one@example.invalid');
    $spot = $spots->getSpot($spotId);
    check($spot !== null && $spot['size'] === 4294967296, 'Spotinhoud en grootte correct in echte database');
    $comments = $spots->comments($spotId);
    check(count($comments) === 2 && str_contains($comments[0]['body'], "\r\n.Begint"), 'NNTP ARTICLE, dot-unstuffing en comment-koppeling werken');
    $state->addComment($spotId, 'local-user', 'Een lokale reactie');
    check(count($spots->comments($spotId)) === 3, 'Lokale en Usenet reacties samen getoond');
    $imageService = new SpotImageService($spots, $state);
    $image = $imageService->load($spot);
    check($image['mime_type'] === 'image/png' && $image['image_data'] === Fixtures::image(), 'Afbeelding via NNTP gedecodeerd en als BLOB opgeslagen');
    check($imageService->load($spot) === $image, 'Afbeelding wordt uit databasecache gelezen');
    $all = $service->run('all', null, true, $logger);
    check($spots->countSpots(null) === 3 && $all['inserted'] === 1, '--all negeert retentie en scant de volledige groep');
    $again = $service->run('since-last', null, true, $logger);
    check($again['inserted'] === 0, 'Na backfill gaat since-last verder vanaf het einde');
    check(count(array_filter($log, static fn (string $line): bool => str_contains($line, '914 overgeslagen'))) > 0, 'Corrupte spot XML wordt expliciet gerapporteerd');
    check(count(array_filter($log, static fn (string $line): bool => str_contains($line, '917 niet meer beschikbaar'))) > 0, 'Verlopen artikelen worden expliciet gerapporteerd');
    $legacyCount = (int) $pdo->query('SELECT COUNT(*) FROM usenet_posts')->fetchColumn();
    check($legacyCount === 0, 'Nieuwe retrieval schrijft geen generieke headers');
    $pdo->exec("INSERT INTO spot_releases VALUES ('" . str_repeat('a', 40) . "', 'Geen echte spot', 'general', 'alt.binaries.test', 'header-poster', 10, 123, NULL, NULL, 'x@example.invalid', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    check($spots->countSpots(null) === 3, 'Oude synthetische releases vervuilen de nieuwe spotlijst niet');
    $rows = $spots->listSpots('Beeld', 1, 0);
    check(count($rows) === 1 && $rows[0]['image_segments'] === ['image@example.invalid'], 'Categorie, paginering en JSON decoding via database');
    check(count($spots->listCategoriesWithCounts()) === 1, 'Categorieaantallen uit echte spots');
    $caseSpot = SpotnetParser::spot('Spot-one@example.invalid', Fixtures::headers('Spot-one@example.invalid'));
    $spots->saveSpot($caseSpot, 'free.pt');
    check($spots->countSpots(null) === 4, 'Message-IDs met verschillende hoofdletters blijven verschillende spots');
    $caseComment = SpotnetParser::comment('Comment@example.invalid', Fixtures::comment());
    $spots->saveComment($caseComment);
    check(count($spots->comments($spotId)) === 4, 'Reactie message-IDs zijn hoofdlettergevoelig');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id = 'Spot-one@example.invalid'");
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id = 'Comment@example.invalid'");
    $pdo->exec('DELETE FROM spotnet_images');
    require __DIR__ . '/http.php';
    $adult = SpotnetParser::spot('adult@example.invalid', Fixtures::headers('adult@example.invalid',
        str_replace('<Sub>1a01</Sub>', '<Sub>1a09</Sub><Sub>1d23</Sub>', Fixtures::xml('Afgeschermde testspot'))));
    $spots->saveSpot($adult, 'free.pt');
    $state->addComment($adult['id'], 'local-user', 'Afgeschermde reactie');
    $pdo->exec("UPDATE spotnet_spots SET browse_type = 'Overig', is_erotica = 1");
    $pdo->exec('DELETE FROM spotnet_spot_categories');
    $pdo->exec("DELETE FROM migrations WHERE name = '20261018_0008_category_backfill'");
    $migrator->migrate();
    check($spots->getSpot($adult['id'], false) === null && $spots->countSpots(null, false) === 3, 'Migratie classificeert bestaande spots en schermt legacy erotica af zonder hernieuwde retrieval');
    check($spots->getSpot($adult['id'], true)['browse_type'] === 'Erotica', 'Bestaande legacy erotica krijgt het juiste type');
    check(count($spots->listCategoriesWithCounts(false)) === 1 && count($spots->listCategoriesWithCounts(true)) === 2, 'Categorieaantallen passen zichtbaarheid toe');
    check($spots->countSpots(null, false, '0:a9') === 0
        && $spots->countSpots(null, true, '0:a9') === 1
        && $spots->countSpots('Erotica', false) === 0, 'Facet en categoriefilter kunnen erotica niet ontsluiten');
    check($spots->countSpots(null, true, null, 'Afgeschermde') === 1
        && $spots->countSpots(null, false, null, 'Afgeschermde') === 0
        && $spots->countSpots(null, true, null, '%') === 0, 'Zoeken respecteert zichtbaarheid en behandelt wildcards letterlijk');
    check(count($spots->facets('Erotica', false)) === 0, 'Geen verborgen facetten of aantallen');
    check(!in_array($adult['id'], array_column($state->getComments(false), 'post_id'), true), 'Admin commentoverzicht lekt geen afgeschermde reacties');
    $firstCodes = $pdo->query('SELECT spot_id, code FROM spotnet_spot_categories ORDER BY spot_id, code')->fetchAll();
    check($spots->rebuildCategories() === 4
        && $firstCodes === $pdo->query('SELECT spot_id, code FROM spotnet_spot_categories ORDER BY spot_id, code')->fetchAll(), 'Categoriebackfill is volledig en idempotent');
    $duplicate = $adult;
    $duplicate['subcategories'] = ['1z00'];
    $spots->saveSpot($duplicate, 'free.pt');
    check($spots->getSpot($adult['id'], false) === null, 'Duplicaat kan classificatie niet los van oorspronkelijke XML veranderen');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$adult['id']]);
    for ($n = 0; $n < 251; $n++) {
        $batchSpot = $adult;
        $batchSpot['message_id'] = 'batch-' . $n . '@example.invalid';
        $batchSpot['id'] = hash('sha256', $batchSpot['message_id']);
        $batchSpot['subcategories'] = $n % 2 === 0 ? ['1d23'] : ['1z01'];
        $spots->saveSpot($batchSpot, 'free.pt');
    }
    $pdo->exec("UPDATE spotnet_spots SET browse_type = 'Overig', is_erotica = 1");
    $pdo->exec('DELETE FROM spotnet_spot_categories');
    $pdo->exec("DELETE FROM migrations WHERE name = '20261018_0008_category_backfill'");
    $migrator->migrate();
    check($spots->countSpots(null, true) === 254 && $spots->countSpots('Erotica', true) === 126
        && $spots->countSpots('Series', false) === 125 && $spots->countSpots(null, false) === 128,
        'Backfill verwerkt meer dan 250 rijen volledig, inclusief zichtbaarheidsgrens over batches');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'batch-%@example.invalid'");
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.ptd';
    $state->saveUsenetSettings($settings);
    $pdo->exec("DELETE FROM migrations WHERE name = '20261018_0006_spotnet'");
    $migrator->migrate();
    check($state->getUsenetSettings()['explicit_groups'] === 'spots:free.pt,comments:free.usenet', 'Migratie corrigeert uitsluitend de oude standaard reactiegroep');
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
    foreach (['--fail-lower' => 'days', '--fail' => 'since-last'] as $failureFlag => $failureMode) {
        proc_terminate($server);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($server);
        $pipes = [];
        $server = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php', $failureFlag], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($server)) {
            throw new RuntimeException('Kon falende NNTP fixture niet starten.');
        }
        $settings['port'] = (int) trim(fgets($pipes[1]));
        $state->saveUsenetSettings($settings);
        $pdo->exec("UPDATE retrieval_cursors SET last_article = 750 WHERE group_name = 'spotnet:spots:free.pt'");
        if ($failureMode === 'days') {
            $pdo->exec("DELETE FROM spotnet_spots WHERE message_id = 'spot-two@example.invalid'");
        }
        try {
            $service->run($failureMode, $failureMode === 'days' ? 30 : null, true, $logger);
            throw new LogicException('Transportfout ten onrechte als succes behandeld.');
        } catch (\Rvdv\Nntp\Exception\UnknownHandlerException $exception) {
            check($pdo->query('SELECT status FROM retrieval_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed',
                $failureMode . ': transportfout faalt expliciet en wordt opgeslagen');
        }
        $cursorAfterFailure = (int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn();
        check($cursorAfterFailure === 750, $failureMode . ': transportfout schuift cursor niet naar ongelezen artikelen vooruit');
        if ($failureMode === 'days') {
            check($spots->getSpot(hash('sha256', 'spot-two@example.invalid')) !== null,
                'Nieuwe spot wordt al opgeslagen voordat een lagere historische range faalt');
        }
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($server);
    }
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
