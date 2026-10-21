<?php

declare(strict_types=1);

/*
 * Browse benchmark on a disposable database. Seeds realistic Spotnet subcategory mixes and title vocabulary,
 * builds the overview read model through the same code path as the migration and times overview queries.
 *
 *   NNSPOTTY_TEST_DSN='mysql:host=127.0.0.1;port=3306;charset=utf8mb4' NNSPOTTY_PERF_SPOTS=4300000 php tests/browse-performance.php
 */

require __DIR__ . '/../vendor/autoload.php';

use NnSpotty\Database\Migrator;
use NnSpotty\Usenet\SpotRepository;

$dsn = getenv('NNSPOTTY_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('Gebruik NNSPOTTY_TEST_DSN met een afzonderlijke MySQL/MariaDB-testserver.');
}
$size = filter_var(getenv('NNSPOTTY_PERF_SPOTS') ?: '300000', FILTER_VALIDATE_INT);
if ($size === false || $size < 1000 || $size > 5000000) {
    throw new InvalidArgumentException('NNSPOTTY_PERF_SPOTS moet tussen 1000 en 5000000 liggen.');
}
$pdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('SET NAMES utf8mb4');
// NNSPOTTY_PERF_KEEP=1 keeps the seeded schema; NNSPOTTY_PERF_REUSE=<name> times queries on a kept schema without reseeding.
$reuse = getenv('NNSPOTTY_PERF_REUSE') ?: null;
if ($reuse !== null && !preg_match('/^nnspotty_perf_[0-9a-f]{12}$/D', $reuse)) {
    throw new InvalidArgumentException('NNSPOTTY_PERF_REUSE moet een bewaard nnspotty_perf_-schema zijn.');
}
$keep = $reuse !== null || getenv('NNSPOTTY_PERF_KEEP') === '1';
$database = $reuse ?? 'nnspotty_perf_' . bin2hex(random_bytes(6));
if ($reuse === null) {
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
}
$pdo->exec('USE `' . $database . '`');

$words = static fn (array $list): string => implode(',', array_map(static fn (string $word): string => $pdo->quote($word), $list));
$first = $words(['Night', 'Love', 'Dark', 'Last', 'Man', 'City', 'Story', 'House', 'Blood', 'Star', 'War', 'King', 'Girl', 'Road',
    'Dead', 'Life', 'World', 'Time', 'Lost', 'Black', 'Red', 'Wild', 'Secret', 'Power', 'Fire', 'Ocean', 'River', 'Winter',
    'Summer', 'Ghost', 'Dream', 'Shadow', 'Heart', 'Game', 'Street', 'Moon', 'Storm']);
$second = $words(['Rising', 'Returns', 'Chronicles', 'Legacy', 'Hunter', 'Empire', 'Mission', 'Escape', 'Promise', 'Kingdom',
    'Island', 'Machine', 'Runner', 'Code', 'Edge', 'Hour', 'Signal', 'Valley', 'Garden', 'Station', 'Bridge', 'Frontier',
    'Paradise', 'Protocol', 'Prophecy', 'Rebellion', 'Requiem', 'Symphony', 'Tales', 'Odyssey', 'Origins', 'Sanctuary',
    'Horizon', 'Labyrinth', 'Voyage', 'Witness', 'Treasure', 'Zone', 'Theory', 'Thesaurus', 'Theatre']);
$genre = static fn (string $a, string $b): string => "LPAD(LEAST(MOD(FLOOR(n/$a),23), MOD(FLOOR(n/$b),23)),2,'0')";

