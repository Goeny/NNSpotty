<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use PDO;

/** A target is a connection slot plus client type, for example "personal:nzbget". */
final class Targets
{
    public const CLIENTS = ['sabnzbd' => 'SABnzbd', 'nzbget' => 'NZBGet'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function client(string $client): string
    {
        if (!isset(self::CLIENTS[$client])) {
            throw new InvalidArgumentException('Onbekende downloadclient.');
        }
        return $client;
    }

    /** @return list<string> */
    public static function permitted(bool $admin): array
    {
        $targets = [];
        foreach ($admin ? ['personal', 'central'] : ['personal'] as $connection) {
            foreach (array_keys(self::CLIENTS) as $client) {
                $targets[] = $connection . ':' . $client;
            }
        }
        return $targets;
    }

    /** @return array{connection: string, client: string} */
    public static function parse(string $target, bool $admin): array
    {
        if (!in_array($target, self::permitted($admin), true)) {
            throw new InvalidArgumentException('Deze downloadclient-koppeling is niet beschikbaar voor jouw account.');
        }
        [$connection, $client] = explode(':', $target, 2);
        return ['connection' => $connection, 'client' => $client];
    }

    public static function label(string $target): string
    {
        [$connection, $client] = explode(':', $target, 2) + [1 => 'sabnzbd'];
        return ($connection === 'central' ? 'Centrale ' : 'Mijn ') . (self::CLIENTS[$client] ?? $client)
            . ($connection === 'central' ? ' (admin)' : '');
    }

    public static function repository(PDO $pdo, string $client): SabRepository|NzbgetRepository
    {
        return self::client($client) === 'nzbget' ? new NzbgetRepository($pdo) : new SabRepository($pdo);
    }

    /**
     * Permitted targets with their enabled state, plus the effective default: the saved preference when it is
     * enabled, otherwise the first enabled target. Without enabled targets the saved/first target is kept.
     *
     * @return array{targets: list<array{id: string, label: string, client: string, enabled: bool}>, default: string}
     */
    public function overview(string $username, bool $admin): array
    {
        $enabled = [];
        foreach (['sabnzbd' => 'sabnzbd_connections', 'nzbget' => 'nzbget_connections'] as $client => $table) {
            $select = $this->pdo->prepare('SELECT scope FROM ' . $table . ' WHERE enabled = 1 AND scope IN (?, ?)');
            $select->execute(['user:' . $username, $admin ? 'central' : 'user:' . $username]);
            foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $scope) {
                $enabled[($scope === 'central' ? 'central' : 'personal') . ':' . $client] = true;
            }
        }
        $targets = [];
        foreach (self::permitted($admin) as $target) {
            $targets[] = ['id' => $target, 'label' => self::label($target), 'client' => explode(':', $target)[1],
                'enabled' => isset($enabled[$target])];
        }
        $preferred = $this->preference($username);
        $permitted = $preferred !== null && in_array($preferred, self::permitted($admin), true);
        $default = $permitted && isset($enabled[$preferred]) ? $preferred : null;
        foreach ($targets as $target) {
            if ($default === null && $target['enabled']) {
                $default = $target['id'];
            }
        }
        return ['targets' => $targets, 'default' => $default ?? ($permitted ? $preferred : 'personal:sabnzbd')];
    }

    public function preference(string $username): ?string
    {
        $select = $this->pdo->prepare('SELECT target FROM downloader_preferences WHERE username = ?');
        $select->execute([$username]);
        $target = $select->fetchColumn();
        return is_string($target) ? $target : null;
    }

    public function saveDefault(string $username, string $target, bool $admin): void
    {
        self::parse($target, $admin);
        $this->pdo->prepare('INSERT INTO downloader_preferences (username, target) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE target = VALUES(target)')->execute([$username, $target]);
    }
}
