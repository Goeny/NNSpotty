<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use DateTimeImmutable;
use PDO;

final class UsenetPostRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array{category: string, total: int}>
     */
    public function listCategoriesWithCounts(): array
    {
        $statement = $this->pdo->query(
            'SELECT category, COUNT(*) AS total
             FROM spot_releases
             GROUP BY category
             ORDER BY total DESC, category ASC'
        );
        $rows = $statement->fetchAll();
        $categories = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['category'], $row['total'])) {
                continue;
            }

            $category = trim((string) $row['category']);
            if ($category === '') {
                continue;
            }

            $categories[] = [
                'category' => $category,
                'total' => (int) $row['total'],
            ];
        }

        return $categories;
    }

    public function countSpots(?string $category): int
    {
        if ($category !== null) {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM spot_releases WHERE category = :category');
            $statement->execute(['category' => $category]);
        } else {
            $statement = $this->pdo->query('SELECT COUNT(*) FROM spot_releases');
        }

        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return array<int, array{
     *   release_key: string,
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   last_posted_at: string
     * }>
     */
    public function listSpots(?string $category, int $limit, int $offset): array
    {
        if ($category !== null) {
            $statement = $this->pdo->prepare(
                'SELECT release_key, release_title, category, group_name, poster, total_parts, total_bytes,
                        DATE_FORMAT(last_posted_at, "%Y-%m-%d %H:%i:%s") AS last_posted_at
                 FROM spot_releases
                 WHERE category = :category
                 ORDER BY COALESCE(last_posted_at, updated_at) DESC, updated_at DESC
                 LIMIT :limit OFFSET :offset'
            );
            $statement->bindValue(':category', $category, PDO::PARAM_STR);
        } else {
            $statement = $this->pdo->prepare(
                'SELECT release_key, release_title, category, group_name, poster, total_parts, total_bytes,
                        DATE_FORMAT(last_posted_at, "%Y-%m-%d %H:%i:%s") AS last_posted_at
                 FROM spot_releases
                 ORDER BY COALESCE(last_posted_at, updated_at) DESC, updated_at DESC
                 LIMIT :limit OFFSET :offset'
            );
        }

        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $spots = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $releaseKey = trim((string) ($row['release_key'] ?? ''));
            if ($releaseKey === '') {
                continue;
            }

            $spots[] = [
                'release_key' => $releaseKey,
                'release_title' => (string) ($row['release_title'] ?? ''),
                'category' => (string) ($row['category'] ?? 'general'),
                'group_name' => (string) ($row['group_name'] ?? ''),
                'poster' => (string) ($row['poster'] ?? ''),
                'total_parts' => (int) ($row['total_parts'] ?? 0),
                'total_bytes' => (int) ($row['total_bytes'] ?? 0),
                'last_posted_at' => (string) ($row['last_posted_at'] ?? ''),
            ];
        }

        return $spots;
    }

    /**
     * @return array{
     *   release_key: string,
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   first_posted_at: string,
     *   last_posted_at: string
     * }|null
     */
    public function getSpot(string $releaseKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT release_key, release_title, category, group_name, poster, total_parts, total_bytes,
                    DATE_FORMAT(first_posted_at, "%Y-%m-%d %H:%i:%s") AS first_posted_at,
                    DATE_FORMAT(last_posted_at, "%Y-%m-%d %H:%i:%s") AS last_posted_at
             FROM spot_releases
             WHERE release_key = :release_key
             LIMIT 1'
        );
        $statement->execute(['release_key' => $releaseKey]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'release_key' => (string) ($row['release_key'] ?? ''),
            'release_title' => (string) ($row['release_title'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'group_name' => (string) ($row['group_name'] ?? ''),
            'poster' => (string) ($row['poster'] ?? ''),
            'total_parts' => (int) ($row['total_parts'] ?? 0),
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'first_posted_at' => (string) ($row['first_posted_at'] ?? ''),
            'last_posted_at' => (string) ($row['last_posted_at'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{
     *   message_id: string,
     *   subject: string,
     *   article_number: int,
     *   from_header: string,
     *   posted_at: string,
     *   bytes: int|null
     * }>
     */
    public function listSpotParts(string $releaseKey, int $limit = 200): array
    {
        $statement = $this->pdo->prepare(
            'SELECT message_id, subject, article_number, from_header,
                    DATE_FORMAT(posted_at, "%Y-%m-%d %H:%i:%s") AS posted_at, bytes
             FROM usenet_posts
             WHERE release_key = :release_key
             ORDER BY article_number ASC
             LIMIT :limit'
        );
        $statement->bindValue(':release_key', $releaseKey, PDO::PARAM_STR);
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $parts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parts[] = [
                'message_id' => (string) ($row['message_id'] ?? ''),
                'subject' => (string) ($row['subject'] ?? ''),
                'article_number' => (int) ($row['article_number'] ?? 0),
                'from_header' => (string) ($row['from_header'] ?? ''),
                'posted_at' => (string) ($row['posted_at'] ?? ''),
                'bytes' => isset($row['bytes']) ? (int) $row['bytes'] : null,
            ];
        }

        return $parts;
    }

    /**
     * @return array{releases: int, parts: int}
     */
    public function rebuildSpotIndex(): array
    {
        $this->pdo->exec('TRUNCATE TABLE spot_releases');

        $select = $this->pdo->query(
            'SELECT message_id, group_name, category, subject, from_header, posted_at, bytes
             FROM usenet_posts
             ORDER BY id ASC'
        );
        $upsert = $this->pdo->prepare(
            'INSERT INTO spot_releases (
                release_key, release_title, category, group_name, poster,
                total_parts, total_bytes, first_posted_at, last_posted_at,
                last_message_id, created_at, updated_at
            ) VALUES (
                :release_key, :release_title, :category, :group_name, :poster,
                1, :total_bytes, :first_posted_at, :last_posted_at,
                :last_message_id, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                release_title = CASE
                    WHEN CHAR_LENGTH(VALUES(release_title)) > CHAR_LENGTH(release_title) THEN VALUES(release_title)
                    ELSE release_title
                END,
                category = VALUES(category),
                group_name = VALUES(group_name),
                poster = VALUES(poster),
                total_parts = total_parts + 1,
                total_bytes = total_bytes + VALUES(total_bytes),
                first_posted_at = CASE
                    WHEN first_posted_at IS NULL THEN VALUES(first_posted_at)
                    WHEN VALUES(first_posted_at) IS NULL THEN first_posted_at
                    ELSE LEAST(first_posted_at, VALUES(first_posted_at))
                END,
                last_posted_at = CASE
                    WHEN last_posted_at IS NULL THEN VALUES(last_posted_at)
                    WHEN VALUES(last_posted_at) IS NULL THEN last_posted_at
                    ELSE GREATEST(last_posted_at, VALUES(last_posted_at))
                END,
                last_message_id = VALUES(last_message_id),
                updated_at = UTC_TIMESTAMP()'
        );
        $update = $this->pdo->prepare(
            'UPDATE usenet_posts
             SET release_key = :release_key, release_title = :release_title
             WHERE message_id = :message_id'
        );

        $parts = 0;
        while (($row = $select->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }

            $messageId = trim((string) ($row['message_id'] ?? ''));
            $groupName = (string) ($row['group_name'] ?? '');
            if ($messageId === '' || $groupName === '') {
                continue;
            }

            $parsed = SpotSubjectParser::parse((string) ($row['subject'] ?? ''), $groupName);
            $postedAtValue = null;
            $postedRaw = (string) ($row['posted_at'] ?? '');
            if ($postedRaw !== '') {
                try {
                    $postedAtValue = (new DateTimeImmutable($postedRaw))->format('Y-m-d H:i:s');
                } catch (\Throwable) {
                    $postedAtValue = null;
                }
            }

            $upsert->execute([
                'release_key' => $parsed['release_key'],
                'release_title' => $parsed['release_title'],
                'category' => (string) ($row['category'] ?? 'general'),
                'group_name' => $groupName,
                'poster' => (string) ($row['from_header'] ?? ''),
                'total_bytes' => max(0, (int) ($row['bytes'] ?? 0)),
                'first_posted_at' => $postedAtValue,
                'last_posted_at' => $postedAtValue,
                'last_message_id' => $messageId,
            ]);

            $update->execute([
                'release_key' => $parsed['release_key'],
                'release_title' => $parsed['release_title'],
                'message_id' => $messageId,
            ]);
            $parts++;
        }

        $count = $this->pdo->query('SELECT COUNT(*) FROM spot_releases')->fetchColumn();

        return [
            'releases' => is_numeric($count) ? (int) $count : 0,
            'parts' => $parts,
        ];
    }
}
