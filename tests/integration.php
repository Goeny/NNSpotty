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
    $contentDefaults = $state->spotContentSettings();
    check($contentDefaults === ['retrieve_comments' => true, 'show_comments' => true, 'images_enabled' => true], 'Nieuwe inhoudsinstellingen behouden bestaand gedrag standaard');
    $state->saveSpotContentSettings(['retrieve_comments' => false, 'show_comments' => true, 'images_enabled' => true]);
    $disabledRun = $service->run('since-last', null, true, $logger);
    check($disabledRun['groups'] === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM spotnet_comments')->fetchColumn() === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM retrieval_cursors WHERE group_name = 'spotnet:comments:free.usenet'")->fetchColumn() === 0
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'Reacties ophalen is uitgeschakeld'))) === 1,
        'Reacties uit slaat groep, opslag en cursor over en meldt dit expliciet');
    rejects(static fn () => $service->run('days', 7, true, $logger, commentsOnly: true), 'Gerichte reactierun omzeilt adminblokkade niet');
    $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'");
    $state->saveSpotContentSettings($contentDefaults);
    check((new AppStateRepository($pdo))->spotContentSettings() === $contentDefaults, 'Inhoudsinstellingen blijven na herladen behouden');
    rejects(static fn () => $state->saveSpotContentSettings(['retrieve_comments' => '0', 'show_comments' => true, 'images_enabled' => true]), 'Niet-booleaanse inhoudsinstelling geweigerd');
    rejects(static fn () => $state->saveSpotContentSettings(['retrieve_comments' => true]), 'Onvolledige inhoudsinstellingen geweigerd');
    $firstRun = $service->run('since-last', null, true, $logger);
    check($firstRun['inserted'] === 2, 'Incrementele run is begrensd per groep en haalt reacties op');
    check($firstRun['groups'] === 2, 'Reacties opnieuw aan verwerkt de eerder overgeslagen groep vanaf de oorspronkelijke positie');
    $retrieveStatus = $service->status();
    check(!$retrieveStatus['running'] && $retrieveStatus['last_status'] === 'completed'
        && is_string($retrieveStatus['last_run_at']), 'Retrieve-status toont laatste afgeronde run en geen actieve run');
    $lockPdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $lockPdo->exec('USE `' . $database . '`');
    $lockName = 'nns:' . substr(hash('sha256', $database), 0, 60);
    $lockStatement = $lockPdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $lockStatement->execute(['lock_name' => $lockName]);
    try {
        check($service->status()['running'], 'Retrieve-status detecteert actieve advisory lock');
        try {
            $service->run('since-last', null, true, $logger);
            check(false, 'Gelijktijdige retrieve wordt geweigerd');
        } catch (DomainException) {
            check(true, 'Gelijktijdige retrieve wordt geweigerd');
        }
    } finally {
        $releaseLock = $lockPdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseLock->execute(['lock_name' => $lockName]);
        $releaseLock->fetchColumn();
    }
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
    check($spot['newsreader'] === 'Spotnet 1.9.0.8', 'Newsreader wordt via NNTP en database behouden');
    $pdo->exec("UPDATE spotnet_comments SET spot_id = '" . str_repeat('a', 64) . "' WHERE message_id = 'spot-one.1.2.3@example.invalid'");
    $pdo->exec('ALTER TABLE spotnet_spots DROP COLUMN newsreader');
    $pdo->exec("DELETE FROM migrations WHERE name = '20261018_0010_spot_details_comments'");
    $migrator->migrate();
    $migrator->migrate();
    check(count($spots->comments($spotId)) === 2, 'Migratie herstelt fout gekoppelde bestaande reacties zonder opnieuw downloaden of dupliceren');
    check($spots->getSpot($spotId)['newsreader'] === '', 'Upgrade voegt clientveld veilig toe aan reeds gevulde spottabel zonder verzonnen waarde');
    $spotCursorBefore = $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn();
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id = 'spot-one.1.2.3@example.invalid'");
    $log = [];
    $commentsOnlyRun = $service->run('days', 7, true, $logger, commentsOnly: true);
    check($commentsOnlyRun['groups'] === 1 && $commentsOnlyRun['inserted'] === 1 && count($spots->comments($spotId)) === 2
        && count(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'spots free.pt:'))) === 0
        && $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:free.pt'")->fetchColumn() === $spotCursorBefore,
        'Gerichte reactierun haalt echte reacties op zonder spotgroep of spotcursor te benaderen');
    rejects(static fn () => $service->run('since-last', null, true, $logger, refreshSpots: true), 'Metadata vernieuwen vereist expliciete backfillperiode');
    rejects(static fn () => $service->run('days', 7, true, $logger, commentsOnly: true, refreshSpots: true), 'Conflicterende retrievalselectie wordt afgewezen');
    $pdo->exec("UPDATE spotnet_spots SET newsreader = ''");
    $commentCursorBefore = $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:comments:free.usenet'")->fetchColumn();
    $refreshedRun = $service->run('days', 7, true, $logger, refreshSpots: true);
    check($refreshedRun['groups'] === 1 && $refreshedRun['inserted'] === 0 && $refreshedRun['refreshed'] === 2
        && $spots->getSpot($spotId)['newsreader'] === 'Spotnet 1.9.0.8'
        && $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:comments:free.usenet'")->fetchColumn() === $commentCursorBefore,
        'Metadata-refresh vernieuwt bestaande spots en bewaart reacties en hun cursor');
    $state->addComment($spotId, 'local-user', 'Een lokale reactie');
    check(count($spots->comments($spotId)) === 3, 'Lokale en Usenet reacties samen getoond');
    $imageService = new SpotImageService($spots, $state);
    $image = $imageService->load($spot);
    check($image['mime_type'] === 'image/png' && $image['image_data'] === Fixtures::image(), 'Afbeelding via NNTP gedecodeerd en als BLOB opgeslagen');
    check($imageService->load($spot) === $image, 'Afbeelding wordt uit databasecache gelezen');
    $changedImage = SpotnetParser::spot('spot-one@example.invalid', Fixtures::headers(xml: str_replace(
        'image@example.invalid', 'updated-image@example.invalid', Fixtures::xml()
    )));
    $spots->saveSpot($changedImage, 'free.pt', true);
    check($spots->image($spotId) === null && count($spots->comments($spotId)) === 3, 'Expliciete metadatarefresh verwijdert een verouderde covercache maar bewaart reacties');
    $spots->saveSpot(SpotnetParser::spot('spot-one@example.invalid', Fixtures::headers()), 'free.pt', true);
    $settings['retention'] = 30;
    $settings['retrieve_after'] = '2000-01-01';
    $state->saveUsenetSettings($settings);
    $all = $service->run('all', null, true, $logger);
    check($spots->countSpots(null) === 2 && $all['inserted'] === 1
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'datumgrens=')
            && !str_contains($line, 'datumgrens=geen'))) === 2,
        '--all respecteert retentie en past de strengste begindatum toe');
    $settings['retention'] = 0;
    $settings['retrieve_after'] = '2099-01-01';
    $state->saveUsenetSettings($settings);
    $log = [];
    $futureStartRun = $service->run('all', null, true, $logger);
    check($futureStartRun['inserted'] === 0
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'datumgrens=2099-01-01 00:00:00'))) === 2,
        'Handmatige begindatum geldt ook bij onbeperkte retentie');
    $settings['retention'] = 30;
    $settings['retrieve_after'] = '2000-01-01';
    $state->saveUsenetSettings($settings);
    $expiredSpot = SpotnetParser::spot('expired-retention@example.invalid', Fixtures::headers('expired-retention@example.invalid'));
    $expiredSpot['posted_at'] = '2000-01-01 00:00:00';
    $spots->saveSpot($expiredSpot, 'free.pt');
    $spots->saveComment(SpotnetParser::comment('expired-retention.1.2.3@example.invalid',
        Fixtures::comment('<expired-retention@example.invalid>')));
    $state->addComment($expiredSpot['id'], 'local-user', 'Verloopt samen met de spot');
    $spots->saveImage($expiredSpot['id'], 'image/png', Fixtures::image());
    $log = [];
    $cleanupRun = $service->run('all', null, true, $logger);
    check($cleanupRun['status'] === 'completed' && $spots->getSpot($expiredSpot['id']) === null
        && $spots->comments($expiredSpot['id']) === [] && $spots->image($expiredSpot['id']) === null,
        'Geslaagde retrieve verwijdert verlopen spots en gekoppelde reacties en afbeeldingen');
    check((int) $pdo->query("SELECT COUNT(*) FROM post_comments WHERE post_id = '" . $expiredSpot['id'] . "'")->fetchColumn() === 0
        && str_contains($cleanupRun['message'], '1 spots ouder dan de retentie verwijderd'),
        'Spotretentie ruimt ook lokale reacties op en meldt verwijderingen');
    $copyExpiredSpot = $pdo->prepare(
        'INSERT INTO spotnet_spots
            (id, message_id, group_name, title, description, poster, newsreader, category, subcategories, size,
             posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, browse_type, is_erotica, created_at)
         SELECT :id, :message_id, group_name, title, description, poster, newsreader, category, subcategories, size,
             posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, browse_type, is_erotica, created_at
         FROM spotnet_spots WHERE id = :source'
    );
    foreach ([10, 10000] as $expiredCount) {
        $spots->saveSpot($expiredSpot, 'free.pt');
        $pdo->beginTransaction();
        try {
            for ($index = 1; $index < $expiredCount; $index++) {
                $messageId = 'retention-batch-' . $index . '@example.invalid';
                $copyExpiredSpot->execute([
                    'id' => hash('sha256', $messageId),
                    'message_id' => $messageId,
                    'source' => $expiredSpot['id'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
        $recentCount = $spots->countSpots(null) - $expiredCount;
        $batchCleanupRun = $service->run('since-last', null, true, $logger);
        check($batchCleanupRun['status'] === 'completed'
            && str_contains($batchCleanupRun['message'], $expiredCount . ' spots ouder dan de retentie verwijderd')
            && (int) $pdo->query("SELECT COUNT(*) FROM spotnet_spots WHERE posted_at < '2000-01-02'")->fetchColumn() === 0
            && $spots->countSpots(null) === $recentCount,
            'Een cron-retrieve ruimt alle ' . $expiredCount . ' verlopen spots op en bewaart recente spots');
    }
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
    check($spots->countSpots(null) === 3, 'Message-IDs met verschillende hoofdletters blijven verschillende spots');
    $caseComment = SpotnetParser::comment('Comment@example.invalid', Fixtures::comment());
    $spots->saveComment($caseComment);
    check(count($spots->comments($spotId)) === 4, 'Reactie message-IDs zijn hoofdlettergevoelig');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id = 'Spot-one@example.invalid'");
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id = 'Comment@example.invalid'");
    $pdo->exec('DELETE FROM spotnet_images');
    require __DIR__ . '/browse-visits-integration.php';
    require __DIR__ . '/tvdb-integration.php';
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
    $firstBatch = $spots->batch('Beeld', 10, false, ['0:z1'], 'Afgeschermde');
    check(count($firstBatch['spots']) === 10 && $firstBatch['next'] !== null, 'Keyset retourneert batchgrootte en vervolgpositie voor gefilterde spots');
    $expected = array_column($spots->listSpots('Beeld', 500, 0, false, ['0:z1'], 'Afgeschermde'), 'id');
    $newest = $adult;
    $newest['message_id'] = 'concurrent-batch@example.invalid';
    $newest['id'] = hash('sha256', $newest['message_id']);
    $newest['posted_at'] = '2099-01-01 12:00:00';
    $newest['subcategories'] = ['1z01'];
    $spots->saveSpot($newest, 'free.pt');
    $seen = array_column($firstBatch['spots'], 'id');
    $next = $firstBatch['next'];
    do {
        $batch = $spots->batch('Beeld', 10, false, ['0:z1'], 'Afgeschermde', $next);
        $seen = array_merge($seen, array_column($batch['spots'], 'id'));
        $next = $batch['next'];
    } while ($next !== null);
    check($seen === $expected && count(array_unique($seen)) === count($seen), 'Keyset slaat bij gelijke datums en gelijktijdige nieuwe indexering geen bestaande spots over en verdubbelt niets');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$newest['id']]);
    rejects(static fn () => $spots->batch(null, 10, false, [], '', 'invalid'), 'Ongeldige keyset wordt expliciet geweigerd');
    rejects(static fn () => $spots->batch(null, 10, false, [], '', base64_encode('2026-02-31 00:00:00|' . str_repeat('a', 64))), 'Ongeldige keysetdatum wordt geweigerd');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'batch-%@example.invalid'");
    $multi = $adult;
    $multi['message_id'] = 'multi-facet@example.invalid';
    $multi['id'] = hash('sha256', $multi['message_id']);
    $multi['subcategories'] = ['1a09', '1a10', '1d00', '1d01', '1z00'];
    $spots->saveSpot($multi, 'free.pt');
    check($spots->countSpots('Beeld', false, ['0:a9', '0:d0', '0:d1']) === 1
        && $spots->countSpots('Beeld', false, ['0:a9', '0:d0', '0:d1', '0:d2']) === 0, 'Alle subcategorieen worden met EN gecombineerd, ook binnen dezelfde dimensie');
    check($spots->countSpots('Beeld', false, ['0:a9', '0:a10']) === 1
        && count($spots->batch('Beeld', 10, false, ['0:a9', '0:a10'], '')['spots']) === 1, 'Ook formaatfilters stapelen met EN in telling en batch');
    $facets = array_column($spots->facets('Beeld', false, '', ['0:a9', '0:d1']), 'total', 'code');
    check((int) $facets['0:d0'] === 1 && (int) $facets['0:a10'] === 1, 'Facetaantallen gebruiken alle actieve filters');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$multi['id']]);
    $columnUser = new \NnSpotty\Users\UserRepository($pdo);
    $columnUser->create('column-fixture', 'fixture-only-delete-test', false);
    $state->savePersonalSpotColumns('column-fixture', [0 => ['title', 'size'], 3 => ['title', 'poster']]);
    $reloadedState = new AppStateRepository($pdo);
    check($reloadedState->personalSpotColumns('column-fixture') === [0 => ['title', 'size'], 3 => ['title', 'poster']]
        && $reloadedState->effectiveSpotColumns('column-fixture', 'Geluid') === $state->globalSpotColumns()[1], 'Overriden en inheriting categorieen blijven na herladen van de state correct opgeslagen');
    rejects(static fn () => $state->savePersonalSpotColumns('column-fixture', [0 => ['title'], 4 => ['title']]), 'Ongeldige hoofdgroep wordt voor een persoonlijke wijziging geweigerd');
    check($state->personalSpotColumns('column-fixture') === [0 => ['title', 'size'], 3 => ['title', 'poster']], 'Ongeldige wijziging wist geen eerdere kolomkeuzes');
    $columnUser->delete('column-fixture');
    check($state->personalSpotColumns('column-fixture') === [], 'Userverwijdering ruimt persoonlijke kolomoverrides via foreign key op');
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
