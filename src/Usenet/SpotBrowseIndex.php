<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use PDO;

/**
 * Narrow read model for the overview: one integer-keyed row per spot plus shared category combinations.
 * A combination is the exact set of (head, type, erotica, subcategory codes); its spot_count drives
 * filter availability and query-plan selection without scanning millions of spots.
 */
final class SpotBrowseIndex
{
    private const REBUILD_BATCH = 2000;

    /** @var array<string, int> */
    private array $combos = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array{type: string, adult: bool, codes: list<string>} $classification */
    public function index(string $spotId, string $category, array $classification): void
    {
        $combo = $this->combo($category, $classification);
        $old = $this->pdo->prepare('SELECT combo_id FROM spot_browse WHERE spot_id = :id FOR UPDATE');
        $old->execute(['id' => $spotId]);
        $previous = $old->fetchColumn();
        $upsert = $this->pdo->prepare(
            'INSERT INTO spot_browse (spot_id, combo_id, category, browse_type, is_erotica, posted_at, created_at, title)
             SELECT id, :combo, :category, :type, :adult, posted_at, created_at, title FROM spotnet_spots WHERE id = :id
             ON DUPLICATE KEY UPDATE combo_id = VALUES(combo_id), category = VALUES(category), browse_type = VALUES(browse_type),
                is_erotica = VALUES(is_erotica), posted_at = VALUES(posted_at), title = VALUES(title)'
        );
        $upsert->execute(['id' => $spotId, 'combo' => $combo, 'category' => $category,
            'type' => $classification['type'], 'adult' => (int) $classification['adult']]);
        if ($previous !== false && (int) $previous === $combo) {
            return;
        }
        $count = $this->pdo->prepare('UPDATE spot_combos SET spot_count = spot_count + :delta WHERE id = :id');
        if ($previous !== false) {
            $count->execute(['delta' => -1, 'id' => (int) $previous]);
        }
        $count->execute(['delta' => 1, 'id' => $combo]);
    }

