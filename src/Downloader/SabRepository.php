<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use PDO;

final class SabRepository implements ConnectionRepository
{
    public const TYPES = ConnectionRepository::TYPES;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function scope(string $username, string $selection, bool $admin): string
    {
        if ($selection === 'central' && $admin) {
            return 'central';
        }
        if ($selection !== 'personal') {
            throw new InvalidArgumentException('Geen toegang tot deze downloadclient-koppeling.');
        }
        return 'user:' . $username;
    }

    /** @return array{url: string, api_key: string, enabled: bool, configured: bool, mappings: array<string,string>} */
    public function settings(string $scope): array
    {
        $select = $this->pdo->prepare('SELECT url, api_key, enabled FROM sabnzbd_connections WHERE scope = ?');
        $select->execute([$scope]);
        $settings = $select->fetch();
        return [
            'url' => $settings['url'] ?? '', 'api_key' => $settings['api_key'] ?? '',
            'enabled' => (bool) ($settings['enabled'] ?? false), 'configured' => $settings !== false,
            'mappings' => CategoryMapping::load($this->pdo, 'sabnzbd_category_mapping'),
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
        CategoryMapping::save($this->pdo, 'sabnzbd_category_mapping', $mapping, 'SABnzbd');
    }

    public function category(array $spot, array $settings): string
    {
        return CategoryMapping::category($spot, $settings);
    }

    public function client(array $settings, bool $internalAllowed): SabClient
    {
        return new SabClient($settings['url'], $settings['api_key'], $internalAllowed);
    }

    public function revisionSecret(array $settings): string
    {
        return $settings['api_key'];
    }

    /** @return list<array<string,mixed>> */
    public function history(string $scope, bool $adult): array
    {
        return (new DownloadHistory($this->pdo))->history($scope, 'sabnzbd', $adult);
    }

    /** @return array<string,mixed> */
    public function begin(string $scope, string $username, array $spot, string $category, string $token): array
    {
        return (new DownloadHistory($this->pdo))->begin($scope, 'sabnzbd', $username, $spot, $category, $token);
    }

    public function finish(int $id, string $status, ?string $nzoId = null): void
    {
        (new DownloadHistory($this->pdo))->finish($id, $status, $nzoId);
    }
}
