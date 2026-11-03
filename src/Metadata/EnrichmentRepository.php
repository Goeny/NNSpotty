<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use PDO;

final class EnrichmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{enabled: bool, start_at: ?string, batch_size: int, max_retries: int} */
    public function settings(): array
    {
        $row = $this->pdo->query('SELECT enabled, start_at, batch_size, max_retries FROM metadata_enrichment_settings WHERE id = 1')->fetch();
        if ($row === false) {
            throw new \RuntimeException('Metadata-verrijkingsinstellingen ontbreken. Voer db migrate uit.');
        }
        return [
            'enabled' => (bool) $row['enabled'],
            'start_at' => $row['start_at'] === null ? null : (string) $row['start_at'],
            'batch_size' => max(1, min(500, (int) $row['batch_size'])),
            'max_retries' => max(1, min(20, (int) $row['max_retries'])),
        ];
    }

    public function configure(bool $enabled, int $batchSize, int $maxRetries, bool $resetStart): void
    {
        if ($batchSize < 1 || $batchSize > 500) {
            throw new \InvalidArgumentException('Batchgrootte moet tussen 1 en 500 liggen.');
        }
        if ($maxRetries < 1 || $maxRetries > 20) {
            throw new \InvalidArgumentException('Max retries moet tussen 1 en 20 liggen.');
        }
        $this->pdo->prepare('UPDATE metadata_enrichment_settings
            SET enabled = ?, batch_size = ?, max_retries = ?, start_at = IF(?, UTC_TIMESTAMP(6), start_at)
            WHERE id = 1')->execute([(int) $enabled, $batchSize, $maxRetries, (int) $resetStart]);
    }

    public function enqueue(string $spotId, string $mediaKind): void
    {
        if (!in_array($mediaKind, ['video', 'music'], true)) {
            throw new \InvalidArgumentException('Onbekend metadatatype voor queue: ' . $mediaKind);
        }
        $this->pdo->prepare('INSERT INTO metadata_enrichment_queue
            (spot_id, media_kind, status, attempts, next_retry_at, last_error, created_at, updated_at)
            SELECT ?, ?, \'pending\', 0, UTC_TIMESTAMP(6), NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)
            FROM metadata_enrichment_settings s
            WHERE s.id = 1 AND s.enabled = 1 AND s.start_at IS NOT NULL AND UTC_TIMESTAMP(6) >= s.start_at
            ON DUPLICATE KEY UPDATE
                media_kind = VALUES(media_kind),
                status = IF(status = \'done\', status, \'pending\'),
                next_retry_at = IF(status = \'done\', next_retry_at, UTC_TIMESTAMP(6)),
                updated_at = UTC_TIMESTAMP(6)')
            ->execute([$spotId, $mediaKind]);
    }

    public function enqueueBackfill(int $limit, ?string $oldestDate): int
    {
        $limit = max(1, min(500, $limit));
        $where = 'q.spot_id IS NULL AND s.category IN (\'Beeld\', \'Geluid\')';
        $parameters = [];
        if ($oldestDate !== null) {
            $where .= ' AND s.posted_at >= :oldest_date';
            $parameters['oldest_date'] = $oldestDate . ' 00:00:00';
        }
        $batchSize = max(50, min(2000, $limit * 4));
        $scanCap = max($batchSize, min(50000, $limit * 200));
        $offset = 0;
        $insert = $this->pdo->prepare('INSERT INTO metadata_enrichment_queue
            (spot_id, media_kind, status, attempts, next_retry_at, last_error, created_at, updated_at)
            VALUES (?, ?, \'pending\', 0, UTC_TIMESTAMP(6), NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE
                media_kind = VALUES(media_kind),
                status = IF(status = \'done\', status, \'pending\'),
                next_retry_at = IF(status = \'done\', next_retry_at, UTC_TIMESTAMP(6)),
                updated_at = UTC_TIMESTAMP(6)');

        $inserted = 0;
        while ($inserted < $limit && $offset < $scanCap) {
            $select = $this->pdo->prepare('SELECT s.id, s.title, s.category, s.subcategories
                FROM spotnet_spots s
                LEFT JOIN metadata_enrichment_queue q ON q.spot_id = s.id
                WHERE ' . $where . '
                ORDER BY s.posted_at DESC, s.id DESC
                LIMIT ' . $batchSize . ' OFFSET ' . $offset);
            $select->execute($parameters);
            $rows = $select->fetchAll();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $spot = [
                    'id' => (string) $row['id'],
                    'title' => (string) $row['title'],
                    'category' => (string) $row['category'],
                    'subcategories' => json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR),
                ];
                $mediaKind = \NnSpotty\Music\Release::parse($spot) !== null
                    ? 'music'
                    : (Release::parse($spot) !== null ? 'video' : null);
                if ($mediaKind === null) {
                    continue;
                }
                $insert->execute([$spot['id'], $mediaKind]);
                if ($insert->rowCount() > 0) {
                    $inserted++;
                }
                if ($inserted >= $limit) {
                    break;
                }
            }
            $offset += count($rows);
        }
        return $inserted;
    }

    /**
     * @return list<array{id: int, spot_id: string, media_kind: string, attempts: int, spot: array{id: string, title: string, category: string, subcategories: array}}>
     */
    public function claim(int $limit, string $revision = ''): array
    {
        $limit = max(1, min(500, $limit));
        $select = $this->pdo->prepare('SELECT q.id, q.spot_id, q.media_kind, q.attempts, s.title, s.category, s.subcategories
            FROM metadata_enrichment_queue q
            JOIN spotnet_spots s ON s.id = q.spot_id
            WHERE ((q.status IN (\'pending\', \'failed\') AND (q.next_retry_at IS NULL OR q.next_retry_at <= UTC_TIMESTAMP(6)))
                OR (q.status = \'done\' AND q.result_state IN (\'unmatched\', \'ambiguous\')
                    AND (q.next_retry_at <= UTC_TIMESTAMP(6) OR q.rule_revision <> ?)))
            ORDER BY s.posted_at DESC, s.id DESC LIMIT ' . $limit);
        $select->execute([$revision]);
        $rows = $select->fetchAll();
        $claimed = [];
        $claim = $this->pdo->prepare('UPDATE metadata_enrichment_queue
            SET status = \'processing\', attempts = attempts + 1, started_at = UTC_TIMESTAMP(6),
                updated_at = UTC_TIMESTAMP(6), last_error = NULL
            WHERE id = ? AND status <> \'processing\'');
        foreach ($rows as $row) {
            $claim->execute([(int) $row['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $claimed[] = [
                'id' => (int) $row['id'],
                'spot_id' => (string) $row['spot_id'],
                'media_kind' => (string) $row['media_kind'],
                'attempts' => (int) $row['attempts'] + 1,
                'spot' => [
                    'id' => (string) $row['spot_id'],
                    'title' => (string) $row['title'],
                    'category' => (string) $row['category'],
                    'subcategories' => json_decode((string) $row['subcategories'], true, 512, JSON_THROW_ON_ERROR),
                ],
            ];
        }
        return $claimed;
    }

    public function complete(int $id, string $state, ?string $error = null, ?string $retryAfter = null): void
    {
        if ($retryAfter !== null) {
            $this->pdo->prepare('UPDATE metadata_enrichment_queue
                SET status = \'failed\', next_retry_at = ?, last_error = ?, finished_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
                WHERE id = ?')->execute([$retryAfter, self::truncate($error ?? 'Tijdelijke fout.', 500), $id]);
            return;
        }

        $this->pdo->prepare('UPDATE metadata_enrichment_queue
            SET status = ?, next_retry_at = NULL, last_error = ?, finished_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
            WHERE id = ?')->execute([$state, $error === null ? null : self::truncate($error, 500), $id]);
    }

    public function recordOutcome(int $id, string $state, string $revision, int $days): void
    {
        $retry = in_array($state, ['unmatched', 'ambiguous'], true) ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        $this->pdo->prepare('UPDATE metadata_enrichment_queue SET status = \'done\', result_state = ?, rule_revision = ?, next_retry_at = ?,
            attempts = 0, last_error = NULL, finished_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
            WHERE id = ?')->execute([$state, $revision, $retry, $id]);
    }

    public function deferError(int $id, int $attempt, int $maximum, int $days, string $message, string $revision): void
    {
        $exhausted = $attempt >= $maximum;
        $seconds = $exhausted ? $days * 86400 : min(3600, (int) (300 * (2 ** min(10, max(0, $attempt - 1)))));
        $this->pdo->prepare('UPDATE metadata_enrichment_queue SET status = \'failed\', result_state = \'error\',
            rule_revision = ?, attempts = ?, next_retry_at = ?, last_error = ?, finished_at = UTC_TIMESTAMP(6),
            updated_at = UTC_TIMESTAMP(6) WHERE id = ?')
            ->execute([$revision, $exhausted ? 0 : $attempt, gmdate('Y-m-d H:i:s', time() + $seconds), self::truncate($message, 500), $id]);
    }

    /** @return array{pending: int, processing: int, done: int, failed: int} */
    public function queueCounts(): array
    {
        $result = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM metadata_enrichment_queue GROUP BY status')->fetchAll() as $row) {
            if (isset($result[$row['status']])) {
                $result[$row['status']] = (int) $row['n'];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $spot @param array<string, mixed> $result */
    public function syncFromResult(array $spot, string $mediaKind, array $result): void
    {
        if (($result['state'] ?? '') !== 'matched' || !is_array($result['metadata'] ?? null)) {
            $this->clearIndex((string) $spot['id']);
            return;
        }
        $metadata = $result['metadata'];
        $row = $mediaKind === 'music' ? $this->flattenMusic($spot, $metadata) : $this->flattenVideo($spot, $metadata);
        $this->pdo->prepare('INSERT INTO metadata_search_index
            (spot_id, media_kind, provider, title, subtitle, year, genres, people, companies, aliases, imdb_id, tvdb_id, tmdb_id, search_text, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE
                media_kind = VALUES(media_kind), provider = VALUES(provider), title = VALUES(title), subtitle = VALUES(subtitle),
                year = VALUES(year), genres = VALUES(genres), people = VALUES(people), companies = VALUES(companies),
                aliases = VALUES(aliases), imdb_id = VALUES(imdb_id), tvdb_id = VALUES(tvdb_id), tmdb_id = VALUES(tmdb_id),
                search_text = VALUES(search_text), updated_at = UTC_TIMESTAMP(6)')
            ->execute([
                $spot['id'], $mediaKind, $row['provider'], $row['title'], $row['subtitle'], $row['year'], $row['genres'],
                $row['people'], $row['companies'], $row['aliases'], $row['imdb_id'], $row['tvdb_id'], $row['tmdb_id'], $row['search_text'],
            ]);
        $this->syncEntities((string) $spot['id'], $row['entities']);
    }

    /**
     * @param list<array<string, mixed>> $spots
     * @return list<array<string, mixed>>
     */
    public function attachSummaries(array $spots, bool $videoAllowed, bool $musicAllowed): array
    {
        if ($spots === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn (array $spot): string => (string) $spot['id'], $spots)));
        $statement = $this->pdo->prepare('SELECT spot_id, media_kind, provider, title, subtitle, year, genres FROM metadata_search_index
            WHERE spot_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $statement->execute($ids);
        $index = [];
        foreach ($statement->fetchAll() as $row) {
            $index[(string) $row['spot_id']] = [
                'media_kind' => (string) $row['media_kind'],
                'provider' => strtoupper((string) $row['provider']),
                'title' => (string) $row['title'],
                'artist' => $row['media_kind'] === 'music' ? trim((string) $row['subtitle']) : '',
                'year' => $row['year'] === null ? null : (int) $row['year'],
                'genres' => array_values(array_filter(array_map('trim', explode(',', (string) $row['genres'])))),
            ];
        }
        $cached = $this->matchedMetadataBySpotIds($ids);
        foreach ($spots as &$spot) {
            $summary = $index[$spot['id']] ?? null;
            $cachedMatch = $cached[$spot['id']] ?? null;
            if ($summary === null && $cachedMatch !== null) {
                try {
                    $this->syncFromResult(['id' => (string) $spot['id'], 'title' => (string) $spot['title']], $cachedMatch['media_kind'], $cachedMatch['result']);
                } catch (\Throwable $exception) {
                    error_log('Metadata-index hydration (' . $spot['id'] . '): ' . $exception->getMessage());
                }
                $summary = self::summaryFromResult($cachedMatch['media_kind'], $cachedMatch['result']);
            }
            if ($summary === null) {
                continue;
            }
            if (($summary['media_kind'] === 'video' && !$videoAllowed) || ($summary['media_kind'] === 'music' && !$musicAllowed)) {
                continue;
            }
            $spot['metadata_summary'] = $summary;
            $metadata = is_array($cachedMatch) && is_array($cachedMatch['result']['metadata'] ?? null) ? $cachedMatch['result']['metadata'] : null;
            $poster = '';
            if (is_array($metadata)) {
                $poster = $summary['media_kind'] === 'music'
                    ? (string) ($metadata['cover'] ?? '')
                    : (string) (($metadata['poster'] ?? '') ?: ($metadata['still'] ?? ''));
            }
            if ($poster !== '' && preg_match('~^https?://~i', $poster) === 1) {
                $spot['metadata_poster'] = $poster;
            }
        }
        unset($spot);
        return $spots;
    }

    /** @param list<string> $ids
     *  @return array<string, array{media_kind: string, result: array<string, mixed>}>
     */
    private function matchedMetadataBySpotIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholder = implode(',', array_fill(0, count($ids), '?'));
        $queries = [
            'video' => 'SELECT spot_id, result_json FROM tvdb_matches WHERE spot_id IN (' . $placeholder . ') AND expires_at > UTC_TIMESTAMP()',
            'music' => 'SELECT spot_id, result_json FROM music_matches WHERE spot_id IN (' . $placeholder . ') AND expires_at > UTC_TIMESTAMP()',
        ];
        $result = [];
        foreach ($queries as $kind => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($ids);
            foreach ($statement->fetchAll() as $row) {
                try {
                    $decoded = is_string($row['result_json'] ?? null) ? json_decode($row['result_json'], true, 64, JSON_THROW_ON_ERROR) : null;
                } catch (\Throwable) {
                    continue;
                }
                if (!is_array($decoded) || ($decoded['state'] ?? '') !== 'matched' || !is_array($decoded['metadata'] ?? null)) {
                    continue;
                }
                $result[(string) $row['spot_id']] = ['media_kind' => $kind, 'result' => $decoded];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $result
     *  @return array{media_kind: string, provider: string, title: string, artist: string, year: ?int, genres: list<string>}|null
     */
    private static function summaryFromResult(string $mediaKind, array $result): ?array
    {
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : null;
        if ($metadata === null) {
            return null;
        }
        $title = trim((string) ($metadata['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $year = null;
        if (is_int($metadata['year'] ?? null)) {
            $year = (int) $metadata['year'];
        } elseif (preg_match('/\b((?:19|20)\d{2})\b/', (string) ($metadata['date'] ?? ''), $match) === 1) {
            $year = (int) $match[1];
        }
        $genres = [];
        foreach (($metadata['genres'] ?? []) as $genre) {
            if (is_string($genre) && trim($genre) !== '') {
                $genres[] = trim($genre);
            }
        }
        return [
            'media_kind' => $mediaKind,
            'provider' => strtoupper((string) ($metadata['provider'] ?? ($mediaKind === 'music' ? 'musicbrainz' : 'tvdb'))),
            'title' => $title,
            'artist' => $mediaKind === 'music' ? trim((string) ($metadata['artist'] ?? '')) : '',
            'year' => $year,
            'genres' => $genres,
        ];
    }

    private function clearIndex(string $spotId): void
    {
        $this->pdo->prepare('DELETE FROM metadata_search_index WHERE spot_id = ?')->execute([$spotId]);
        $this->pdo->prepare('DELETE FROM spot_metadata_entities WHERE spot_id = ?')->execute([$spotId]);
    }

    /** @return array{provider: string, title: string, subtitle: string, year: ?int, genres: string, people: string, companies: string, aliases: string, imdb_id: string, tvdb_id: string, tmdb_id: string, search_text: string, entities: list<array{type: string, key: string, name: string}>} */
    private function flattenVideo(array $spot, array $metadata): array
    {
        $title = self::truncate((string) ($metadata['title'] ?? $spot['title']), 255);
        $subtitle = self::truncate((string) ($metadata['episode_title'] ?? ''), 255);
        $genres = self::textList($metadata['genres'] ?? [], 30);
        $companiesRaw = [];
        foreach (($metadata['companies'] ?? []) as $company) {
            if (is_array($company) && is_string($company['name'] ?? null)) {
                $companiesRaw[] = trim($company['name']);
            }
        }
        $companiesRaw = array_values(array_unique(array_filter($companiesRaw)));
        $peopleRaw = [];
        $entities = [];
        foreach (($metadata['people'] ?? []) as $person) {
            if (!is_array($person) || !is_string($person['name'] ?? null)) {
                continue;
            }
            $name = trim($person['name']);
            if ($name === '') {
                continue;
            }
            $peopleRaw[] = $name;
            $type = strtolower((string) ($person['type'] ?? ''));
            $role = strtolower((string) ($person['role'] ?? ''));
            $entityType = str_contains($type, 'actor') || str_contains($role, 'actor') ? 'actor'
                : (str_contains($type, 'director') || str_contains($role, 'director') ? 'director' : 'person');
            $entities[] = ['type' => $entityType, 'key' => self::entityKey($name), 'name' => self::truncate($name, 191)];
        }
        foreach ($companiesRaw as $company) {
            $entities[] = ['type' => 'studio', 'key' => self::entityKey($company), 'name' => self::truncate($company, 191)];
        }
        $peopleRaw = array_values(array_unique(array_filter($peopleRaw)));
        $aliases = self::textList(array_filter([$metadata['episode_title'] ?? '', $metadata['type'] ?? '']), 6);
        $overview = self::truncate((string) ($metadata['overview'] ?? ''), 4000);
        $year = null;
        if (preg_match('/\b((?:19|20)\d{2})\b/', (string) ($metadata['date'] ?? ''), $match) === 1) {
            $year = (int) $match[1];
        }
        $provider = (string) ($metadata['provider'] ?? 'tvdb');
        $tvdbId = '';
        $tmdbId = '';
        if ($provider === 'tvdb') {
            $tvdbId = (string) (($metadata['tvdb_id'] ?? $metadata['id'] ?? $metadata['parent_id'] ?? '') ?: '');
        } elseif ($provider === 'tmdb') {
            $tmdbId = (string) (($metadata['tmdb_id'] ?? $metadata['id'] ?? $metadata['parent_id'] ?? '') ?: '');
            $tvdbId = (string) (($metadata['tvdb_id'] ?? '') ?: '');
        }
        return [
            'provider' => in_array($provider, ['tvdb', 'tmdb'], true) ? $provider : 'tvdb',
            'title' => $title,
            'subtitle' => $subtitle,
            'year' => $year,
            'genres' => self::truncate(implode(', ', $genres), 2000),
            'people' => self::truncate(implode(', ', $peopleRaw), 4000),
            'companies' => self::truncate(implode(', ', $companiesRaw), 2000),
            'aliases' => self::truncate(implode(', ', $aliases), 1000),
            'imdb_id' => self::truncate((string) ($metadata['imdb_id'] ?? ''), 16),
            'tvdb_id' => self::truncate($tvdbId, 16),
            'tmdb_id' => self::truncate($tmdbId, 16),
            'search_text' => self::truncate(implode("\n", array_filter([
                $title, $subtitle, implode(', ', $genres), implode(', ', $peopleRaw), implode(', ', $companiesRaw),
                $overview, (string) ($spot['title'] ?? ''),
            ])), 16000),
            'entities' => self::uniqueEntities($entities),
        ];
    }

    /** @return array{provider: string, title: string, subtitle: string, year: ?int, genres: string, people: string, companies: string, aliases: string, imdb_id: string, tvdb_id: string, tmdb_id: string, search_text: string, entities: list<array{type: string, key: string, name: string}>} */
    private function flattenMusic(array $spot, array $metadata): array
    {
        $genres = self::textList($metadata['genres'] ?? [], 30);
        $labels = self::textList($metadata['labels'] ?? [], 20);
        $artist = trim((string) ($metadata['artist'] ?? ''));
        $title = self::truncate((string) ($metadata['title'] ?? $spot['title']), 255);
        $year = null;
        if (preg_match('/\b((?:19|20)\d{2})\b/', (string) ($metadata['date'] ?? ''), $match) === 1) {
            $year = (int) $match[1];
        }
        $entities = [];
        if ($artist !== '') {
            $entities[] = ['type' => 'artist', 'key' => self::entityKey($artist), 'name' => self::truncate($artist, 191)];
        }
        foreach ($labels as $label) {
            $entities[] = ['type' => 'studio', 'key' => self::entityKey($label), 'name' => self::truncate($label, 191)];
        }
        return [
            'provider' => 'musicbrainz',
            'title' => $title,
            'subtitle' => self::truncate($artist, 255),
            'year' => $year,
            'genres' => self::truncate(implode(', ', $genres), 2000),
            'people' => self::truncate($artist, 1000),
            'companies' => self::truncate(implode(', ', $labels), 2000),
            'aliases' => self::truncate((string) ($metadata['kind'] ?? ''), 1000),
            'imdb_id' => '',
            'tvdb_id' => '',
            'tmdb_id' => '',
            'search_text' => self::truncate(implode("\n", array_filter([
                $title, $artist, (string) ($metadata['kind'] ?? ''), (string) ($metadata['country'] ?? ''),
                (string) ($metadata['note'] ?? ''), implode(', ', $genres), implode(', ', $labels), (string) ($spot['title'] ?? ''),
            ])), 16000),
            'entities' => self::uniqueEntities($entities),
        ];
    }

    /** @param list<array{type: string, key: string, name: string}> $entities */
    private function syncEntities(string $spotId, array $entities): void
    {
        $this->pdo->prepare('DELETE FROM spot_metadata_entities WHERE spot_id = ?')->execute([$spotId]);
        if ($entities === []) {
            return;
        }
        $insert = $this->pdo->prepare('INSERT INTO spot_metadata_entities (spot_id, entity_type, entity_key, entity_name)
            VALUES (?, ?, ?, ?)');
        foreach ($entities as $entity) {
            $insert->execute([$spotId, $entity['type'], $entity['key'], $entity['name']]);
        }
    }

    /** @param list<array{type: string, key: string, name: string}> $entities
     *  @return list<array{type: string, key: string, name: string}>
     */
    private static function uniqueEntities(array $entities): array
    {
        $unique = [];
        foreach ($entities as $entity) {
            $key = $entity['type'] . ':' . $entity['key'];
            $unique[$key] = $entity;
        }
        return array_values($unique);
    }

    /** @param list<string> $values
     *  @return list<string>
     */
    private static function textList(array $values, int $max): array
    {
        $items = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value !== '') {
                $items[$value] = $value;
            }
        }
        return array_slice(array_values($items), 0, $max);
    }

    private static function truncate(string $value, int $max): string
    {
        if ($max < 1 || strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }

    private static function entityKey(string $name): string
    {
        return sha1(mb_strtolower(trim($name), 'UTF-8'));
    }
}
