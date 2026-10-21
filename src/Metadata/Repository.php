<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use PDO;

final class Repository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function settings(): array
    {
        $row = $this->pdo->query('SELECT * FROM tvdb_settings WHERE id = 1')->fetch();
        return $row === false ? ['enabled' => false, 'api_key' => '', 'pin' => '', 'language' => 'nld', 'token' => null, 'token_until' => null] : $row;
    }

    public function saveSettings(bool $enabled, string $key, string $pin, string $language, bool $clearPin = false): void
    {
        $old = $this->settings();
        $key = trim($key) === '' ? $old['api_key'] : trim($key);
        $pin = $clearPin ? '' : (trim($pin) === '' ? $old['pin'] : trim($pin));
        if (($enabled && $key === '') || strlen($key) > 255 || strlen($pin) > 255
            || preg_match('/[\x00-\x20\x7f]/', $key . $pin)
            || !in_array($language, ['nld', 'eng'], true)) {
            throw new InvalidArgumentException('Voer een geldige TVDB-key, optionele abonnee-PIN en taal in.');
        }
        $save = $this->pdo->prepare('INSERT INTO tvdb_settings (id, enabled, api_key, pin, language) VALUES (1, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), api_key = VALUES(api_key), pin = VALUES(pin),
            language = VALUES(language), token = NULL, token_until = NULL');
        $save->execute([(int) $enabled, $key, $pin, $language]);
        if ($old['api_key'] !== $key || $old['pin'] !== $pin || $old['language'] !== $language) {
            $this->pdo->exec('UPDATE tvdb_matches SET expires_at = NULL');
        }
    }

    public function preference(string $username): bool
    {
        $query = $this->pdo->prepare('SELECT enabled FROM tvdb_preferences WHERE username = ?');
        $query->execute([$username]);
        return (bool) $query->fetchColumn();
    }

    public function savePreference(string $username, bool $enabled): void
    {
        $query = $this->pdo->prepare('INSERT INTO tvdb_preferences (username, enabled) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
        $query->execute([$username, (int) $enabled]);
    }

    public function allowed(string $username): bool
    {
        return ($this->providerEnabled('tvdb') || $this->providerEnabled('tmdb')) && $this->preference($username);
    }

    public function tmdbSettings(): array
    {
        $row = $this->pdo->query('SELECT enabled, token, language FROM tmdb_settings WHERE id = 1')->fetch();
        return $row === false ? ['enabled' => false, 'token' => '', 'language' => 'nl-NL']
            : ['enabled' => (bool) $row['enabled'], 'token' => $row['token'], 'language' => $row['language']];
    }

    public function saveTmdbSettings(bool $enabled, string $token, string $language, bool $clear = false): void
    {
        $old = $this->tmdbSettings();
        $token = $clear ? '' : (trim($token) === '' ? $old['token'] : trim($token));
        if (($enabled && $token === '') || strlen($token) > 2000 || preg_match('/[^A-Za-z0-9._-]/', $token)
            || !in_array($language, ['nl-NL', 'en-US'], true)) {
            throw new InvalidArgumentException('Voer een geldige TMDB API-key of Read Access Token en taal in.');
        }
        $this->pdo->prepare('INSERT INTO tmdb_settings (id, enabled, token, language) VALUES (1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), token = VALUES(token), language = VALUES(language)')
            ->execute([(int) $enabled, $token, $language]);
        if ($old['token'] !== $token || $old['language'] !== $language || $old['enabled'] !== $enabled) {
            $this->pdo->exec('UPDATE tvdb_matches SET expires_at = NULL');
        }
    }

    public function providerEnabled(string $provider): bool
    {
        if ($provider === 'tmdb') {
            $settings = $this->tmdbSettings();
            return $settings['enabled'] && $settings['token'] !== '';
        }
        $settings = $this->settings();
        return $provider === 'tvdb' && (bool) $settings['enabled'] && $settings['api_key'] !== '';
    }

    /** @return array{movie: string, series: string} */
    public function providers(): array
    {
        $providers = ['movie' => 'tmdb', 'series' => 'tvdb'];
        foreach ($this->pdo->query('SELECT media_type, provider FROM metadata_providers')->fetchAll() as $row) {
            if (isset($providers[$row['media_type']]) && in_array($row['provider'], ['tvdb', 'tmdb'], true)) $providers[$row['media_type']] = $row['provider'];
        }
        return $providers;
    }

    public function saveProvider(string $type, string $provider): void
    {
        if (!in_array($type, ['movie', 'series'], true) || !in_array($provider, ['tvdb', 'tmdb'], true)) {
            throw new InvalidArgumentException('Kies TMDB of TVDB als primaire provider.');
        }
        $this->pdo->prepare('INSERT INTO metadata_providers (media_type, provider) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE provider = VALUES(provider)')->execute([$type, $provider]);
        $this->pdo->exec('UPDATE tvdb_matches SET expires_at = NULL');
    }

    /** Configured primary provider, or the other provider when the configured one is not available. */
    public function effectiveProvider(string $type): ?string
    {
        $primary = $this->providers()[$type] ?? 'tvdb';
        $secondary = $primary === 'tvdb' ? 'tmdb' : 'tvdb';
        return $this->providerEnabled($primary) ? $primary : ($this->providerEnabled($secondary) ? $secondary : null);
    }

    /** Everything that changes the shared match result; part of the match signature. */
    public function profile(): string
    {
        $tvdb = $this->settings();
        $tmdb = $this->tmdbSettings();
        $enabled = ['tvdb' => (bool) $tvdb['enabled'] && $tvdb['api_key'] !== '', 'tmdb' => $tmdb['enabled'] && $tmdb['token'] !== ''];
        $effective = [];
        foreach ($this->providers() as $type => $primary) {
            $secondary = $primary === 'tvdb' ? 'tmdb' : 'tvdb';
            $effective[] = $enabled[$primary] ? $primary : ($enabled[$secondary] ? $secondary : '-');
        }
        return implode('|', [$tvdb['language'], $tmdb['language'], ...$effective,
            $enabled['tvdb'] ? 'tvdb' : '', $enabled['tmdb'] ? 'tmdb' : '']);
    }

    public function token(string $key, string $pin, ?string $token): void
    {
        $query = $this->pdo->prepare('UPDATE tvdb_settings SET token = ?, token_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 25 DAY)
            WHERE id = 1 AND api_key = ? AND pin = ?');
        $query->execute([$token, $key, $pin]);
    }

    public function claimRequest(string $provider = 'tvdb'): void
    {
        // TVDB budget stays at 90/min; TMDB allows ~50/s, keep a modest 240/min per installation.
        $limit = $provider === 'tmdb' ? 240 : 90;
        $name = $provider === 'tmdb' ? 'TMDB' : 'TVDB';
        $scope = $provider === 'tmdb' ? 'tmdb-request-budget' : 'request-budget';
        if (!$this->lock($scope)) throw new \RuntimeException($name . '-aanvraaglimiet is bezet. Probeer later opnieuw.');
        try {
            $key = $scope . ':' . gmdate('YmdHi');
            $count = (int) ($this->cached($key)['count'] ?? 0);
            if ($count >= $limit) throw new \RuntimeException($name . '-aanvraaglimiet bereikt (' . $limit . ' per minuut voor deze installatie).');
            $this->cache($key, ['count' => $count + 1], 120);
        } finally {
            $this->unlock($scope);
        }
    }

    public function cached(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT data_json FROM tvdb_cache WHERE cache_key = ? AND expires_at > UTC_TIMESTAMP()');
        $query->execute([hash('sha256', $key)]);
        $json = $query->fetchColumn();
        return $json === false ? null : json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    public function cache(string $key, array $data, int $seconds): void
    {
        $query = $this->pdo->prepare('INSERT INTO tvdb_cache (cache_key, data_json, expires_at)
            VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), expires_at = VALUES(expires_at)');
        $query->execute([hash('sha256', $key), json_encode($data, JSON_THROW_ON_ERROR), $seconds]);
    }

    public function match(string $id): ?array
    {
        $query = $this->pdo->prepare('SELECT *, (expires_at > UTC_TIMESTAMP()) AS fresh FROM tvdb_matches WHERE spot_id = ?');
        $query->execute([$id]);
        $row = $query->fetch();
        if ($row === false) return null;
        foreach (['choice_json', 'result_json'] as $field) {
            $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 64, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    public function previewMetadata(array $spot, string $username): ?array
    {
        if (!$this->allowed($username)) return null;
        $match = $this->match($spot['id']);
        if ($match === null || !$match['fresh'] || ($match['choice_json']['blocked'] ?? false)
            || $match['input_hash'] !== Service::matchSignature($spot, $this->profile())
            || ($match['result_json']['state'] ?? null) !== 'matched') return null;
        return $match['result_json']['metadata'];
    }

    public function result(string $id, string $hash, array $result, int $seconds): void
    {
        $query = $this->pdo->prepare('INSERT INTO tvdb_matches (spot_id, input_hash, result_json, expires_at)
            VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE input_hash = VALUES(input_hash), result_json = VALUES(result_json), expires_at = VALUES(expires_at)');
        $query->execute([$id, $hash, json_encode($result, JSON_THROW_ON_ERROR), $seconds]);
    }

    public function choose(string $id, string $hash, ?array $choice): void
    {
        $query = $this->pdo->prepare('INSERT INTO tvdb_matches (spot_id, input_hash, choice_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE input_hash = VALUES(input_hash), choice_json = VALUES(choice_json), result_json = NULL, expires_at = NULL');
        $query->execute([$id, $hash, $choice === null ? null : json_encode($choice, JSON_THROW_ON_ERROR)]);
    }

    public function lock(string $key): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute(['tvdb:' . substr(hash('sha256', $key), 0, 58)]);
        return (int) $query->fetchColumn() === 1;
    }

    public function unlock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute(['tvdb:' . substr(hash('sha256', $key), 0, 58)]);
    }
}
