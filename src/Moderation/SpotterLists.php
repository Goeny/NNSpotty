<?php

declare(strict_types=1);

namespace NnSpotty\Moderation;

use PDO;

final class SpotterLists
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{enabled: int, updated_at: ?string, attempted_at: ?string, last_error: ?string, last_warning: ?string} */
    public function settings(): array
    {
        $row = $this->pdo->query('SELECT enabled, updated_at, attempted_at, last_error, last_warning FROM spotter_list_settings WHERE id = 1')->fetch();
        if (!is_array($row)) throw new \RuntimeException('Spotterlijstinstellingen ontbreken. Voer db migrate uit.');
        $row['enabled'] = (int) $row['enabled'];
        return $row;
    }

    public function enable(bool $enabled): void
    {
        $this->pdo->prepare('UPDATE spotter_list_settings SET enabled = :enabled WHERE id = 1')
            ->execute(['enabled' => (int) $enabled]);
    }

    /** @return array<string, string> */
    public function entries(): array
    {
        $rows = $this->pdo->query("SELECT key_hash, MAX(list_type = 'blacklist') AS blocked FROM spotter_keys GROUP BY key_hash")->fetchAll();
        $result = [];
        foreach ($rows as $row) $result[$row['key_hash']] = $row['blocked'] ? 'blacklist' : 'whitelist';
        return $result;
    }

    /** @return array<string, int|string> */
    public function totals(): array
    {
        return $this->pdo->query('SELECT list_type, COUNT(*) AS total FROM spotter_keys GROUP BY list_type')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string, string> */
    public static function parse(string $text, ?array &$warnings = null): array
    {
        $warnings = [];
        $xml = SpotterIdentity::xml($text, 'Keys', 4 * 1024 * 1024);
        $result = [];
        if (count($xml->children()) > 20000 || count($xml->children()) === 0) {
            throw new \UnexpectedValueException('Spotlist bevat geen sleutels of te veel sleutels.');
        }
        foreach ($xml->children() as $node) {
            $name = trim((string) $node['Name']);
            if ($node->getName() !== 'Key' || count($node->children()) !== 0
                || $name === '' || strlen($name) > 191 || preg_match('//u', $name) !== 1) {
                throw new \UnexpectedValueException('Ongeldige Spotlist-vermelding.');
            }
            try {
                $result[hash('sha256', SpotterIdentity::modulus((string) $node))] = $name;
            } catch (\UnexpectedValueException) {
                $warnings[] = $name;
            }
        }
        if ($result === []) throw new \UnexpectedValueException('Spotlist bevat geen geldige sleutels; vorige lijst blijft behouden.');
        return $result;
    }

    /** @param null|callable(string): string $fetch */
    public function sync(bool $force = false, ?callable $fetch = null): bool
    {
        $lockName = 'nnspotty:spotlists:' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute(['name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new \RuntimeException('Spotterlijsten worden al bijgewerkt.');
        try {
            $settings = $this->settings();
            if (!$force && (!$settings['enabled'] || ($settings['updated_at'] !== null
                && strtotime($settings['updated_at'] . ' UTC') > time() - 86400))) return false;
            $this->pdo->exec('UPDATE spotter_list_settings SET attempted_at = UTC_TIMESTAMP() WHERE id = 1');
            $fetch ??= (new SpotlistClient())->fetch(...);
            $snapshots = [];
            $warnings = [];
            foreach (array_keys(SpotlistClient::URLS) as $type) {
                $snapshots[$type] = self::parse($fetch($type), $invalidNames);
                if ($invalidNames !== []) {
                    $warnings[] = $type . ': ' . count($invalidNames) . ' ongeldige sleutelvermeldingen overgeslagen ('
                        . implode(', ', array_slice($invalidNames, 0, 10)) . ').';
                }
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec('DELETE FROM spotter_keys');
                $insert = $this->pdo->prepare('INSERT INTO spotter_keys (list_type, key_hash, display_name) VALUES (:type, :hash, :name)');
                foreach ($snapshots as $type => $entries) {
                    foreach ($entries as $hash => $name) $insert->execute(['type' => $type, 'hash' => $hash, 'name' => $name]);
                }
                $this->pdo->prepare('UPDATE spotter_list_settings SET updated_at = UTC_TIMESTAMP(), last_error = NULL, last_warning = :warning WHERE id = 1')
                    ->execute(['warning' => $warnings === [] ? null : implode(' ', $warnings)]);
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
            return true;
        } catch (\Throwable $exception) {
            $this->pdo->prepare('UPDATE spotter_list_settings SET last_error = :error WHERE id = 1')
                ->execute(['error' => substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
            if ((int) $release->fetchColumn() !== 1) throw new \RuntimeException('Spotterlijst-lock kon niet worden vrijgegeven.');
        }
    }
}