    /** Lowers combination counts for spots that are about to be deleted (rows follow via ON DELETE CASCADE). */
    public function forget(array $spotIds): void
    {
        if ($spotIds === []) {
            return;
        }
        $statement = $this->pdo->prepare('UPDATE spot_combos c
            JOIN (SELECT combo_id, COUNT(*) AS n FROM spot_browse WHERE spot_id IN ('
            . implode(',', array_fill(0, count($spotIds), '?')) . ') GROUP BY combo_id) d ON d.combo_id = c.id
            SET c.spot_count = c.spot_count - d.n');
        $statement->execute(array_values($spotIds));
    }

    /** Recounts every combination; repairs drift after manual SQL deletes. */
    public function refreshCounts(): void
    {
        $this->pdo->exec('UPDATE spot_combos c
            LEFT JOIN (SELECT combo_id, COUNT(*) AS n FROM spot_browse GROUP BY combo_id) x ON x.combo_id = c.id
            SET c.spot_count = COALESCE(x.n, 0)');
        $this->pdo->exec('DELETE FROM spot_filter_cache');
    }

    /**
     * Ensures every spot has a browse row, then recounts and builds the title index. Safe to rerun.
     * Spots are added oldest first so browse ids follow the date and date walks read neighbouring pages.
     */
    public function rebuild(?callable $progress = null): int
    {
        $progress ??= static function (string $message): void {
        };
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM spotnet_spots')->fetchColumn();
        $progress('Leesmodel opbouwen voor ' . $total . ' spots...');
        $order = $this->pdo->prepare('SELECT id, posted_at FROM spotnet_spots FORCE INDEX (idx_spotnet_spots_date)
            WHERE posted_at > :after_date OR (posted_at = :same_date AND id > :after_id)
            ORDER BY posted_at, id LIMIT ' . self::REBUILD_BATCH);
        $lastDate = '0000-01-01 00:00:00';
        $last = '';
        $done = 0;
        $started = microtime(true);
        do {
            $order->execute(['after_date' => $lastDate, 'same_date' => $lastDate, 'after_id' => $last]);
            $keys = $order->fetchAll(PDO::FETCH_ASSOC);
            if ($keys === []) {
                break;
            }
            $select = $this->pdo->prepare('SELECT id, category, subcategories, posted_at, created_at, title FROM spotnet_spots
                WHERE id IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
            $select->execute(array_column($keys, 'id'));
            $byId = array_column($select->fetchAll(PDO::FETCH_ASSOC), null, 'id');
            $rows = [];
            foreach ($keys as $key) {
                if (isset($byId[$key['id']])) {
                    $rows[] = $byId[$key['id']];
                }
            }
            $lastDate = $keys[count($keys) - 1]['posted_at'];
            $last = $keys[count($keys) - 1]['id'];
            if ($rows === []) {
                continue;
            }
            $values = [];
            $parameters = [];
            // One commit per batch instead of one per new combination keeps the rebuild fast on large databases.
            $own = !$this->pdo->inTransaction() && $this->pdo->beginTransaction();
            try {
                $described = [];
                foreach ($rows as $index => $row) {
                    $classification = SpotCategories::classify($row['category'],
                        json_decode($row['subcategories'], true, 512, JSON_THROW_ON_ERROR));
                    $described[$index] = [$classification, $this->describe($row['category'], $classification)];
                }
                $this->resolve(array_column($described, 1));
                foreach ($rows as $index => $row) {
                    [$classification, $combo] = $described[$index];
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($parameters, $row['id'], $this->combos[$combo['signature']], $row['category'],
                        $classification['type'], (int) $classification['adult'], $row['posted_at'], $row['created_at'], $row['title']);
                    $last = $row['id'];
                }
                $this->pdo->prepare('INSERT INTO spot_browse (spot_id, combo_id, category, browse_type, is_erotica, posted_at, created_at, title)
                    VALUES ' . implode(',', $values) . '
                    ON DUPLICATE KEY UPDATE combo_id = VALUES(combo_id), category = VALUES(category), browse_type = VALUES(browse_type),
                        is_erotica = VALUES(is_erotica), posted_at = VALUES(posted_at), title = VALUES(title)')
                    ->execute($parameters);
                if ($own) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $exception) {
                if ($own) {
                    $this->pdo->rollBack();
                    $this->reset();
                }
                throw $exception;
            }
            $done += count($rows);
            if ($done % 50000 < self::REBUILD_BATCH || count($keys) < self::REBUILD_BATCH) {
                $progress(sprintf('  %d / %d spots (%.0f s)', $done, $total, microtime(true) - $started));
            }
        } while (count($keys) === self::REBUILD_BATCH);
        $progress('Combinatietellers herberekenen...');
        $this->refreshCounts();
        $progress('Titelindex (FULLTEXT) bijwerken...');
        $this->ensureFulltext();
        $progress('Leesmodel gereed: ' . $done . ' spots.');
        return $done;
    }

    public function ensureFulltext(): void
    {
        // Without stopwords "The Who" stays searchable; the setting is stored with the index.
        $this->pdo->exec('SET SESSION innodb_ft_enable_stopword = 0');
        try {
            $this->pdo->exec('ALTER TABLE spot_browse ADD FULLTEXT INDEX IF NOT EXISTS ft_browse_title (title)');
        } finally {
            $this->pdo->exec('SET SESSION innodb_ft_enable_stopword = 1');
        }
    }

    /** Clears cached combination ids after a rollback may have discarded freshly inserted combinations. */
    public function reset(): void
    {
        $this->combos = [];
    }

    /** @param array{type: string, adult: bool, codes: list<string>} $classification */
    private function combo(string $category, array $classification): int
    {
        $combo = $this->describe($category, $classification);
        $this->resolve([$combo]);
        return $this->combos[$combo['signature']];
    }

    /**
     * @param array{type: string, adult: bool, codes: list<string>} $classification
     * @return array{signature: string, category: string, type: string, adult: int, codes: list<string>}
     */
    private function describe(string $category, array $classification): array
    {
        $head = array_search($category, SpotCategories::HEADS, true);
        $codes = $head === false ? [] : array_map(static fn (string $code): string => $head . ':' . $code, $classification['codes']);
        sort($codes);
        $codes = array_values(array_unique($codes));
        return [
            'signature' => sha1(json_encode([$category, $classification['type'], $classification['adult'], $codes], JSON_THROW_ON_ERROR)),
            'category' => $category,
            'type' => $classification['type'],
            'adult' => (int) $classification['adult'],
            'codes' => $codes,
        ];
    }

    /**
     * Creates unknown combinations with a few multi-row statements and caches their ids.
     *
     * @param list<array{signature: string, category: string, type: string, adult: int, codes: list<string>}> $combos
     */
    private function resolve(array $combos): void
    {
        $missing = [];
        foreach ($combos as $combo) {
            if (!isset($this->combos[$combo['signature']])) {
                $missing[$combo['signature']] = $combo;
            }
        }
        foreach (array_chunk($missing, 500, true) as $chunk) {
            $parameters = [];
            foreach ($chunk as $combo) {
                array_push($parameters, $combo['signature'], $combo['category'], $combo['type'], $combo['adult']);
            }
            $this->pdo->prepare('INSERT IGNORE INTO spot_combos (signature, category, browse_type, is_erotica) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)')))->execute($parameters);
            $select = $this->pdo->prepare('SELECT id, signature FROM spot_combos WHERE signature IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $select->execute(array_keys($chunk));
            $codes = [];
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                foreach ($chunk[$row['signature']]['codes'] as $code) {
                    array_push($codes, $id, $code);
                }
                $this->combos[$row['signature']] = $id;
            }
            foreach (array_chunk($codes, 20000) as $pairs) {
                $this->pdo->prepare('INSERT IGNORE INTO spot_combo_codes (combo_id, code) VALUES '
                    . implode(',', array_fill(0, intdiv(count($pairs), 2), '(?, ?)')))->execute($pairs);
            }
            if (count(array_intersect_key($chunk, $this->combos)) !== count($chunk)) {
                throw new \RuntimeException('Categoriecombinatie kon niet worden vastgelegd.');
            }
        }
    }
}
