<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Fixtures.php';

use NnSpotty\Database\Migrator;
use NnSpotty\Http\Request;
use NnSpotty\Newznab\Search;
use NnSpotty\Usenet\SearchCandidates;
use NnSpotty\Usenet\SpotRepository;
use NnSpotty\Usenet\SpotnetParser;

$dsn = getenv('NNSPOTTY_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('Gebruik NNSPOTTY_TEST_DSN met een afzonderlijke MySQL/MariaDB-testserver.');
}
$size = filter_var(getenv('NNSPOTTY_PERF_SPOTS') ?: '10000', FILTER_VALIDATE_INT);
if ($size === false || $size < 1000 || $size > 5000000) {
    throw new InvalidArgumentException('NNSPOTTY_PERF_SPOTS moet tussen 1000 en 5000000 liggen.');
}
$connect = static fn (): PDO => new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo = $connect();
$reuse = getenv('NNSPOTTY_PERF_REUSE') ?: null;
if ($reuse !== null && preg_match('/^nnspotty_perf_[0-9a-f]{12}$/D', $reuse) !== 1) {
    throw new InvalidArgumentException('NNSPOTTY_PERF_REUSE moet een bewaard nnspotty_perf_-schema zijn.');
}
$keep = $reuse !== null || getenv('NNSPOTTY_PERF_KEEP') === '1';
$database = $reuse ?? 'nnspotty_perf_' . bin2hex(random_bytes(6));
if ($reuse === null) {
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
}
$pdo->exec('USE `' . $database . '`');
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ', $message, PHP_EOL;
};
$measure = static function (string $name, callable $query): mixed {
    $start = hrtime(true);
    $result = $query();
    $ms = (hrtime(true) - $start) / 1e6;
    printf("BENCHMARK %s: %.1f ms\n", $name, $ms);
    if ($ms > 2000) {
        throw new RuntimeException($name . ' overschrijdt het testbudget van 2000 ms.');
    }
    return $result;
};

