<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use PDO;

final class FetchLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{id: string, title: string} $spot
     * @param array{state?: mixed, metadata?: mixed, message?: mixed, warnings?: mixed} $result
     */
    public function record(string $mediaKind, string $source, ?string $username, string $action, array $spot, array $result): void
    {
        $state = is_string($result['state'] ?? null) ? $result['state'] : 'error';
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $provider = is_string($metadata['provider'] ?? null) ? (string) $metadata['provider'] : '';
        $title = is_string($metadata['title'] ?? null) ? trim((string) $metadata['title']) : '';
        $year = null;
        if (is_string($metadata['date'] ?? null) && preg_match('/\b((?:19|20)\d{2})\b/', $metadata['date'], $match) === 1) {
            $year = (int) $match[1];
        }
        $message = is_string($result['message'] ?? null) ? $result['message'] : '';
        if ($message === '' && is_array($result['warnings'] ?? null) && $result['warnings'] !== []) {
            $message = implode(' ', array_filter(array_map(static fn (mixed $warning): string => is_string($warning) ? trim($warning) : '', $result['warnings'])));
        }
        $this->pdo->prepare('INSERT INTO metadata_fetch_logs
            (spot_id, spot_title, media_kind, source, username, action, provider, fetch_state, metadata_title, metadata_year, message, created_at)
            VALUES (:spot_id, :spot_title, :media_kind, :source, :username, :action, :provider, :fetch_state, :metadata_title, :metadata_year, :message, UTC_TIMESTAMP(6))')
            ->execute([
                'spot_id' => $spot['id'],
                'spot_title' => self::truncate($spot['title'], 255),
                'media_kind' => $mediaKind,
                'source' => self::truncate($source, 24),
                'username' => $username !== null ? self::truncate($username, 191) : null,
                'action' => self::truncate($action, 24),
                'provider' => self::truncate($provider, 20),
                'fetch_state' => self::truncate($state, 16),
                'metadata_title' => self::truncate($title, 255),
                'metadata_year' => $year,
                'message' => $message === '' ? null : self::truncate($message, 500),
            ]);
    }

    /**
     * @return list<array{
     *   created_at: string,
     *   media_kind: string,
     *   source: string,
     *   username: ?string,
     *   action: string,
     *   fetch_state: string,
     *   provider: string,
     *   spot_id: string,
     *   spot_title: string,
     *   metadata_title: string,
     *   metadata_year: ?int,
     *   message: ?string
     * }>
     */
    public function recent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query('SELECT DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at,
                media_kind, source, username, action, fetch_state, provider, spot_id, spot_title, metadata_title, metadata_year, message
            FROM metadata_fetch_logs
            ORDER BY id DESC
            LIMIT ' . $limit);
        return array_map(static fn (array $row): array => [
            'created_at' => (string) $row['created_at'],
            'media_kind' => (string) $row['media_kind'],
            'source' => (string) $row['source'],
            'username' => $row['username'] === null ? null : (string) $row['username'],
            'action' => (string) $row['action'],
            'fetch_state' => (string) $row['fetch_state'],
            'provider' => (string) $row['provider'],
            'spot_id' => (string) $row['spot_id'],
            'spot_title' => (string) $row['spot_title'],
            'metadata_title' => (string) $row['metadata_title'],
            'metadata_year' => $row['metadata_year'] === null ? null : (int) $row['metadata_year'],
            'message' => $row['message'] === null ? null : (string) $row['message'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function truncate(string $value, int $max): string
    {
        if ($max < 1 || strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }
}
