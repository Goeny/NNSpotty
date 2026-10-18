<?php

declare(strict_types=1);

use NnSpotty\Database\Migrator;
use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\RetrieveService;
use NnSpotty\Usenet\SpotImageService;
use NnSpotty\Usenet\SpotRepository;
use NnSpotty\Usenet\SpotnetParser;

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
    $days = $service->run('days', 30, true, $logger);
    check($spots->countSpots(null) === 2, '--days vindt alle recente spots buiten de incrementele artikellimiet');
    check($days['inserted'] === 1, 'Herladen is idempotent, oude spots worden gefilterd');
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
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.ptd';
    $state->saveUsenetSettings($settings);
    $pdo->exec("DELETE FROM migrations WHERE name = '20261018_0006_spotnet'");
    $migrator->migrate();
    check($state->getUsenetSettings()['explicit_groups'] === 'spots:free.pt,comments:free.usenet', 'Migratie corrigeert uitsluitend de oude standaard reactiegroep');
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
    proc_terminate($server);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($server);
    $pipes = [];
    $server = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php', '--fail'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($server)) {
        throw new RuntimeException('Kon falende NNTP fixture niet starten.');
    }
    $settings['port'] = (int) trim(fgets($pipes[1]));
    $state->saveUsenetSettings($settings);
    $pdo->exec("UPDATE retrieval_cursors SET last_article = 750 WHERE group_name = 'spotnet:spots:free.pt'");
    try {
        $service->run('since-last', null, true, $logger);
        throw new LogicException('Transportfout ten onrechte als succes behandeld.');
    } catch (\Rvdv\Nntp\Exception\UnknownHandlerException $exception) {
        check($pdo->query('SELECT status FROM retrieval_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed', 'Transportfout faalt expliciet en wordt opgeslagen');
    }
    $cursorAfterFailure = (int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn();
    check($cursorAfterFailure === 750, 'Transportfout schuift cursor niet naar ongelezen artikelen vooruit');
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
