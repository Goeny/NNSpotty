<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use NnSpotty\Usenet\SpotCategories;
use PDO;

final class SabRepository
{
    public const TYPES = ['Films', 'Series', 'Boeken', 'Erotica', 'Afbeeldingen', 'Muziek', 'Livesets', 'Podcasts', 'Luisterboeken', 'Spellen', 'Applicaties', 'Overig'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function scope(string $username, string $selection, bool $admin): string
    {
        if ($selection === 'central' && $admin) {
            return 'central';
        }
        if ($selection !== 'personal') {
            throw new InvalidArgumentException('Geen toegang tot deze SABnzbd-koppeling.');
        }
        return 'user:' . $username;
    }

    /** @return array{url: string, api_key: string, enabled: bool, mappings: array<string,string>} */
    public function settings(string $scope): array
    {
        $select = $this->pdo->prepare('SELECT url, api_key, enabled FROM sabnzbd_connections WHERE scope = ?');
        $select->execute([$scope]);
        $settings = $select->fetch();
        $mapping = $this->pdo->prepare("SELECT type, category FROM sabnzbd_category_mapping");
        $mapping->execute();
        return [
            'url' => $settings['url'] ?? '', 'api_key' => $settings['api_key'] ?? '',
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'mappings' => array_column($mapping->fetchAll(), 'category', 'type'),
        ];
    }

    public function save(string $scope, ?string $username, string $url, string $key, bool $enabled, bool $admin): void
    {
        $url = Endpoint::normalize($url);
        if ($key === '') {
            $key = $this->settings($scope)['api_key'];
        }
        if (preg_match('/^[a-zA-Z0-9]{16,128}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Voer een geldige volledige SABnzbd API-key in (niet alleen de NZB-key).');
        }
        Endpoint::resolve($url, $admin);
        $save = $this->pdo->prepare('INSERT INTO sabnzbd_connections (scope, username, url, api_key, enabled)
            VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE url = VALUES(url), api_key = VALUES(api_key), enabled = VALUES(enabled)');
        $save->execute([$scope, $username, $url, $key, (int) $enabled]);
    }

    public function delete(string $scope): void
    {
        $this->pdo->prepare('DELETE FROM sabnzbd_connections WHERE scope = ?')->execute([$scope]);
    }

    /** @param array<string, string> $mapping */
    public function saveMappings(array $mapping): void
    {
        if (array_diff(array_keys($mapping), self::TYPES) !== []) {
            throw new InvalidArgumentException('Onbekend spottype in SABnzbd-mapping.');
        }
        foreach ($mapping as $category) {
            if (!is_string($category) || strlen($category) > 100 || preg_match('/[\x00-\x1f\x7f]/', $category)) {
                throw new InvalidArgumentException('Ongeldige SABnzbd-categorienaam.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM sabnzbd_category_mapping');
            $insert = $this->pdo->prepare('INSERT INTO sabnzbd_category_mapping (type, category) VALUES (?, ?)');
            foreach ($mapping as $type => $category) {
                $insert->execute([$type, trim($category)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function category(array $spot, array $settings): string
    {
        $type = SpotCategories::classify($spot['category'], $spot['subcategories'])['type'];
        return $settings['mappings'][$type] ?? '';
    }

    /** @return list<array<string,mixed>> */
    public function history(string $scope, bool $adult): array
    {
        $select = $this->pdo->prepare('SELECT h.id, h.title, h.category, h.status, h.nzo_id, h.created_at
            FROM sabnzbd_downloads h JOIN spotnet_spots s ON s.id = h.spot_id
            WHERE h.scope = ?' . ($adult ? '' : ' AND s.is_erotica = 0') . ' ORDER BY h.id DESC LIMIT 30');
        $select->execute([$scope]);
        return $select->fetchAll();
    }

    /** @return array<string,mixed> */
    public function begin(string $scope, string $username, array $spot, string $category, string $token): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Ongeldige downloadbevestiging.');
        }
        $insert = $this->pdo->prepare("INSERT INTO sabnzbd_downloads (scope, username, spot_id, title, category, token, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'sending', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $insert->execute([$scope, $username, $spot['id'], $spot['title'], $category, $token]);
        $fresh = $insert->rowCount() === 1;
        $select = $this->pdo->prepare('SELECT id, status, nzo_id, spot_id FROM sabnzbd_downloads WHERE scope = ? AND token = ?');
        $select->execute([$scope, $token]);
        $row = $select->fetch();
        if ($row['spot_id'] !== $spot['id']) {
            throw new InvalidArgumentException('Downloadbevestiging hoort bij een andere spot.');
        }
        return $row + ['fresh' => $fresh];
    }

    public function finish(int $id, string $status, ?string $nzoId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT username, spot_id, status FROM sabnzbd_downloads WHERE id = ? FOR UPDATE');
            $select->execute([$id]);
            $download = $select->fetch();
            if ($download === false) throw new InvalidArgumentException('Downloadpoging niet gevonden.');
            if ($download['status'] !== 'sent') {
                $this->pdo->prepare('UPDATE sabnzbd_downloads SET status = ?, nzo_id = ? WHERE id = ?')->execute([$status, $nzoId, $id]);
                if ($status === 'sent') {
                    $spot = (new \NnSpotty\Usenet\SpotRepository($this->pdo))->getSpot($download['spot_id'], true);
                    if ($spot === null) throw new \RuntimeException('Spot voor downloadhistorie niet gevonden.');
                    (new \NnSpotty\Activity\Repository($this->pdo))->record($download['username'], $spot, 'sabnzbd');
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
