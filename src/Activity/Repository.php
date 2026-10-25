<?php

declare(strict_types=1);

namespace NnSpotty\Activity;

use InvalidArgumentException;
use NnSpotty\Usenet\SpotCategories;
use PDO;
use Throwable;

final class Repository
{
    public const SOURCES = ['detail' => 'Detailpagina', 'nzb' => 'NZB-bestand', 'sabnzbd' => 'SABnzbd', 'newznab' => 'Newznab API'];
    public const PAGE_SIZE = 30;

    public function __construct(private readonly PDO $pdo) {}

    public function record(string $username, array $spot, string $source): void
    {
        if (!isset(self::SOURCES[$source])) throw new InvalidArgumentException('Onbekende historiebron.');
        $kind = $source === 'detail' ? 'view' : 'download';
        $category = SpotCategories::classify($spot['category'], $spot['subcategories'])['type'];
        $adult = (int) $spot['is_erotica'];
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO activity_history
                (username, spot_id, kind, source, title, category, is_erotica, first_at, last_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE title = VALUES(title), category = VALUES(category),
                    is_erotica = VALUES(is_erotica), last_at = VALUES(last_at), action_count = action_count + 1')
                ->execute([$username, $spot['id'], $kind, $source, $spot['title'], $category, $adult]);
            $this->pdo->prepare('INSERT INTO activity_daily (day, kind, source, category, is_erotica, action_count)
                VALUES (UTC_DATE(), ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE action_count = action_count + 1')->execute([$kind, $source, $category, $adult]);
            if ($ownTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownTransaction) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function history(string $username, string $kind, bool $adult, int $page): array
    {
        if (!in_array($kind, ['view', 'download'], true) || $page < 1 || $page > 100000) {
            throw new InvalidArgumentException('Ongeldige historiepagina.');
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $query = $this->pdo->prepare('SELECT h.spot_id, COALESCE(MAX(s.title), MAX(h.title)) AS title,
                COALESCE(MAX(s.browse_type), MAX(h.category)) AS category, MAX(h.last_at) AS last_at, MIN(h.first_at) AS first_at,
                SUM(h.action_count) AS action_count, GROUP_CONCAT(DISTINCT h.source ORDER BY h.source) AS sources,
                MAX(s.id IS NOT NULL) AS available
            FROM activity_history h LEFT JOIN spotnet_spots s ON s.id = h.spot_id
            WHERE h.username = ? AND h.kind = ?'
            . ($adult ? '' : ' AND h.is_erotica = 0 AND COALESCE(s.is_erotica, 0) = 0')
            . ' GROUP BY h.spot_id ORDER BY last_at DESC, h.spot_id DESC LIMIT ' . (self::PAGE_SIZE + 1) . ' OFFSET ' . $offset);
        $query->execute([$username, $kind]);
        $items = $query->fetchAll();
        $more = count($items) > self::PAGE_SIZE;
        return ['items' => array_slice($items, 0, self::PAGE_SIZE), 'more' => $more];
    }

    public function statistics(int $days, bool $adult): array
    {
        if (!in_array($days, [7, 30, 90], true)) throw new InvalidArgumentException('Ongeldige statistiekperiode.');
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $start = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $query = $this->pdo->prepare('SELECT day, kind, source, category, SUM(action_count) AS total
            FROM activity_daily WHERE day BETWEEN ? AND UTC_DATE()'
            . ($adult ? '' : ' AND is_erotica = 0')
            . ' GROUP BY day, kind, source, category ORDER BY day');
        $query->execute([$start]);
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $daily[$today->modify('-' . $i . ' days')->format('Y-m-d')] = ['view' => 0, 'download' => 0, 'users' => 0];
        }
        $categories = ['view' => [], 'download' => []];
        $sources = [];
        $totals = ['view' => 0, 'download' => 0];
        foreach ($query->fetchAll() as $row) {
            $kind = $row['kind'];
            $count = (int) $row['total'];
            $daily[$row['day']][$kind] += $count;
            $totals[$kind] += $count;
            $categories[$kind][$row['category']] = ($categories[$kind][$row['category']] ?? 0) + $count;
            if ($kind === 'download') $sources[$row['source']] = ($sources[$row['source']] ?? 0) + $count;
        }
        foreach ($categories as &$counts) arsort($counts);
        unset($counts);
        arsort($sources);
        $users = $this->pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(active = 1), 0) AS active FROM app_users')->fetch();
        $newUsers = $this->pdo->prepare('SELECT DATE(created_at) AS day, COUNT(*) AS total FROM app_users
            WHERE created_at >= ? AND created_at < UTC_DATE() + INTERVAL 1 DAY GROUP BY DATE(created_at)');
        $newUsers->execute([$start]);
        foreach ($newUsers->fetchAll() as $row) $daily[$row['day']]['users'] = (int) $row['total'];
        return ['days' => $days, 'daily' => $daily, 'categories' => $categories, 'sources' => $sources,
            'totals' => $totals, 'users' => $users];
    }
}
