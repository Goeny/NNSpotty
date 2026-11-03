<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Operations
{
    private const LOCK = 'nnspotty:metadata-run';
    private ?string $lockName = null;

    public function __construct(private readonly PDO $pdo) {}

    public function policy(): array
    {
        $row = $this->pdo->query('SELECT error_days, unmatched_days, ambiguous_days FROM metadata_retry_policy WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new RuntimeException('Metadata-instellingen ontbreken; voer db migrate uit.');
        return array_map('intval', $row);
    }

    public function savePolicy(int $errors, int $unmatched, int $ambiguous): void
    {
        foreach ([$errors, $unmatched, $ambiguous] as $days) {
            if ($days < 1 || $days > 365) throw new InvalidArgumentException('Kies wachttijden van 1 tot 365 dagen.');
        }
        $this->pdo->prepare('UPDATE metadata_retry_policy SET error_days = ?, unmatched_days = ?, ambiguous_days = ? WHERE id = 1')
            ->execute([$errors, $unmatched, $ambiguous]);
    }

    public function acquire(): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$this->lockName()]);
        $result = $query->fetchColumn();
        if ($result === null || $result === false) throw new RuntimeException('Metadata-runlock kon niet worden aangevraagd.');
        return (int) $result === 1;
    }

    public function begin(int $total): void
    {
        // An interrupted previous run cannot retain ownership after its database connection closes.
        $this->pdo->exec("UPDATE metadata_enrichment_queue SET status = 'pending' WHERE status = 'processing'");
        $this->pdo->prepare('UPDATE metadata_run_state SET started_at = UTC_TIMESTAMP(6), heartbeat_at = UTC_TIMESTAMP(6),
            finished_at = NULL, processed = 0, total = ?, current_title = \'\' WHERE id = 1')->execute([$total]);
    }

    public function progress(int $processed, int $total, string $title): void
    {
        $this->pdo->prepare('UPDATE metadata_run_state SET heartbeat_at = UTC_TIMESTAMP(6), processed = ?, total = ?, current_title = ? WHERE id = 1')
            ->execute([$processed, $total, mb_substr($title, 0, 255)]);
    }

    public function finish(bool $completed = true): void
    {
        try {
            $this->pdo->exec('UPDATE metadata_run_state SET finished_at = ' . ($completed ? 'UTC_TIMESTAMP(6)' : 'NULL')
                . ', heartbeat_at = UTC_TIMESTAMP(6), current_title = \'\' WHERE id = 1');
        } finally {
            $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$this->lockName()]);
            if ((int) $query->fetchColumn() !== 1) throw new RuntimeException('Metadata-runlock kon niet worden vrijgegeven.');
        }
    }

    public function status(): array
    {
        $row = $this->pdo->query('SELECT * FROM metadata_run_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) throw new RuntimeException('Metadata-runstatus ontbreekt; voer db migrate uit.');
        $query = $this->pdo->prepare('SELECT IS_USED_LOCK(?)');
        $query->execute([$this->lockName()]);
        $row['active'] = $query->fetchColumn() !== null;
        return $row;
    }

    private function lockName(): string
    {
        if ($this->lockName === null) {
            $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            if (!is_string($database) || $database === '') throw new RuntimeException('Metadata-run vereist een geselecteerde database.');
            $this->lockName = self::LOCK . ':' . substr(hash('sha256', $database), 0, 32);
        }
        return $this->lockName;
    }

    public function bulk(string $kind, string $state, string $oldest, string $newest): void
    {
        if (!in_array($kind, ['video', 'music', 'all'], true)
            || !in_array($state, ['error', 'unmatched', 'ambiguous', 'all'], true)) {
            throw new InvalidArgumentException('Ongeldig type of resultaat voor bulkrun.');
        }
        foreach ([$oldest, $newest] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Kies geldige datums.');
        }
        if ($oldest > $newest) throw new InvalidArgumentException('Begindatum ligt na einddatum.');
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM metadata_bulk_requests WHERE status = 'pending'")->fetchColumn() >= 10) {
            throw new InvalidArgumentException('Er staan al tien bulkruns klaar; wacht tot cron deze verwerkt.');
        }
        $this->pdo->prepare('INSERT INTO metadata_bulk_requests (media_kind, result_state, oldest_date, newest_date, created_at)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))')->execute([$kind, $state, $oldest, $newest]);
    }

    public function recentBulk(): array
    {
        return $this->pdo->query('SELECT * FROM metadata_bulk_requests ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Seed a bounded chunk per cron run; the cursor is persisted rather than restarting the scan. */
    public function seedBulk(): int
    {
        $bulk = $this->pdo->query("SELECT * FROM metadata_bulk_requests WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($bulk === false) return 0;
        $where = ["s.posted_at >= ?", "s.posted_at < DATE_ADD(?, INTERVAL 1 DAY)",
            "s.category IN ('Beeld', 'Geluid')",
            "(v.choice_json IS NULL AND m.choice_json IS NULL)",
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.result_json, '$.state')), JSON_UNQUOTE(JSON_EXTRACT(m.result_json, '$.state')), '') IN ('error', 'unmatched', 'ambiguous')"];
        $parameters = [$bulk['oldest_date'], $bulk['newest_date']];
        if ($bulk['media_kind'] !== 'all') {
            $where[] = 's.category = ?';
            $parameters[] = $bulk['media_kind'] === 'music' ? 'Geluid' : 'Beeld';
        }
        if ($bulk['result_state'] !== 'all') {
            $where[] = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.result_json, '$.state')), JSON_UNQUOTE(JSON_EXTRACT(m.result_json, '$.state')), '') = ?";
            $parameters[] = $bulk['result_state'];
        }
        if ($bulk['cursor_date'] !== null) {
            $where[] = '(s.posted_at < ? OR (s.posted_at = ? AND s.id < ?))';
            array_push($parameters, $bulk['cursor_date'], $bulk['cursor_date'], $bulk['cursor_id']);
        }
        $query = $this->pdo->prepare('SELECT s.id, s.title, s.category, s.subcategories, s.posted_at FROM spotnet_spots s
            LEFT JOIN tvdb_matches v ON v.spot_id = s.id AND s.category = \'Beeld\'
            LEFT JOIN music_matches m ON m.spot_id = s.id AND s.category = \'Geluid\'
            WHERE ' . implode(' AND ', $where) . ' ORDER BY s.posted_at DESC, s.id DESC LIMIT 500');
        $query->execute($parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("INSERT INTO metadata_enrichment_queue
                (spot_id, media_kind, status, attempts, next_retry_at, created_at, updated_at)
                VALUES (?, ?, 'pending', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE status = 'pending', attempts = 0, next_retry_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)");
            $count = 0;
            foreach ($rows as $spot) {
                $spot['subcategories'] = json_decode($spot['subcategories'], true, 512, JSON_THROW_ON_ERROR);
                $kind = $spot['category'] === 'Geluid' ? 'music' : 'video';
                $insert->execute([$spot['id'], $kind]);
                $count++;
            }
            $last = $rows === [] ? null : $rows[array_key_last($rows)];
            $this->pdo->prepare('UPDATE metadata_bulk_requests SET status = ?, cursor_date = ?, cursor_id = ?, queued = queued + ? WHERE id = ?')
                ->execute([count($rows) < 500 ? 'done' : 'pending', $last['posted_at'] ?? null, $last['id'] ?? null, $count, $bulk['id']]);
            $this->pdo->commit();
            return $count;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
