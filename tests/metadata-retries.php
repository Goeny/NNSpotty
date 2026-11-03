<?php

declare(strict_types=1);

require __DIR__ . '/../src/Metadata/EnrichmentRepository.php';
require __DIR__ . '/../src/Metadata/Operations.php';
require __DIR__ . '/../src/Metadata/OperationsView.php';

final class RetryStatement extends PDOStatement
{
    public function __construct(private readonly RetryPdo $pdo, private readonly string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->pdo->calls[] = [$this->sql, $params];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function fetchColumn(int $column = 0): mixed { return 1; }
}

final class RetryPdo extends PDO
{
    public array $calls = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RetryStatement($this, $query);
    }
}

function expectRetry(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new RetryPdo();
$repo = new NnSpotty\Metadata\EnrichmentRepository($pdo);
$revision = str_repeat('a', 64);
foreach ([1 => 300, 2 => 600, 3 => 1200, 4 => 2400] as $attempt => $seconds) {
    $start = time();
    $repo->deferError(42, $attempt, 5, 2, 'HTTP 503', $revision);
    [$sql, $parameters] = $pdo->calls[array_key_last($pdo->calls)];
    $delay = strtotime($parameters[2] . ' UTC') - $start;
    expectRetry($delay >= $seconds && $delay <= $seconds + 1 && $parameters[1] === $attempt,
        "Error attempt $attempt waits $seconds seconds, never consumes a cached five-minute error");
}
$start = time();
$repo->deferError(42, 5, 5, 2, 'HTTP 503', $revision);
[$sql, $parameters] = $pdo->calls[array_key_last($pdo->calls)];
expectRetry($parameters[1] === 0 && strtotime($parameters[2] . ' UTC') - $start >= 172800
    && $parameters[2] !== null, 'Retry ceiling resets the streak only after a two-day cooldown, not immediately');
$start = time();
$repo->deferError(42, 6, 10, 1, 'HTTP 503', $revision);
[$sql, $parameters] = $pdo->calls[array_key_last($pdo->calls)];
expectRetry(strtotime($parameters[2] . ' UTC') - $start >= 3600 && strtotime($parameters[2] . ' UTC') - $start <= 3601,
    'Short error backoff is capped at exactly one hour');

foreach (['unmatched' => 7, 'ambiguous' => 30, 'matched' => 0, 'blocked' => 0] as $state => $days) {
    $start = time();
    $repo->recordOutcome(42, $state, $revision, $days);
    [$sql, $parameters] = $pdo->calls[array_key_last($pdo->calls)];
    expectRetry($parameters[0] === $state && $parameters[1] === $revision
        && ($days > 0 ? strtotime($parameters[2] . ' UTC') - $start >= $days * 86400 : $parameters[2] === null),
        "$state persists the correct re-evaluation deadline");
}
$repo->claim(25, $revision);
[$sql, $parameters] = $pdo->calls[count($pdo->calls) - 1];
expectRetry(str_contains($sql, 'ORDER BY s.posted_at DESC, s.id DESC')
    && str_contains($sql, "q.result_state IN ('unmatched', 'ambiguous')")
    && str_contains($sql, 'q.rule_revision <> ?') && $parameters === [$revision],
    'Queue orders newest first and only reopens due/revised negative results');
$operations = new NnSpotty\Metadata\Operations($pdo);
foreach ([
    ['video', 'matched', '2026-01-01', '2026-11-03'],
    ['all', 'error', '2026-11-03', '2026-01-01'],
    ['music', 'unmatched', '2026-02-30', '2026-11-03'],
] as $input) {
    try {
        $operations->bulk(...$input);
        throw new RuntimeException('Invalid bulk selection accepted.');
    } catch (InvalidArgumentException) {
        echo "PASS: invalid bulk selection rejected\n";
    }
}
$row = ['active' => true, 'processed' => 3, 'total' => 25, 'heartbeat_at' => '2026-11-03 14:00:00',
    'current_title' => 'Example', 'started_at' => '2026-11-03 13:59:00', 'finished_at' => null];
expectRetry(str_contains(NnSpotty\Metadata\OperationsView::statusText($row), '3/25'), 'Active indicator reports progress');
$row['active'] = false;
expectRetry(str_contains(NnSpotty\Metadata\OperationsView::statusText($row), 'onderbroken'), 'Disconnected unfinished run is reported as interrupted');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    // Exercise the common queue SQL against a real engine without requiring the application's MySQL test database.
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (int $precision): string => gmdate('Y-m-d H:i:s'), 1);
    $db->exec('CREATE TABLE spotnet_spots (id TEXT PRIMARY KEY, title TEXT, category TEXT, subcategories TEXT, posted_at TEXT)');
    $db->exec('CREATE TABLE metadata_enrichment_queue (id INTEGER PRIMARY KEY, spot_id TEXT, media_kind TEXT,
        status TEXT, attempts INTEGER, result_state TEXT, rule_revision TEXT, next_retry_at TEXT,
        last_error TEXT, started_at TEXT, finished_at TEXT, updated_at TEXT)');
    $spotInsert = $db->prepare("INSERT INTO spotnet_spots VALUES (?, ?, 'Beeld', '[]', ?)");
    $queueInsert = $db->prepare("INSERT INTO metadata_enrichment_queue
        (id, spot_id, media_kind, status, attempts, result_state, rule_revision, next_retry_at) VALUES (?, ?, 'video', ?, 0, ?, ?, ?)");
    $fixtures = [
        [1, '2026-01-01', 'pending', '', $revision, null],
        [2, '2026-01-03', 'done', 'unmatched', $revision, '2000-01-01'],
        [3, '2026-01-05', 'done', 'ambiguous', str_repeat('b', 64), '2099-01-01'],
        [4, '2026-01-07', 'done', 'matched', '', null],
        [5, '2026-01-09', 'done', 'blocked', '', null],
        [6, '2026-01-11', 'failed', 'error', $revision, '2099-01-01'],
        [7, '2026-01-13', 'done', 'unmatched', $revision, '2099-01-01'],
        [8, '2026-01-15', 'failed', 'error', $revision, '2000-01-01'],
    ];
    foreach ($fixtures as [$id, $date, $status, $state, $hash, $retry]) {
        $spotInsert->execute([(string) $id, 'Example ' . $id, $date]);
        $queueInsert->execute([$id, (string) $id, $status, $state, $hash, $retry]);
    }
    $queue = new NnSpotty\Metadata\EnrichmentRepository($db);
    $claimed = $queue->claim(25, $revision);
    expectRetry(array_column($claimed, 'id') === [8, 3, 2, 1],
        'Real queue SQL claims only eligible jobs in exact newest-to-oldest order');
    expectRetry($queue->claim(25, $revision) === [], 'Processing, matched, blocked and not-yet-due jobs are not reclaimed');
    $queue->recordOutcome(3, 'ambiguous', $revision, 30);
    $queue->recordOutcome(2, 'unmatched', $revision, 7);
    expectRetry($queue->claim(25, $revision) === [], 'Reassessed ambiguous job waits for its new deadline');
    $queue->deferError(8, 5, 5, 1, 'HTTP 503', $revision);
    expectRetry($queue->claim(25, $revision) === [], 'Exhausted error is not retried on the next cron run');
    expectRetry(array_column($queue->claim(25, str_repeat('c', 64)), 'id') === [7, 3, 2],
        'Changed revision reopens negative results, never matched, blocked or cooling-down errors');
    require __DIR__ . '/../src/Metadata/MatchRules.php';
    require __DIR__ . '/../src/Music/Release.php';
    require __DIR__ . '/../src/Music/Repository.php';
    $db->exec('CREATE TABLE metadata_match_rules (id INTEGER PRIMARY KEY, media_type TEXT, operation TEXT, term TEXT, enabled INTEGER)');
    $musicSpot = ['id' => '42', 'title' => 'Example Artist - Album (2026)', 'category' => 'Geluid', 'subcategories' => []];
    $music = new NnSpotty\Music\Repository($db);
    $positive = $music->matchSignature($musicSpot);
    $negative = $music->matchSignature($musicSpot, true);
    $db->exec("INSERT INTO metadata_match_rules VALUES (1, 'music', 'remove_word', 'EXTRA', 1)");
    $updated = new NnSpotty\Music\Repository($db);
    expectRetry($updated->matchSignature($musicSpot) === $positive && $updated->matchSignature($musicSpot, true) !== $negative,
        'Rule changes invalidate negative results but keep matched preview signatures usable');
    require __DIR__ . '/../src/Music/Gateway.php';
    require __DIR__ . '/../src/Music/Service.php';
    $db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
    $db->exec('CREATE TABLE music_matches (spot_id TEXT PRIMARY KEY, input_hash TEXT, choice_json TEXT, result_json TEXT, expires_at TEXT)');
    $matched = ['state' => 'matched', 'metadata' => ['title' => 'Album']];
    $db->prepare('INSERT INTO music_matches VALUES (?, ?, NULL, ?, ?)')->execute([
        $musicSpot['id'], $positive, json_encode($matched, JSON_THROW_ON_ERROR), '2099-01-01',
    ]);
    $gateway = new class implements NnSpotty\Music\Gateway {
        public function get(string $type, ?string $id, array $query = []): ?array
        {
            throw new RuntimeException('An existing matched result must not trigger provider requests.');
        }
    };
    expectRetry((new NnSpotty\Music\Service($updated, $gateway))->lookup($musicSpot) === $matched,
        'Matched lookup still serves its real database cache after rules change without provider calls');
}