try {
    $spots = new SpotRepository($pdo);
    if ($reuse === null) {
        (new Migrator($pdo))->migrate();
        printf("Seeding %d synthetic spots...\n", $size);
        $started = microtime(true);
        $pdo->exec('CREATE TEMPORARY TABLE perf_digits (n INT PRIMARY KEY)');
        $pdo->exec('INSERT INTO perf_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
        $pdo->exec('CREATE TEMPORARY TABLE perf_numbers (n INT PRIMARY KEY)
            SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 + g.n*1000000 + 1 AS n
            FROM perf_digits a CROSS JOIN perf_digits b CROSS JOIN perf_digits c
            CROSS JOIN perf_digits d CROSS JOIN perf_digits e CROSS JOIN perf_digits f CROSS JOIN perf_digits g
            WHERE a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 + g.n*1000000 < ' . $size);
        $title = "CASE MOD(n,100003)
                WHEN 11 THEN 'The Juggler (1953)' WHEN 12 THEN 'Juggler in the streets' WHEN 13 THEN 'The.Juggler.1953.1080p'
                WHEN 14 THEN 'The Proud Rebel 1958'
                ELSE IF(MOD(n,4)=0, REPLACE(t,' ','.'), t) END";
        $raw = "CONCAT_WS(' ', IF(MOD(n,3)=0,'The',NULL), ELT(1+MOD(n,37),$first), ELT(1+MOD(FLOOR(n/37),41),$second),
                1950+MOD(n,75), ELT(1+MOD(n,4),'1080p','720p','2160p','DVDRip'))";
        $beeld = "CONCAT('[\"', CASE WHEN MOD(n,20)=0 THEN '1z03' WHEN MOD(n,10) IN (1,2) THEN '1z01' WHEN MOD(n,10)=3 THEN '1z02'
                    WHEN MOD(n,31)=5 THEN '1z04' ELSE '1z00' END,
                '\",\"', ELT(1+MOD(FLOOR(n/3),6),'1a09','1a00','1a06','1a15','1a04','1a10'),
                '\",\"', ELT(1+MOD(FLOOR(n/7),5),'1b11','1b12','1b04','1b03','1b13'), '\"',
                ELT(1+MOD(FLOOR(n/11),4),'',',\"1c03\"',',\"1c02\",\"1c03\"',',\"1c11\"'),
                ',\"1d', " . $genre('13', '17') . ", '\"',
                IF(MOD(n,3)>0, CONCAT(',\"1d', " . $genre('19', '29') . ", '\"'), ''),
                IF(MOD(n,7)=0, CONCAT(',\"1d', " . $genre('31', '37') . ", '\"'), ''), ']')";
        $pdo->exec("INSERT INTO spotnet_spots
            (id, message_id, group_name, title, description, poster, newsreader, category, subcategories, size,
             posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, created_at)
            SELECT SHA2(CONCAT('performance-',n),256), CONCAT('performance-',n,'@example.invalid'), 'free.pt',
                $title, REPEAT('Synthetic description. ',12), 'Fixture', '',
                CASE MOD(n,10) WHEN 7 THEN 'Geluid' WHEN 8 THEN 'Spellen' WHEN 9 THEN 'Applicaties' ELSE 'Beeld' END,
                CASE MOD(n,10)
                    WHEN 7 THEN CONCAT('[\"1a0', MOD(n,9), '\",\"1b00\",\"1c07\",\"1d', LPAD(MOD(FLOOR(n/3),39),2,'0'), '\",\"1z00\"]')
                    WHEN 8 THEN CONCAT('[\"2a0', MOD(n,9), '\",\"2b01\",\"2c', LPAD(MOD(FLOOR(n/3),18),2,'0'), '\"]')
                    WHEN 9 THEN CONCAT('[\"3a0', MOD(n,8), '\",\"3b', LPAD(MOD(FLOOR(n/3),32),2,'0'), '\"]')
                    ELSE $beeld END,
                1000, DATE_SUB('2026-10-01 00:00:00', INTERVAL n * 7 SECOND), '', '', '', '[]', '[]', '',
                DATE_SUB('2026-10-01 00:00:00.000000', INTERVAL n * 7 SECOND)
            FROM (SELECT n, $raw AS t FROM perf_numbers) source");
        printf("Seeded in %.0f s\n", microtime(true) - $started);
        $started = microtime(true);
        $spots->rebuildBrowseIndex(static fn (string $line) => print($line . PHP_EOL));
        printf("Read model rebuild: %.0f s\n", microtime(true) - $started);
    }
    $pdo->query('ANALYZE TABLE spot_browse, spot_combos, spot_combo_codes')->fetchAll();
    printf("Combinations: %d; with codes: %d rows\n", $pdo->query('SELECT COUNT(*) FROM spot_combos')->fetchColumn(),
        $pdo->query('SELECT COUNT(*) FROM spot_combo_codes')->fetchColumn());

    $newSince = $pdo->query("SELECT '2026-09-30 00:00:00.000000'")->fetchColumn();
    $cases = [
        'all' => [null, [], ''],
        'head' => ['Beeld', [], ''],
        'type' => ['Films', [], ''],
        'common-filters' => ['Beeld', ['0:a9', '0:d0'], ''],
        'rare-filters' => ['Beeld', ['0:a10', '0:b13', '0:c11', '0:d20'], ''],
        'impossible-filters' => ['Beeld', ['0:z0', '0:z1'], ''],
        'rare-search' => [null, [], 'Juggler'],
        'phrase-search' => ['Beeld', [], 'The Juggler'],
        'common-search' => ['Beeld', [], 'The'],
        'two-word-search' => ['Beeld', [], 'Ocean Odyssey'],
        'search-filter' => ['Beeld', ['0:a9', '0:d1'], 'Night Code'],
        'no-match-search' => ['Beeld', [], 'absentword'],
    ];
    $slowest = 0.0;
    foreach ($cases as $name => [$category, $filters, $search]) {
        $started = hrtime(true);
        $result = $spots->batch($category, 50, false, $filters, $search);
        $first = (hrtime(true) - $started) / 1e6;
        $nextTime = 0.0;
        $rows = $result['spots'];
        if ($result['next'] !== null) {
            $started = hrtime(true);
            $rows = array_merge($rows, $spots->batch($category, 50, false, $filters, $search, $result['next'])['spots']);
            $nextTime = (hrtime(true) - $started) / 1e6;
        }
        $availability = 0.0;
        $warm = 0.0;
        $available = [];
        if ($category !== null) {
            $started = hrtime(true);
            $available = $spots->availableCodes($category, false, $filters, $search, null);
            $availability = (hrtime(true) - $started) / 1e6;
            $started = hrtime(true);
            $spots->availableCodes($category, false, $filters, $search, null);
            $warm = (hrtime(true) - $started) / 1e6;
        }
        $ids = array_column($rows, 'browse_id');
        $sorted = $rows;
        usort($sorted, static fn (array $a, array $b): int => strcmp($b['posted_at'], $a['posted_at']) ?: $b['browse_id'] <=> $a['browse_id']);
        if (count($ids) !== count(array_unique($ids)) || array_column($sorted, 'browse_id') !== $ids) {
            throw new RuntimeException('Onjuiste keysetvolgorde voor ' . $name);
        }
        if ($name === 'impossible-filters' && ($rows !== [] || in_array('0:z1', $spots->availableCodes('Beeld', false, ['0:z0'], '', null), true))) {
            throw new RuntimeException('Drill-down laat een onmogelijke combinatie toe.');
        }
        if ($name === 'no-match-search' && $rows !== []) {
            throw new RuntimeException('Zoekterm zonder treffers levert resultaten op.');
        }
        printf("%-18s first=%7.1f ms  next=%7.1f ms  filters=%7.1f ms (warm %6.1f)  rows=%3d  available=%d\n",
            $name, $first, $nextTime, $availability, $warm, count($rows), count($available));
        $slowest = max($slowest, $first, $nextTime, $availability);
    }
    foreach (['Juggler' => null, 'The Juggler' => 'Beeld'] as $term => $category) {
        $expected = $pdo->prepare('SELECT title FROM spot_browse b WHERE b.is_erotica = 0' . ($category === null ? '' : ' AND b.category = :category')
            . ' AND b.title REGEXP :pattern ORDER BY b.posted_at DESC, b.id DESC');
        $expected->execute(($category === null ? [] : ['category' => $category])
            + ['pattern' => \NnSpotty\Usenet\SearchTerms::parse($term)->phrasePattern()]);
        $titles = $expected->fetchAll(PDO::FETCH_COLUMN);
        if ($titles !== array_column($spots->listSpots($category, 500, 0, false, [], $term), 'title')
            || $titles === [] || in_array('Juggler in the streets', $term === 'The Juggler' ? $titles : [], true)) {
            throw new RuntimeException('Zoekresultaat wijkt af van een volledige scan voor ' . $term);
        }
        printf("verified %-12s %d whole-word matches\n", $term, count($titles));
    }
    foreach (['idx_browse_date' => '', 'idx_browse_category' => "b.category = 'Beeld' AND "] as $index => $scope) {
        $plan = $pdo->query('EXPLAIN SELECT STRAIGHT_JOIN b.id FROM spot_browse b FORCE INDEX (' . $index . ')
            JOIN spot_combo_codes x0 ON x0.combo_id = b.combo_id AND x0.code = \'0:a9\'
            WHERE ' . $scope . 'b.is_erotica = 0 ORDER BY b.posted_at DESC, b.id DESC LIMIT 51')->fetchAll();
        if (str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'filesort')) {
            throw new RuntimeException('Datumwandeling via ' . $index . ' gebruikt een filesort.');
        }
    }
    printf("Slowest step: %.1f ms\nPHP peak memory: %.1f MiB\n", $slowest, memory_get_peak_usage(true) / 1048576);
} finally {
    if ($keep) {
        printf("Schema bewaard: %s (verwijderen: DROP DATABASE `%s`)\n", $database, $database);
    } else {
        $pdo->exec('DROP DATABASE `' . $database . '`');
    }
}
