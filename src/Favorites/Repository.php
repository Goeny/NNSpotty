<?php

declare(strict_types=1);

namespace NnSpotty\Favorites;

use PDO;

final class Repository
{
    public function __construct(private readonly PDO $pdo) {}

    public function set(string $username, string $id, bool $favorite): void
    {
        if ($favorite) {
            $this->pdo->prepare('INSERT INTO spot_favorites (username, spot_id) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE spot_id = VALUES(spot_id)')->execute([$username, $id]);
        } else {
            $this->pdo->prepare('DELETE FROM spot_favorites WHERE username = ? AND spot_id = ?')->execute([$username, $id]);
        }
    }

    /** @param list<array<string, mixed>> $spots
     *  @return list<array<string, mixed>>
     */
    public function annotate(array $spots, string $username): array
    {
        if ($spots === []) return [];
        $ids = array_column($spots, 'id');
        $query = $this->pdo->prepare('SELECT spot_id FROM spot_favorites WHERE username = ? AND spot_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute([$username, ...$ids]);
        $favorites = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($spots as &$spot) $spot['is_favorite'] = isset($favorites[$spot['id']]);
        unset($spot);
        return $spots;
    }
}