try {
    echo 'Test schema: ', $database, PHP_EOL;
    $spots = new SpotRepository($pdo);
    $api = static fn (array $query = [], bool $adult = false, string $type = 'search'): array =>
        (new Search($pdo))->run(new Request('GET', '/api', $query, [], '127.0.0.1'), $adult, $type);
    $fixture = SpotnetParser::spot('search-template@example.invalid', Fixtures::headers('search-template@example.invalid'));
    if ($reuse === null) {
        $migrator = new Migrator($pdo);
        $migrator->migrate();
        $pdo->exec('ALTER TABLE metadata_search_index DROP INDEX ft_metadata_search,
            ADD INDEX ft_metadata_search (search_text(191))');
        $pdo->exec("DELETE FROM migrations WHERE name = '20261104_0031_search_performance'");
        $migrator->migrate();
        $check($pdo->query("SELECT INDEX_TYPE FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'metadata_search_index'
                AND INDEX_NAME = 'ft_metadata_search' LIMIT 1")->fetchColumn() === 'FULLTEXT',
            'Upgrade vervangt de oude gewone metadata-index door een echte FULLTEXT-index');
        $ids = [];
        foreach ([
            ['Nebula Observatory', ['1a09', '1z00'], ['nzb@example.invalid']],
            ['Unrelated Archive', ['1a09', '1z00'], ['nzb@example.invalid']],
            ['Nebula Observatory', ['1a09', '1z03'], ['nzb@example.invalid']],
            ['Nebula Observatory', ['1a09', '1z00'], []],
            ['Observatory Nebula', ['1a09', '1z00'], ['nzb@example.invalid']],
        ] as $n => [$title, $codes, $segments]) {
            $spot = $fixture;
            $spot['message_id'] = 'search-' . $n . '@example.invalid';
            $spot['id'] = $ids[$n] = hash('sha256', $spot['message_id']);
            $spot['title'] = $title;
            $spot['subcategories'] = $codes;
            $spot['nzb_segments'] = $segments;
            $spots->saveSpot($spot, 'free.pt');
        }
        $pdo->prepare("INSERT INTO metadata_search_index
            (spot_id, media_kind, provider, title, subtitle, genres, people, companies, aliases, search_text, updated_at)
            VALUES (?, 'video', 'fixture', 'Nebula Observatory', '', '', '', '', '', 'Nebula Observatory Astrophysics', UTC_TIMESTAMP(6))")
            ->execute([$ids[1]]);
        $result = $api(['q' => 'Nebula Observatory']);
        $check($result['total'] === 3 && count($result['items']) === 3,
            'API vindt titel- en metadatawoorden, dedupliceert en verbergt erotica en lege NZBs');
        $check($api(['q' => 'Nebula Observatory'], true)['total'] === 4, 'API volgt erotica-toegang');
        $check($api(['q' => 'Archive Astrophysics'])['total'] === 1,
            'API-woorden mogen verdeeld over titel en metadata voorkomen');
        $check($api(['q' => 'Nebula Observatory', 'limit' => '1', 'offset' => '1'])['total'] === 3
            && count($api(['q' => 'Nebula Observatory', 'limit' => '1', 'offset' => '1'])['items']) === 1,
            'API-paginering behoudt de exacte totaaltelling');
        $check($api(['q' => 'Nebula', 'cat' => '2000'])['total'] === 3
            && $api(['q' => 'Nebula'], false, 'tvsearch')['total'] === 0,
            'Newznab-categorie en zoekmodus behouden hun filters');
        $check($spots->countSpots(null, false, [], 'Nebula Observatory') === 3,
            'Webzoeken behoudt aaneengesloten woordvolgorde en bevat ook spots zonder NZB');
        $check(count($spots->batch(null, 50, false, [], 'Astrophysics')['spots']) === 1,
            'Webzoeken vindt metadata-only kandidaten');
        $check(count($spots->facets(null, false, 'Astrophysics')) > 0, 'Metadatazoekfacetten blijven beschikbaar');
        $pdo->prepare("UPDATE metadata_search_index
            SET imdb_id = 'tt1234567', tvdb_id = '12345', tmdb_id = '67890', year = 2026 WHERE spot_id = ?")->execute([$ids[1]]);
        $check($api(['imdbid' => 'tt1234567', 'tvdbid' => '12345', 'tmdbid' => '67890'])['total'] === 1,
            'Externe ID-indexen combineren kandidaten zonder duplicaten');
        $check($api(['year' => '2026'], false, 'movie')['total'] === 1,
            'Filmjaar kan uit metadata komen');
        $pdo->exec('RENAME TABLE metadata_search_index TO saved_metadata_search_index');
        try {
            $check($api(['q' => 'Nebula Observatory'])['total'] === 2,
                'Titelzoeken werkt ook zonder metadata-index');
            $check($api(['year' => '2026'], false, 'movie')['total'] === 0,
                'Filmjaar zonder metadata bindt geen ongebruikte parameter');
        } finally {
            $pdo->exec('RENAME TABLE saved_metadata_search_index TO metadata_search_index');
        }

        // Test an upgrade with existing rows, including direct SQL updates that bypass the repository.
        $pdo->exec('ALTER TABLE spot_browse DROP INDEX idx_browse_nzb_date,
            DROP INDEX idx_browse_nzb_visible_date, DROP COLUMN has_nzb');
        $pdo->exec("DELETE FROM migrations WHERE name = '20261104_0031_search_performance'");
        $migrator->migrate();
        $migrator->migrate();
        $check((int) $pdo->query('SELECT SUM(has_nzb) FROM spot_browse')->fetchColumn() === 4,
            'Migratie berekent NZB-beschikbaarheid voor bestaande spots en is herhaalbaar');
        $check((int) $pdo->query("SELECT COUNT(*) FROM metadata_search_index
            WHERE MATCH(search_text) AGAINST('+Observatory' IN BOOLEAN MODE)")->fetchColumn() === 1,
            'Herbouwde metadata-FULLTEXT behoudt bestaande zoekkandidaten');
        $pdo->prepare("UPDATE spotnet_spots SET nzb_segments = '[]' WHERE id = ?")->execute([$ids[0]]);
        $spots->rebuildBrowseIndex();
        $updated = $api(['q' => 'Nebula Observatory']);
        $check($updated['total'] === 2, 'Herindexeren synchroniseert NZB-beschikbaarheid na handmatige SQL-updates');
        $fixture['message_id'] = 'search-3@example.invalid';
        $fixture['id'] = $ids[3];
        $fixture['title'] = 'Nebula Observatory';
        $fixture['subcategories'] = ['1a09', '1z00'];
        $spots->saveSpot($fixture, 'free.pt', true);
        $check($api(['q' => 'Nebula Observatory'])['total'] === 3, 'Repository-refresh met NZB werkt direct door in de API');

        echo 'Seeding ', $size, " spots...\n";
        $pdo->exec('CREATE TEMPORARY TABLE digits (n INT PRIMARY KEY)');
        $pdo->exec('INSERT INTO digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
        $pdo->exec("INSERT INTO spotnet_spots
            (id, message_id, group_name, title, description, poster, category, subcategories, size, posted_at,
             website, tag, image_url, image_segments, nzb_segments, full_xml, created_at, is_erotica)
            SELECT SHA2(CONCAT('perf-',n),256), CONCAT('perf-',n,'@example.invalid'), 'free.pt',
                CONCAT('Common archive release ',n), REPEAT('Description ',100), 'Fixture', 'Beeld',
                '[\"1a09\",\"1z00\"]', 1000, DATE_SUB('2020-01-01', INTERVAL n SECOND),
                '', '', '', '[]', IF(MOD(n,5)=0,'[]','[\"nzb@example.invalid\"]'), REPEAT('XML ',100),
                UTC_TIMESTAMP(), 0
            FROM (SELECT a.n+b.n*10+c.n*100+d.n*1000+e.n*10000+f.n*100000+g.n*1000000+1 AS n
                FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d
                CROSS JOIN digits e CROSS JOIN digits f CROSS JOIN digits g) numbers WHERE n <= " . $size);
        $spots->rebuildBrowseIndex();
    }
    $pdo->query('ANALYZE TABLE spotnet_spots, spot_browse, metadata_search_index')->fetchAll();

    foreach ([true, false] as $adult) {
        $where = $adult ? '' : ' AND is_erotica = 0';
        $start = hrtime(true);
        $old = (int) $pdo->query('SELECT COUNT(*) FROM spotnet_spots WHERE JSON_LENGTH(nzb_segments) > 0' . $where)->fetchColumn();
        printf("BASELINE JSON count (%s): %.1f ms\n", $adult ? 'all' : 'visible', (hrtime(true) - $start) / 1e6);
        $new = $measure('indexed count ' . ($adult ? 'all' : 'visible'),
            static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM spot_browse WHERE has_nzb = 1' . $where)->fetchColumn());
        $check($new === $old, 'Geindexeerde telling is exact gelijk aan de oude JSON-telling');
        $plan = $pdo->query('EXPLAIN SELECT COUNT(*) FROM spot_browse WHERE has_nzb = 1' . $where)->fetchAll();
        $check(str_contains($plan[0]['Extra'], 'Using index'), 'Telling gebruikt een dekkende index zonder JSON-/tabelscan');
        $pagePlan = $pdo->query('EXPLAIN SELECT spot_id, posted_at FROM spot_browse WHERE has_nzb = 1' . $where
            . ' ORDER BY posted_at DESC, spot_id DESC LIMIT 100')->fetchAll();
        $check(!str_contains($pagePlan[0]['Extra'], 'filesort'), 'API-pagina loopt in indexvolgorde zonder filesort');
        $result = $measure('API unfiltered ' . ($adult ? 'all' : 'visible'), static fn (): array => $api([], $adult));
        $expected = $pdo->query('SELECT id FROM spotnet_spots WHERE JSON_LENGTH(nzb_segments) > 0' . $where
            . ' ORDER BY posted_at DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
        $check(array_column($result['items'], 'id') === $expected, 'API behoudt datum/Message-ID-hash-sortering bij gelijke datums');
    }

    $parameters = [];
    $condition = SearchCandidates::condition('b.spot_id', '+Astrophysics', true, $parameters);
    $explain = $pdo->prepare('EXPLAIN SELECT b.id FROM spot_browse b WHERE ' . $condition);
    $explain->execute($parameters);
    $plan = $explain->fetchAll();
    $check(count(array_filter($plan, static fn (array $row): bool => $row['type'] === 'fulltext')) === 2,
        'Titel- en metadatazoekkandidaten gebruiken beide hun FULLTEXT-index');
    foreach (['Nebula', 'Astrophysics', 'Common', 'Absentword'] as $term) {
        $measure('API ' . $term, static fn (): array => $api(['q' => $term]));
        $measure('web ' . $term, static fn (): array => $spots->batch(null, 50, false, [], $term));
    }

    // A second connection keeps retrieval-style row/index updates uncommitted while readers run.
    $writer = $connect();
    $writer->exec('USE `' . $database . '`');
    $writer->beginTransaction();
    try {
        $importer = new SpotRepository($writer);
        for ($i = 0; $i < 25; $i++) {
            $spot = $fixture;
            $spot['title'] = 'Nebula Observatory';
            $spot['subcategories'] = ['1a09', '1z00'];
            $spot['message_id'] = 'concurrent-' . $i . '@example.invalid';
            $spot['id'] = hash('sha256', $spot['message_id']);
            $importer->saveSpot($spot, 'free.pt');
        }
        $measure('API during retrieve writes', static fn (): array => $api());
        $measure('web during retrieve writes', static fn (): array => $spots->batch(null, 50, false, [], 'Astrophysics'));
        $check($api(['q' => 'Nebula Observatory'])['total'] === 3,
            'Lezers blokkeren niet op een lopende import en zien geen onbevestigde spots');
    } finally {
        $writer->rollBack();
    }
    $check($api(['q' => 'Nebula Observatory'])['total'] === 3, 'Rollback laat zoekresultaten consistent');
    $spots->saveSpot($spot, 'free.pt');
    $check($api(['q' => 'Nebula Observatory'])['total'] === 4,
        'Gecommitte nieuwe imports zijn direct vindbaar met correcte NZB-beschikbaarheid');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$spot['id']]);
    $spots->refreshBrowseCounts();
    echo "Search correctness, query plans and performance checks passed.\n";
} finally {
    if (!$keep) {
        $pdo->exec('DROP DATABASE `' . $database . '`');
    }
}
