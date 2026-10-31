<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use PDO;

final class NzbgetRepository implements ConnectionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{url: string, control_username: string, control_password: string, enabled: bool, configured: bool, mappings: array<string,string>} */
    public function settings(string $scope): array
    {
        $select = $this->pdo->prepare('SELECT url, control_username, control_password, enabled FROM nzbget_connections WHERE scope = ?');
        $select->execute([$scope]);
        $settings = $select->fetch();
        return [
            'url' => $settings['url'] ?? '', 'control_username' => $settings['control_username'] ?? '',
            'control_password' => $settings['control_password'] ?? '',
            'enabled' => (bool) ($settings['enabled'] ?? false), 'configured' => $settings !== false,
            'mappings' => CategoryMapping::load($this->pdo, 'nzbget_category_mapping'),
        ];
    }

    public function save(string $scope, ?string $username, string $url, string $controlUsername, string $password, bool $enabled, bool $admin): void
    {
        $url = Endpoint::normalize($url);
        $controlUsername = trim($controlUsername);
        if (preg_match('/^[^\x00-\x1f\x7f:]{1,100}$/uD', $controlUsername) !== 1) {
            throw new InvalidArgumentException('Voer een geldige NZBGet-gebruikersnaam in (maximaal 100 tekens, zonder dubbele punt).');
        }
        if ($password === '') {
            $password = $this->settings($scope)['control_password'];
        }
        if (preg_match('/^[^\x00-\x1f\x7f]{1,255}$/uD', $password) !== 1) {
            throw new InvalidArgumentException('Voer het NZBGet-wachtwoord in (maximaal 255 tekens).');
        }
        Endpoint::resolve($url, $admin, '/jsonrpc');
        $save = $this->pdo->prepare('INSERT INTO nzbget_connections (scope, username, url, control_username, control_password, enabled)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE url = VALUES(url), control_username = VALUES(control_username),
                control_password = VALUES(control_password), enabled = VALUES(enabled)');
        $save->execute([$scope, $username, $url, $controlUsername, $password, (int) $enabled]);
    }

    public function delete(string $scope): void
    {
        $this->pdo->prepare('DELETE FROM nzbget_connections WHERE scope = ?')->execute([$scope]);
    }

    /** @param array<string, string> $mapping */
    public function saveMappings(array $mapping): void
    {
        CategoryMapping::save($this->pdo, 'nzbget_category_mapping', $mapping, 'NZBGet');
    }

    public function category(array $spot, array $settings): string
    {
        return CategoryMapping::category($spot, $settings);
    }

    public function client(array $settings, bool $internalAllowed): NzbgetClient
    {
        return new NzbgetClient($settings['url'], $settings['control_username'], $settings['control_password'], $internalAllowed);
    }

    public function revisionSecret(array $settings): string
    {
        return $settings['control_username'] . "\0" . $settings['control_password'];
    }
}
