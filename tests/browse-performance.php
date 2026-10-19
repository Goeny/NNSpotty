<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use NnSpotty\Database\Migrator;
use NnSpotty\Usenet\SpotRepository;

$dsn = getenv('NNSPOTTY_TEST_DSN');
if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('Gebruik NNSPOTTY_TEST_DSN met een afzonderlijke MySQL/MariaDB-testserver.');
}
$size = filter_var(getenv('NNSPOTTY_PERF_SPOTS') ?: '300000', FILTER_VALIDATE_INT);
if ($size === false || $size < 1000 || $size > 2000000) {
    throw new InvalidArgumentException('NNSPOTTY_PERF_SPOTS moet tussen 1000 en 2000000 liggen.');
}
$pdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'nnspotty_perf_' . bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
$pdo->exec('USE `' . $database . '`');

try {
    (new Migrator($pdo))->migrate();
    printf("Seeding %d synthetic spots...\n", $size);
    $pdo->exec('CREATE TEMPORARY TABLE perf_digits (n INT PRIMARY KEY)');
    $pdo->exec('INSERT INTO perf_digits VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)');
    $pdo->exec('CREATE TEMPORARY TABLE perf_numbers (n INT PRIMARY KEY)
        SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 + g.n*1000000 + 1 AS n
        FROM perf_digits a CROSS JOIN perf_digits b CROSS JOIN perf_digits c
        CROSS JOIN perf_digits d CROSS JOIN perf_digits e CROSS JOIN perf_digits f CROSS JOIN perf_digits g
        WHERE a.n + b.n*10 + c.n*100 + d.n*1000 + e.n*10000 + f.n*100000 + g.n*1000000 < ' . $size);
    $pdo->exec("INSERT INTO spotnet_spots
        (id, message_id, group_name, title, description, poster, newsreader, category, subcategories, size,
         posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, browse_type, is_erotica, created_at)
        SELECT SHA2(CONCAT('performance-',n),256), CONCAT('performance-',n,'@example.invalid'), 'free.pt',
            CONCAT('Performance film ',n), REPEAT('Synthetic description. ',12), 'Fixture', '',
            CASE MOD(n,10) WHEN 7 THEN 'Geluid' WHEN 8 THEN 'Spellen' WHEN 9 THEN 'Applicaties' ELSE 'Beeld' END,
            '[\"1a09\"]', 1000, DATE_SUB(UTC_TIMESTAMP(), INTERVAL n SECOND), '', '', '', '[]', '[]', '',
            CASE MOD(n,10) WHEN 6 THEN 'Erotica' WHEN 7 THEN 'Muziek' WHEN 8 THEN 'Spellen'
                WHEN 9 THEN 'Applicaties' WHEN 5 THEN 'Series' ELSE 'Films' END,
            MOD(n,10)=6, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL n SECOND)
        FROM perf_numbers");
    echo "Indexing synthetic facets...\n";
    $pdo->exec("INSERT INTO spotnet_spot_categories (spot_id,code)
        SELECT id, CONCAT(CASE category WHEN 'Beeld' THEN '0' WHEN 'Geluid' THEN '1' WHEN 'Spellen' THEN '2' ELSE '3' END, ':a9')
        FROM spotnet_spots ORDER BY id");
    foreach (['b3', 'c0', 'd0', 'z0'] as $code) {
        $pdo->exec("INSERT INTO spotnet_spot_categories (spot_id,code)
            SELECT id, '0:$code' FROM spotnet_spots WHERE category = 'Beeld' ORDER BY id");
    }
    $pdo->query('ANALYZE TABLE spotnet_spots, spotnet_spot_categories')->fetchAll();
    echo 'Synthetic spots: ' . $pdo->query('SELECT COUNT(*) FROM spotnet_spots')->fetchColumn() . PHP_EOL;
    $spots = new SpotRepository($pdo);
    $newSince = $pdo->query('SELECT DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY)')->fetchColumn();
    $cases = [
        'all' => static fn (): array => $spots->batch(null, 50, false, [], ''),
        'head' => static fn (): array => $spots->batch('Beeld', 50, false, [], ''),
        'type' => static fn (): array => $spots->batch('Films', 50, false, [], ''),
        'filters' => static fn (): array => $spots->batch('Beeld', 50, false, ['0:a9', '0:d0'], ''),
        'title-search' => static fn (): array => $spots->batch('Beeld', 50, false, [], 'film 123'),
        'dense-search' => static fn (): array => $spots->batch('Beeld', 50, false, [], 'Performance'),
        'empty-search' => static fn (): array => $spots->batch('Beeld', 50, false, [], 'absent-title'),
        'new-facets' => static fn (): array => $spots->facets('Beeld', false, '', [], $newSince),
    ];
    foreach ($cases as $name => $query) {
        $before = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_prev','Sort_rows')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $started = hrtime(true);
        $result = $query();
        $milliseconds = (hrtime(true) - $started) / 1000000;
        if (isset($result['spots'])) {
            if (count($result['spots']) > 50 || ($name === 'empty-search' && $result['spots'] !== [])) {
                throw new RuntimeException('Onjuiste batchgrootte voor ' . $name);
            }
            if ($name !== 'empty-search' && $name !== 'title-search' && count($result['spots']) !== 50) {
                throw new RuntimeException('Onvolledige eerste batch voor ' . $name);
            }
            foreach ($result['spots'] as $row) {
                if (array_key_exists('description', $row) || array_key_exists('full_xml', $row)
                    || $row['browse_type'] === 'Erotica'
                    || ($name !== 'all' && $row['category'] !== 'Beeld')) {
                    throw new RuntimeException('Onjuiste projectie of zichtbaarheid voor ' . $name);
                }
            }
        }
        $after = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_next','Handler_read_prev','Sort_rows')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $reads = (int) $after['Handler_read_next'] + (int) $after['Handler_read_prev']
            - (int) $before['Handler_read_next'] - (int) $before['Handler_read_prev'];
        $sorts = (int) $after['Sort_rows'] - (int) $before['Sort_rows'];
        if (in_array($name, ['all', 'head', 'type'], true) && $reads > 512) {
            throw new RuntimeException('Gewone eerste batch leest meer dan 512 indexstappen: ' . $name);
        }
        if ($name === 'new-facets') {
            $expectedQuery = $pdo->prepare("SELECT COUNT(*) FROM spotnet_spots
                WHERE category = 'Beeld' AND is_erotica = 0 AND created_at > :new_since");
            $expectedQuery->execute(['new_since' => $newSince]);
            $expectedTotal = (int) $expectedQuery->fetchColumn();
            $counts = array_map('intval', array_column($result, 'total', 'code'));
            if ($counts !== array_fill_keys(['0:a9', '0:b3', '0:c0', '0:d0', '0:z0'], $expectedTotal)) {
                throw new RuntimeException('Nieuwtellers komen niet overeen met de synthetische index.');
            }
        }
        printf("%s: %.1f ms; index_steps=%d; sorted_rows=%d; results=%d\n",
            $name, $milliseconds, $reads, $sorts, count($result['spots'] ?? $result));
        if (isset($result['next'])) {
            $started = hrtime(true);
            $next = $spots->batch($name === 'all' ? null : ($name === 'type' ? 'Films' : 'Beeld'),
                50, false, $name === 'filters' ? ['0:a9', '0:d0'] : [],
                $name === 'title-search' ? 'film 123' : ($name === 'dense-search' ? 'Performance' : ''), $result['next']);
            printf("%s-next: %.1f ms; results=%d\n", $name, (hrtime(true) - $started) / 1000000, count($next['spots']));
            $rows = array_merge($result['spots'], $next['spots']);
            $ids = array_column($rows, 'id');
            $sorted = $rows;
            usort($sorted, static fn (array $a, array $b): int => strcmp($b['posted_at'], $a['posted_at']) ?: strcmp($b['id'], $a['id']));
            if (count($ids) !== count(array_unique($ids)) || array_column($sorted, 'id') !== $ids) {
                throw new RuntimeException('Onjuiste keysetvolgorde voor ' . $name);
            }
        }
    }
    foreach (['film 123', 'absent-title', 'Performance'] as $term) {
        $query = $pdo->prepare('SELECT s.id, title, category, browse_type, subcategories, poster, size, posted_at, image_url, image_segments
            FROM spotnet_spots s
            WHERE s.is_erotica = 0 AND (s.browse_type = :type OR s.category = :category) AND s.title LIKE :search
            ORDER BY s.posted_at DESC, s.id DESC LIMIT 51');
        $started = hrtime(true);
        $query->execute(['type' => 'Beeld', 'category' => 'Beeld', 'search' => '%' . $term . '%']);
        $result = $query->fetchAll();
        printf("legacy-query %s: %.1f ms; results=%d\n", $term, (hrtime(true) - $started) / 1000000, count($result));
        if (array_column($result, 'id') !== array_column($spots->listSpots('Beeld', 51, 0, false, [], $term), 'id')) {
            throw new RuntimeException('Zoekoptimalisatie wijzigt de resultaten voor ' . $term);
        }
    }
    $peak = memory_get_peak_usage(true);
    printf("PHP peak memory: %.1f MiB\n", $peak / 1048576);
    if ($peak > 32 * 1048576) {
        throw new RuntimeException('Browsefixture overschrijdt de geheugenlimiet van 32 MiB.');
    }
} finally {
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
