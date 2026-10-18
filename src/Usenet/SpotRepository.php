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
        $table = $role === 'spots' ? 'spotnet_spots' : 'spotnet_comments';
        $statement = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $spot */
    public function saveSpot(array $spot, string $group): void
    {
        $classification = SpotCategories::classify($spot['category'], $spot['subcategories']);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO spotnet_spots
                    (id, message_id, group_name, title, description, poster, category, subcategories, size,
                     posted_at, website, tag, image_url, image_segments, nzb_segments, full_xml, browse_type, is_erotica, created_at)
                 VALUES
                    (:id, :message_id, :group_name, :title, :description, :poster, :category, :subcategories, :size,
                     :posted_at, :website, :tag, :image_url, :image_segments, :nzb_segments, :full_xml, :browse_type, :is_erotica, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id = VALUES(id)'
            );
            $parameters = $spot;
            $parameters['group_name'] = $group;
            $parameters['full_xml'] = $parameters['xml'];
            $parameters['browse_type'] = $classification['type'];
            $parameters['is_erotica'] = (int) $classification['adult'];
            unset($parameters['xml']);
            foreach (['subcategories', 'image_segments', 'nzb_segments'] as $key) {
                $parameters[$key] = json_encode($parameters[$key], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
            $statement->execute($parameters);
            if ($statement->rowCount() === 1) {
                $this->indexCategories($spot['id'], $spot['category'], $classification);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $comment */
    public function saveComment(array $comment): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO spotnet_comments (id, message_id, spot_id, poster, body, posted_at)
             VALUES (:id, :message_id, :spot_id, :poster, :body, :posted_at)
             ON DUPLICATE KEY UPDATE id = VALUES(id)'
        );
        $statement->execute($comment);
    }

    /** @return list<array<string, mixed>> */
    public function listCategoriesWithCounts(bool $showErotica = true): array
    {
        return $this->pdo->query('SELECT browse_type AS category, COUNT(*) AS total FROM spotnet_spots'
            . ($showErotica ? '' : ' WHERE is_erotica = 0') . ' GROUP BY browse_type ORDER BY browse_type')->fetchAll();
    }

    public function countSpots(?string $category, bool $showErotica = true, ?string $subcategory = null, string $search = ''): int
    {
        [$where, $parameters] = $this->filter($category, $showErotica, $subcategory, $search);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM spotnet_spots s' . $where);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function listSpots(?string $category, int $limit, int $offset, bool $showErotica = true, ?string $subcategory = null, string $search = ''): array
    {
        [$where, $parameters] = $this->filter($category, $showErotica, $subcategory, $search);
        $statement = $this->pdo->prepare(
            'SELECT s.id, title, category, browse_type, subcategories, poster, size, posted_at, image_url, image_segments
             FROM spotnet_spots s' . $where .
            ' ORDER BY posted_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        return array_map($this->decode(...), $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function getSpot(string $id, bool $showErotica = true): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM spotnet_spots WHERE id = :id' . ($showErotica ? '' : ' AND is_erotica = 0'));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $this->decode($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function facets(?string $category, bool $showErotica, string $search = ''): array
    {
        [$where, $parameters] = $this->filter($category, $showErotica, null, $search);
        $statement = $this->pdo->prepare('SELECT c.code, COUNT(*) AS total FROM spotnet_spot_categories c
            JOIN spotnet_spots s ON s.id = c.spot_id' . $where . ' GROUP BY c.code ORDER BY c.code');
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public function rebuildCategories(): int
    {
        $last = '';
        $total = 0;
        do {
            $select = $this->pdo->prepare('SELECT id, category, subcategories FROM spotnet_spots WHERE id > :id ORDER BY id LIMIT 250');
            $select->execute(['id' => $last]);
            $rows = $select->fetchAll();
            $this->pdo->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $classification = SpotCategories::classify($row['category'], json_decode($row['subcategories'], true, 512, JSON_THROW_ON_ERROR));
                    $this->indexCategories($row['id'], $row['category'], $classification);
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
    private function indexCategories(string $id, string $category, array $classification): void
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
    }

    /** @return array{string, array<string, string>} */
    private function filter(?string $category, bool $showErotica, ?string $subcategory, string $search): array
    {
        $conditions = [];
        $parameters = [];
        if (!$showErotica) {
            $conditions[] = 's.is_erotica = 0';
        }
        if ($category !== null) {
            $conditions[] = '(s.browse_type = :type OR s.category = :head)';
            $parameters['type'] = $category;
            $parameters['head'] = $category;
        }
        if ($subcategory !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM spotnet_spot_categories f WHERE f.spot_id = s.id AND f.code = :code)';
            $parameters['code'] = $subcategory;
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
            'SELECT poster, body, posted_at, \'Usenet\' AS source FROM spotnet_comments WHERE spot_id = :spot_id
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
}
