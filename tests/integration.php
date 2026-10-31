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
    // Simulates migrations that failed halfway: every schema step must be safe to run again (MariaDB and MySQL 8).
    $pdo->exec('DELETE FROM migrations');
    $migrator->migrate();
    check(count(array_filter($migrator->status(), static fn (array $row): bool => !$row['executed'])) === 0, 'Alle migraties opnieuw uitvoerbaar op bestaand schema');
    $schemaHelper = new \NnSpotty\Database\Schema($pdo);
    check($schemaHelper->hasColumn('sabnzbd_downloads', 'client') && $schemaHelper->hasIndex('sabnzbd_downloads', 'idx_download_client_history')
        && !$schemaHelper->hasIndex('spotnet_spots', 'idx_spotnet_visibility'), 'Schema-helper ziet toegevoegde en verwijderde kolommen/indexen');
    $browseIndex = new \NnSpotty\Usenet\SpotBrowseIndex($pdo);
    $browseIndex->ensureFulltext();
    $browseIndex->ensureFulltext();
    check($schemaHelper->hasIndex('spot_browse', 'ft_browse_title'), 'FULLTEXT-titelindex herhaalbaar zonder IF NOT EXISTS');
    require __DIR__ . '/moderation-integration.php';
    $state = new AppStateRepository($pdo);
    require __DIR__ . '/accounts-integration.php';
    $service = new RetrieveService($pdo, $state);
    require __DIR__ . '/pipeline-integration.php';
    $settings = $state->getUsenetSettings();
    $settings['server'] = '127.0.0.1';
    $settings['port'] = $port;
    $settings['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
    $settings['max_articles_per_group'] = 100;
    $state->saveUsenetSettings($settings);
    $spots = new SpotRepository($pdo);
    require __DIR__ . '/activity-integration.php';
    require __DIR__ . '/activity-http.php';
    require __DIR__ . '/system-http.php';
    require __DIR__ . '/comments-integration.php';
    $log = [];
    $logger = static function (string $message) use (&$log): void {
        $log[] = $message;
    };
    $service = new RetrieveService($pdo, $state);
    check($state->commentRetrievalSettings() === ['mode' => 'lazy', 'retention' => 0], 'Reacties standaard lazy en met spotretentie');
    rejects(static fn () => $state->saveCommentRetrievalSettings(['mode' => 'headers', 'retention' => 0]), 'Onbekende reactiemodus geweigerd');
    rejects(static fn () => $state->saveCommentRetrievalSettings(['mode' => 'lazy', 'retention' => -1]), 'Negatieve reactie-retentie geweigerd');
    rejects(static fn () => $state->saveCommentRetrievalSettings(['mode' => 'lazy', 'retention' => '30']), 'Niet-numerieke reactie-retentie geweigerd');
    check((int) $pdo->query("SELECT COUNT(*) FROM user_groups g LEFT JOIN group_permissions p ON p.group_id = g.id AND p.permission = 'comments.load' WHERE p.group_id IS NULL")->fetchColumn() === 0,
        'Migratie geeft alle bestaande groepen het recht om reacties te laden');
    $state->saveCommentRetrievalSettings(['mode' => 'full', 'retention' => 0]);
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
    $state->saveCommentRetrievalSettings(['mode' => 'lazy', 'retention' => 0]);
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id = 'spot-one.1.2.3@example.invalid'");
    $log = [];
    $lazyRun = $service->run('days', 7, true, $logger, commentsOnly: true);
    $lazyRow = $pdo->query("SELECT spot_id, body, body_status FROM spotnet_comments WHERE message_id = 'spot-one.1.2.3@example.invalid'")->fetch();
    check($lazyRun['inserted'] === 1 && $lazyRow === ['spot_id' => $spotId, 'body' => null, 'body_status' => 'pending']
        && count($spots->comments($spotId)) === 1 && $spots->pendingCommentCount($spotId) === 1
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'tekst_later=1') && str_contains($line, 'bestaand=1'))) === 1
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'alleen headers indexeren'))) === 1,
        'Lazy retrieve slaat alleen een header-stub op zonder artikel te downloaden');
    $service->run('days', 7, true, $logger, commentsOnly: true);
    check($spots->pendingCommentCount($spotId) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM spotnet_comments')->fetchColumn() === 2,
        'Herhaalde lazy retrieve dupliceert stubs niet');
    $loader = new \NnSpotty\Comments\BodyLoader($pdo, $state);
    $loadedBodies = $loader->load($spotId);
    $loadedRow = $pdo->query("SELECT body_status, body FROM spotnet_comments WHERE message_id = 'spot-one.1.2.3@example.invalid'")->fetch();
    check($loadedBodies === ['loaded' => 1, 'unavailable' => 0, 'invalid' => 0, 'remaining' => 0]
        && $loadedRow['body_status'] === 'loaded' && str_contains($loadedRow['body'], 'Begint met een punt')
        && count($spots->comments($spotId)) === 2, 'BodyLoader haalt reactietekst via NNTP op en cachet die');
    check($loader->load($spotId) === ['loaded' => 0, 'unavailable' => 0, 'invalid' => 0, 'remaining' => 0], 'BodyLoader zonder openstaande reacties maakt geen verbinding');
    $service->run('days', 7, true, $logger, commentsOnly: true);
    check($pdo->query("SELECT body_status FROM spotnet_comments WHERE message_id = 'spot-one.1.2.3@example.invalid'")->fetchColumn() === 'loaded',
        'Lazy retrieve overschrijft geladen reactietekst niet met een stub');
    $stubs = [];
    foreach (['gone', 'broken'] as $suffix) {
        $stubs[] = SpotnetParser::commentStub($suffix . '.1.2.3@example.invalid', '', 'X <x@example.invalid>', gmdate('r'));
    }
    $spots->saveComments($stubs);
    $spots->markComments(['gone.1.2.3@example.invalid'], 'unavailable');
    $spots->markComments(['broken.1.2.3@example.invalid'], 'invalid');
    $spots->markComments(['spot-one.1.2.3@example.invalid'], 'invalid');
    check($pdo->query("SELECT GROUP_CONCAT(body_status ORDER BY message_id) FROM spotnet_comments WHERE message_id IN ('gone.1.2.3@example.invalid', 'broken.1.2.3@example.invalid', 'spot-one.1.2.3@example.invalid')")->fetchColumn() === 'invalid,unavailable,loaded',
        'Markeren raakt alleen openstaande stubs, nooit geladen reacties');
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id IN ('gone.1.2.3@example.invalid', 'broken.1.2.3@example.invalid')");
    $oldStub = SpotnetParser::commentStub('old-comment.1.2.3@example.invalid', '', 'X <x@example.invalid>', '1 Jan 2000 00:00:00 +0000');
    $spots->saveComments([$oldStub]);
    $state->saveCommentRetrievalSettings(['mode' => 'lazy', 'retention' => 30]);
    $log = [];
    $commentRetentionRun = $service->run('days', 7, true, $logger, commentsOnly: true);
    check(str_contains($commentRetentionRun['message'], '1 reacties ouder dan de reactie-retentie verwijderd')
        && (int) $pdo->query('SELECT COUNT(*) FROM spotnet_comments')->fetchColumn() === 2
        && count(array_filter($log, static fn (string $line): bool => str_contains($line, 'retentie=30 dagen'))) === 1,
        'Reactie-retentie ruimt oude reacties op zonder recente reacties te raken');
    $state->saveCommentRetrievalSettings(['mode' => 'full', 'retention' => 0]);
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
    $log = [];
    $all = $service->run('all', null, true, $logger);
    check($spots->countSpots(null) === 2 && $all['inserted'] === 0
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
        // Direct SQL bypasses the overview read model, exactly like manual database edits in production.
        $spots->rebuildBrowseIndex();
        $recentCount = $spots->countSpots(null) - $expiredCount;
        $batchCleanupRun = $service->run('since-last', null, true, $logger);
        check($batchCleanupRun['status'] === 'completed'
            && str_contains($batchCleanupRun['message'], $expiredCount . ' spots ouder dan de retentie verwijderd')
            && (int) $pdo->query("SELECT COUNT(*) FROM spotnet_spots WHERE posted_at < '2000-01-02'")->fetchColumn() === 0
            && $spots->countSpots(null) === $recentCount,
            'Een cron-retrieve ruimt alle ' . $expiredCount . ' verlopen spots op en bewaart recente spots');
    }
    $settings['retention'] = 0;
    $settings['retrieve_after'] = null;
    $state->saveUsenetSettings($settings);
    $unlimitedRun = $service->run('all', null, true, $logger);
    check($spots->countSpots(null) === 3 && $unlimitedRun['inserted'] === 1,
        'Retentie nul zonder begindatum haalt oude spots op en verwijdert ze niet');
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
    require __DIR__ . '/browse-visits-integration.php';
    require __DIR__ . '/tvdb-integration.php';
    require __DIR__ . '/tmdb-integration.php';
    require __DIR__ . '/music-integration.php';
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
    $probeSpot = $adult;
    $probeSpot['subcategories'] = ['1z00'];
    for ($n = 1; $n <= 600; $n++) {
        $probeSpot['message_id'] = 'search-probe-' . $n . '@example.invalid';
        $probeSpot['id'] = hash('sha256', $probeSpot['message_id']);
        $probeSpot['title'] = 'Probe fixture ' . $n . ($n % 150 === 1 ? ' %_!' : '');
        $probeSpot['posted_at'] = gmdate('Y-m-d H:i:s', strtotime('2025-01-01 00:00:00 UTC') + $n);
        $spots->saveSpot($probeSpot, 'free.pt');
    }
    $dense = $spots->batch('Beeld', 50, false, [], 'pRoBe fixture');
    $denseNext = $spots->batch('Beeld', 50, false, [], 'pRoBe fixture', $dense['next']);
    $denseExpected = $pdo->query("SELECT id FROM spotnet_spots WHERE title LIKE '%Probe fixture%'
        ORDER BY posted_at DESC, id DESC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    check(array_merge(array_column($dense['spots'], 'id'), array_column($denseNext['spots'], 'id')) === $denseExpected,
        'Geordend zoeken behoudt databasecollatie, sortering en keyset zonder dubbelen');
    $sparse = $spots->batch('Beeld', 10, false, [], '%_!');
    check($sparse['spots'] === [] && $sparse['next'] === null && $spots->countSpots('Beeld', false, [], '%_!') === 0,
        'Zoektekst zonder woorden levert niets op en procent, underscore en uitroepteken werken niet als wildcard');
    check(array_column($spots->listSpots('Beeld', 10, 7, false, [], 'Probe fixture'), 'id') === array_slice($denseExpected, 7, 10),
        'Zoeken met offset behoudt dezelfde gefilterde sorteervolgorde');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'search-probe-%@example.invalid'");
    $phraseSpot = $adult;
    $phraseIds = [];
    foreach (['The Juggler (1953)', 'The.Juggler.1953.1080p', 'The Proud Rebel', 'Juggler in the streets', 'Thesaurus collection', 'Theatre night'] as $n => $title) {
        $phraseSpot['message_id'] = 'phrase-' . $n . '@example.invalid';
        $phraseSpot['id'] = $phraseIds[$title] = hash('sha256', $phraseSpot['message_id']);
        $phraseSpot['title'] = $title;
        $phraseSpot['subcategories'] = $n < 2 ? ['1z00', '1a09'] : ['1z01'];
        $phraseSpot['posted_at'] = gmdate('Y-m-d H:i:s', strtotime('2024-06-01 00:00:00 UTC') + $n);
        $spots->saveSpot($phraseSpot, 'free.pt');
    }
    $found = static fn (string $search): array => array_column($spots->listSpots('Beeld', 50, 0, false, [], $search), 'title');
    check(array_values(array_diff($found('The'), ['Thesaurus collection', 'Theatre night'])) === $found('The')
        && in_array('The Proud Rebel', $found('The'), true) && in_array('Juggler in the streets', $found('the'), true),
        'Zoeken op een los woord matcht hele woorden: "The" vindt geen Thesaurus of Theatre');
    check($found('The Juggler') === ['The.Juggler.1953.1080p', 'The Juggler (1953)'] && $spots->countSpots('Beeld', false, [], 'the juggler') === 2,
        'Zoeken op meerdere woorden vindt de exacte woordgroep, ook in release-namen met punten');
    check($found('Thesaurus') === ['Thesaurus collection'] && $found('juggler streets') === [] && $found('Juggler 1953') === ['The.Juggler.1953.1080p', 'The Juggler (1953)'],
        'Woordgroepen moeten aaneengesloten voorkomen');
    $available = $spots->availableCodes('Beeld', false, ['0:z0'], '', null);
    check(in_array('0:a9', $available, true) && in_array('0:z0', $available, true) && !in_array('0:z1', $available, true),
        'Drill-down toont alleen filters die met de huidige selectie nog resultaten opleveren (Films sluit Series uit)');
    check(!in_array('0:z0', $spots->availableCodes('Beeld', false, [], 'Proud Rebel', null), true)
        && in_array('0:z1', $spots->availableCodes('Beeld', false, [], 'Proud Rebel', null), true),
        'Beschikbare filters volgen ook de zoekterm');
    $cachedBefore = $spots->availableCodes('Beeld', false, [], '', null);
    check($cachedBefore === $spots->availableCodes('Beeld', false, [], '', null)
        && (int) $pdo->query('SELECT COUNT(*) FROM spot_filter_cache')->fetchColumn() >= 1,
        'Filterbeschikbaarheid zonder zoekterm wordt kort in de database gecachet');
    $cacheSpot = $phraseSpot;
    $cacheSpot['message_id'] = 'phrase-cache@example.invalid';
    $cacheSpot['id'] = hash('sha256', $cacheSpot['message_id']);
    $cacheSpot['title'] = 'Filter cache fixture';
    $cacheSpot['subcategories'] = ['1z00', '1d22'];
    $cacheSpot['posted_at'] = '2024-06-01 00:00:30';
    $spots->saveSpot($cacheSpot, 'free.pt');
    check(!in_array('0:d22', $cachedBefore, true) && in_array('0:d22', $spots->availableCodes('Beeld', false, [], '', null), true),
        'Een nieuwe categoriecombinatie is direct zichtbaar ondanks de filtercache');
    $comboCount = static fn (): int => (int) $pdo->query('SELECT COALESCE(SUM(spot_count), 0) FROM spot_combos')->fetchColumn();
    $spots->refreshBrowseCounts();
    $before = $comboCount();
    $deleted = $spots->deleteExpiredSpots('2024-06-01 00:00:02', 1000);
    $afterDelete = $comboCount();
    $spots->refreshBrowseCounts();
    check($deleted >= 2 && $afterDelete === $before - $deleted && $afterDelete === $comboCount()
        && $spots->getSpot($phraseIds['The Juggler (1953)']) === null,
        'Retentieverwijdering verlaagt de combinatietellers in dezelfde transactie');
    $reclassified = $phraseSpot;
    $reclassified['message_id'] = 'phrase-2@example.invalid';
    $reclassified['id'] = $phraseIds['The Proud Rebel'];
    $reclassified['title'] = 'The Proud Rebel';
    $reclassified['subcategories'] = ['1z00'];
    $spots->saveSpot($reclassified, 'free.pt', true);
    $after = $comboCount();
    $spots->refreshBrowseCounts();
    check($after === $comboCount() && $spots->countSpots('Films', false, [], 'Proud Rebel') === 1
        && $spots->countSpots('Series', false, [], 'Proud Rebel') === 0,
        'Herclassificatie verplaatst de spot naar de nieuwe combinatie zonder tellerdrift');
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'phrase-%@example.invalid'");
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
    require __DIR__ . '/retrieve-boundary-integration.php';
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
