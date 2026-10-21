<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use PDO;

final class SpotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exists(string $id, string $role): bool
    {
        return $this->existing([$id], $role) !== [];
    }

    /**
     * @param list<string> $ids
     * @return array<string, string> Existing ids mapped to their comment body status ('loaded' for spots).
     */
    public function existing(array $ids, string $role): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare($role === 'spots'
            ? 'SELECT id, \'loaded\' FROM spotnet_spots WHERE id IN (' . $placeholders . ')'
            : 'SELECT id, body_status FROM spotnet_comments WHERE id IN (' . $placeholders . ')');
        $statement->execute(array_values($ids));
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param array<string, mixed> $spot */
    public function saveSpot(array $spot, string $group, bool $refresh = false): void
    {
        $classification = SpotCategories::classify($spot['category'], $spot['subcategories']);
        // Retrieve batches wrap many spots in one transaction; a savepoint keeps a failed spot isolated.
        $nested = $this->pdo->inTransaction();
        $nested ? $this->pdo->exec('SAVEPOINT nnspotty_spot') : $this->pdo->beginTransaction();
        try {
            if ($refresh) {
                $existing = $this->pdo->prepare('SELECT image_url, image_segments, nzb_segments FROM spotnet_spots WHERE id = :id FOR UPDATE');
                $existing->execute(['id' => $spot['id']]);
                $image = $existing->fetch();
                if (is_array($image) && ($image['image_url'] !== $spot['image_url']
                    || json_decode($image['image_segments'], true, 512, JSON_THROW_ON_ERROR) !== $spot['image_segments'])) {
                    $delete = $this->pdo->prepare('DELETE FROM spotnet_images WHERE spot_id = :id');
                    $delete->execute(['id' => $spot['id']]);
                }
                if (is_array($image) && json_decode($image['nzb_segments'], true, 512, JSON_THROW_ON_ERROR) !== $spot['nzb_segments']) {
                    $delete = $this->pdo->prepare('DELETE FROM spotnet_nzbs WHERE spot_id = :id');
                    $delete->execute(['id' => $spot['id']]);
                }
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO spotnet_spots
                    (id, message_id, group_name, title, description, poster, newsreader, category, subcategories, size,
                     posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, browse_type, is_erotica, spotter_key, signature_state, created_at)
                 VALUES
                    (:id, :message_id, :group_name, :title, :description, :poster, :newsreader, :category, :subcategories, :size,
                     :posted_at, :website, :tag, :image_url, :image_segments, :nzb_segments, :full_xml, :browse_type, :is_erotica, :spotter_key, :signature_state, UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE ' . ($refresh ? 'title = VALUES(title), description = VALUES(description),
                    poster = VALUES(poster), newsreader = VALUES(newsreader), category = VALUES(category),
                    subcategories = VALUES(subcategories), size = VALUES(size), posted_at = VALUES(posted_at),
                    website = VALUES(website), tag = VALUES(tag), image_url = VALUES(image_url),
                    image_segments = VALUES(image_segments), nzb_segments = VALUES(nzb_segments),
                    full_xml = VALUES(full_xml), browse_type = VALUES(browse_type), is_erotica = VALUES(is_erotica),
                    spotter_key = VALUES(spotter_key), signature_state = VALUES(signature_state)'
                    : 'id = VALUES(id)')
            );
            $parameters = $spot;
            $parameters['spotter_key'] ??= null;
            $parameters['signature_state'] ??= 'missing';
            $parameters['group_name'] = $group;
            $parameters['full_xml'] = $parameters['xml'];
            $parameters['browse_type'] = $classification['type'];
            $parameters['is_erotica'] = (int) $classification['adult'];
            unset($parameters['xml']);
            foreach (['subcategories', 'image_segments', 'nzb_segments'] as $key) {
                $parameters[$key] = json_encode($parameters[$key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $statement->execute($parameters);
            if ($statement->rowCount() > 0) {
                $this->indexCategories($spot['id'], $spot['category'], $spot['subcategories'], $classification);
            }
            $nested ? $this->pdo->exec('RELEASE SAVEPOINT nnspotty_spot') : $this->pdo->commit();
        } catch (\Throwable $exception) {
            $nested ? $this->pdo->exec('ROLLBACK TO SAVEPOINT nnspotty_spot') : $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $comment */
    public function saveComment(array $comment): void
    {
        $this->saveComments([$comment]);
    }

    /**
     * Multi-row upsert. Header-only entries (body_status pending) never overwrite a stored body;
     * loaded entries complete pending rows.
     *
     * @param list<array<string, mixed>> $comments
     */
    public function saveComments(array $comments): void
    {
        foreach (array_chunk($comments, 100) as $chunk) {
            $rows = [];
            $parameters = [];
            foreach ($chunk as $comment) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($parameters, $comment['id'], $comment['message_id'], $comment['spot_id'], $comment['poster'],
                    $comment['body'], $comment['body_status'] ?? 'loaded', $comment['posted_at']);
            }
            $statement = $this->pdo->prepare(
                'INSERT INTO spotnet_comments (id, message_id, spot_id, poster, body, body_status, posted_at)
                 VALUES ' . implode(', ', $rows) . '
                 ON DUPLICATE KEY UPDATE
                    spot_id = IF(VALUES(body_status) = \'loaded\', VALUES(spot_id), spot_id),
                    poster = IF(VALUES(body_status) = \'loaded\', VALUES(poster), poster),
                    body = IF(VALUES(body_status) = \'loaded\', VALUES(body), body),
                    body_status = IF(VALUES(body_status) = \'loaded\', \'loaded\', body_status)'
            );
            $statement->execute($parameters);
        }
    }

    /** @return list<string> Newest pending message IDs first. */
    public function pendingCommentMessageIds(string $spotId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT message_id FROM spotnet_comments WHERE spot_id = :spot_id AND body_status = \'pending\'
             ORDER BY posted_at DESC LIMIT ' . max(1, $limit)
        );
        $statement->execute(['spot_id' => $spotId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function pendingCommentCount(string $spotId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM spotnet_comments WHERE spot_id = :spot_id AND body_status = \'pending\'');
        $statement->execute(['spot_id' => $spotId]);
        return (int) $statement->fetchColumn();
    }

    /** @param list<string> $messageIds */
    public function markComments(array $messageIds, string $status): void
    {
        if (!in_array($status, ['unavailable', 'invalid'], true)) {
            throw new \InvalidArgumentException('Ongeldige reactiestatus.');
        }
        foreach (array_chunk($messageIds, 100) as $chunk) {
            $statement = $this->pdo->prepare('UPDATE spotnet_comments SET body_status = ?
                WHERE body_status = \'pending\' AND message_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')');
            $statement->execute([$status, ...$chunk]);
        }
    }

    public function deleteExpiredComments(string $cutoff, int $limit = 5000): int
    {
        $statement = $this->pdo->prepare('DELETE FROM spotnet_comments WHERE posted_at < :cutoff LIMIT ' . max(1, $limit));
        $statement->execute(['cutoff' => $cutoff]);
        return $statement->rowCount();
    }

    public function deleteExpiredSpots(string $cutoff, int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('De batchgrootte voor spotopruiming moet positief zijn.');
        }

        $select = $this->pdo->prepare(
            'SELECT id FROM spotnet_spots
             WHERE posted_at < :cutoff
             ORDER BY posted_at ASC, id ASC
             LIMIT ' . $limit
        );
        $select->execute(['cutoff' => $cutoff]);
        $ids = $select->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            return 0;
        }

        $parameters = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'spot_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $id;
        }
        $in = implode(', ', $placeholders);

        $this->pdo->beginTransaction();
        try {
            foreach ([
                ['spotnet_comments', 'spot_id'],
                ['post_comments', 'post_id'],
            ] as [$table, $column]) {
                $deleteRelated = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . $in . ')');
                $deleteRelated->execute($parameters);
            }
            $deleteSpots = $this->pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (' . $in . ')');
            $deleteSpots->execute($parameters);
            $deleted = $deleteSpots->rowCount();
            $this->pdo->commit();
            return $deleted;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listCategoriesWithCounts(bool $showErotica = true): array
    {
        return $this->pdo->query('SELECT browse_type AS category, COUNT(*) AS total FROM spotnet_spots'
            . ($showErotica ? '' : ' WHERE is_erotica = 0') . ' GROUP BY browse_type ORDER BY browse_type')->fetchAll();
    }

    public function countSpots(?string $category, bool $showErotica = true, string|array|null $subcategory = null, string $search = ''): int
    {
        [$where, $parameters] = $this->filter($category, $showErotica, $subcategory, $search);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM spotnet_spots s' . $where);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function listSpots(?string $category, int $limit, int $offset, bool $showErotica = true, string|array|null $subcategory = null, string $search = '', ?array $after = null, ?string $favoriteUser = null): array
    {
        [$where, $parameters] = $this->filter($category, $showErotica, $subcategory, $search, $favoriteUser);
        if ($after !== null) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . '(s.posted_at < :before_date OR (s.posted_at = :same_date AND s.id < :before_id))';
            $parameters['before_date'] = $after['date'];
            $parameters['same_date'] = $after['date'];
            $parameters['before_id'] = $after['id'];
        }
        $columns = 's.id, title, category, browse_type, subcategories, poster, size, posted_at, image_url, image_segments, spotter_key, signature_state';
        $fetch = function (string $sql) use ($parameters, $limit, $offset): array {
            $statement = $this->pdo->prepare($sql);
            foreach ($parameters as $key => $value) {
                $statement->bindValue(':' . $key, $value);
            }
            $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
            $statement->execute();
            return $this->withSpotterStatus(array_map($this->decode(...), $statement->fetchAll()));
        };
        if ($search !== '') {
            $ordered = 'SELECT ' . $columns . ' FROM spotnet_spots s' . $where
                . ' ORDER BY s.posted_at DESC, s.id DESC LIMIT :limit OFFSET :offset';
            $timed = str_contains((string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')
                ? 'SET STATEMENT max_statement_time=0.5 FOR ' . $ordered
                : 'SELECT /*+ MAX_EXECUTION_TIME(500) */ ' . substr($ordered, 7);
            try {
                return $fetch($timed);
            } catch (\PDOException $exception) {
                // Only an exhausted read budget switches plans; other database errors remain failures.
                if (!in_array((int) ($exception->errorInfo[1] ?? 0), [1969, 3024], true)) {
                    throw $exception;
                }
            }
        }
        $scan = $search === '' || $favoriteUser !== null ? '' : ' USE INDEX (PRIMARY)';
        return $fetch('SELECT ' . $columns . '
            FROM spotnet_spots s
            JOIN (SELECT s.id FROM spotnet_spots s' . $scan . $where
                . ' ORDER BY s.posted_at DESC, s.id DESC LIMIT :limit OFFSET :offset) page ON page.id = s.id
            ORDER BY s.posted_at DESC, s.id DESC');
    }

    /** @param list<string> $filters
     *  @return array{spots: list<array<string, mixed>>, next: ?string}
     */
    public function batch(?string $category, int $limit, bool $showErotica, array $filters, string $search, ?string $cursor = null, ?string $favoriteUser = null): array
    {
        $after = null;
        if ($cursor !== null) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($decoded === false || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([a-f0-9]{64})$/D', $decoded, $match)) {
                throw new \InvalidArgumentException('Ongeldige vervolgpositie.');
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1]);
            if ($date === false || $date->format('Y-m-d H:i:s') !== $match[1]) {
                throw new \InvalidArgumentException('Ongeldige datum in vervolgpositie.');
            }
            $after = ['date' => $match[1], 'id' => $match[2]];
        }
        $limit = max(10, min(200, $limit));
        $rows = $this->listSpots($category, $limit + 1, 0, $showErotica, $filters, $search, $after, $favoriteUser);
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        return [
            'spots' => $rows,
            'next' => $more && $last !== null ? rtrim(strtr(base64_encode($last['posted_at'] . '|' . $last['id']), '+/', '-_'), '=') : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getSpot(string $id, bool $showErotica = true): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM spotnet_spots WHERE id = :id' . ($showErotica ? '' : ' AND is_erotica = 0'));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->withSpotterStatus([$this->decode($row)])[0] : null;
    }

    /** @return list<array<string, mixed>> */
    public function facets(?string $category, bool $showErotica, string $search = '', array $subcategories = [], ?string $newSince = null, ?string $favoriteUser = null): array
    {
        [$where, $parameters] = $this->filter($category, $showErotica, $subcategories, $search, $favoriteUser);
        if ($newSince !== null) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 's.created_at > :new_since';
            $parameters['new_since'] = $newSince;
        }
        $statement = $this->pdo->prepare('SELECT c.code, COUNT(*) AS total FROM spotnet_spot_categories c
            JOIN spotnet_spots s ON s.id = c.spot_id' . $where . ' GROUP BY c.code ORDER BY c.code');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function rebuildCategories(): int
    {
        $newznab = $this->pdo->query("SHOW TABLES LIKE 'spotnet_newznab_categories'")->fetchColumn() !== false;
        $last = '';
        $total = 0;
        do {
            $select = $this->pdo->prepare('SELECT id, category, subcategories FROM spotnet_spots WHERE id > :id ORDER BY id LIMIT 250');
            $select->execute(['id' => $last]);
            $rows = $select->fetchAll();
            $this->pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $subcategories = json_decode($row['subcategories'], true, 512, JSON_THROW_ON_ERROR);
                    $classification = SpotCategories::classify($row['category'], $subcategories);
                    $this->indexCategories($row['id'], $row['category'], $subcategories, $classification, $newznab);
                    $last = $row['id'];
                    $total++;
                }
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        } while (count($rows) === 250);
        return $total;
    }

    /** @param array{type: string, adult: bool, codes: list<string>} $classification */
    private function indexCategories(string $id, string $category, array $subcategories, array $classification, bool $newznab = true): void
    {
        $update = $this->pdo->prepare('UPDATE spotnet_spots SET browse_type = :type, is_erotica = :adult WHERE id = :id');
        $update->execute(['id' => $id, 'type' => $classification['type'], 'adult' => (int) $classification['adult']]);
        $delete = $this->pdo->prepare('DELETE FROM spotnet_spot_categories WHERE spot_id = :id');
        $delete->execute(['id' => $id]);
        $insert = $this->pdo->prepare('INSERT INTO spotnet_spot_categories (spot_id, code) VALUES (:id, :code)');
        $head = array_search($category, SpotCategories::HEADS, true);
        foreach ($classification['codes'] as $code) {
            $insert->execute(['id' => $id, 'code' => $head . ':' . $code]);
        }
        if ($newznab) {
            $delete = $this->pdo->prepare('DELETE FROM spotnet_newznab_categories WHERE spot_id = :id');
            $delete->execute(['id' => $id]);
            $insert = $this->pdo->prepare('INSERT INTO spotnet_newznab_categories (spot_id, category_id) VALUES (:id, :category)');
            foreach (\NnSpotty\Newznab\Categories::forSpot($category, $subcategories) as $newznabCategory) {
                $insert->execute(['id' => $id, 'category' => $newznabCategory]);
            }
        }
    }

    /** @return array{string, array<string, string>} */
    private function filter(?string $category, bool $showErotica, string|array|null $subcategory, string $search, ?string $favoriteUser = null): array
    {
        $conditions = [];
        $parameters = [];
        if ($favoriteUser !== null) {
            $conditions[] = 's.id IN (SELECT spot_id FROM spot_favorites WHERE username = :favorite_user)';
            $parameters['favorite_user'] = $favoriteUser;
        }
        if (!$showErotica) {
            $conditions[] = 's.is_erotica = 0';
        }
        if ($category !== null) {
            if (in_array($category, SpotCategories::HEADS, true)) {
                $conditions[] = 's.category = :category';
                $parameters['category'] = $category;
            } elseif (SpotCategories::headForCategory($category) !== null) {
                $conditions[] = 's.browse_type = :type';
                $parameters['type'] = $category;
            } else {
                $conditions[] = '(s.browse_type = :type OR s.category = :head)';
                $parameters['type'] = $category;
                $parameters['head'] = $category;
            }
        }
        $subcategories = is_array($subcategory) ? $subcategory : SpotFilters::parse($subcategory);
        foreach ($subcategories as $index => $code) {
            $key = 'code_' . $index;
            $conditions[] = 'EXISTS (SELECT 1 FROM spotnet_spot_categories f WHERE f.spot_id = s.id AND f.code = :' . $key . ')';
            $parameters[$key] = $code;
        }
        if ($search !== '') {
            $conditions[] = 's.title LIKE :search';
            $parameters['search'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $conditions[count($conditions) - 1] .= " ESCAPE '!'";
        }
        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $parameters];
    }

    /** @return list<array<string, mixed>> */
    public function comments(string $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT poster, body, posted_at, \'Usenet\' AS source FROM spotnet_comments WHERE spot_id = :spot_id AND body_status = \'loaded\'
             UNION ALL
             SELECT username AS poster, comment AS body, created_at AS posted_at, \'NNSpotty\' AS source
             FROM post_comments WHERE post_id = :local_id
             ORDER BY posted_at ASC'
        );
        $statement->execute(['spot_id' => $id, 'local_id' => $id]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function image(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT mime_type, image_data FROM spotnet_images WHERE spot_id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveImage(string $id, string $mime, string $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO spotnet_images (spot_id, mime_type, image_data) VALUES (:id, :mime, :data)
             ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), image_data = VALUES(image_data)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':mime', $mime);
        $statement->bindValue(':data', $data, PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (['subcategories', 'image_segments', 'nzb_segments'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = json_decode($row[$key], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $row;
    }

    private function withSpotterStatus(array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_column($rows, 'spotter_key'))));
        $statuses = [];
        if ($keys !== []) {
            $query = $this->pdo->prepare("SELECT key_hash, MAX(list_type = 'blacklist') AS blocked
                FROM spotter_keys WHERE key_hash IN (" . implode(',', array_fill(0, count($keys), '?')) . ")
                AND EXISTS (SELECT 1 FROM spotter_list_settings WHERE id = 1 AND enabled = 1) GROUP BY key_hash");
            $query->execute($keys);
            foreach ($query->fetchAll() as $entry) {
                $statuses[$entry['key_hash']] = $entry['blocked'] ? 'blacklist' : 'whitelist';
            }
        }
        foreach ($rows as &$row) {
            $row['spotter_status'] = ($row['signature_state'] ?? 'missing') === 'verified'
                ? ($statuses[$row['spotter_key']] ?? 'unlisted') : 'unverified';
        }
        return $rows;
    }
}
