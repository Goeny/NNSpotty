<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use PDO;
use RuntimeException;

final class Repository
{
    public function __construct(private readonly PDO $pdo) {}

    public function enabled(): bool
    {
        $value = $this->pdo->query('SELECT enabled FROM music_settings WHERE id = 1')->fetchColumn();
        if ($value === false) throw new RuntimeException('Muziekinstellingen ontbreken. Voer db migrate uit.');
        return (bool) $value;
    }

    public function enable(bool $enabled): void
    {
        $this->pdo->prepare('UPDATE music_settings SET enabled = ? WHERE id = 1')->execute([(int) $enabled]);
    }

    public function preference(string $username): bool
    {
        $query = $this->pdo->prepare('SELECT enabled FROM music_preferences WHERE username = ?');
        $query->execute([$username]);
        return (bool) $query->fetchColumn();
    }

    public function savePreference(string $username, bool $enabled): void
    {
        $this->pdo->prepare('INSERT INTO music_preferences (username, enabled) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')->execute([$username, (int) $enabled]);
    }

    public function allowed(string $username): bool
    {
        return $this->enabled() && $this->preference($username);
    }

    public function cached(string $key): ?array
    {
        $query = $this->pdo->prepare('SELECT data_json FROM music_cache WHERE cache_key = ? AND expires_at > UTC_TIMESTAMP()');
        $query->execute([hash('sha256', $key)]);
        $value = $query->fetchColumn();
        return $value === false ? null : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    public function cache(string $key, array $value, int $seconds): void
    {
        $this->pdo->prepare('INSERT INTO music_cache VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), expires_at = VALUES(expires_at)')
            ->execute([hash('sha256', $key), json_encode($value, JSON_THROW_ON_ERROR), $seconds]);
    }

    public function match(string $id): ?array
    {
        $query = $this->pdo->prepare('SELECT *, expires_at > UTC_TIMESTAMP() AS fresh FROM music_matches WHERE spot_id = ?');
        $query->execute([$id]);
        $row = $query->fetch();
        if ($row === false) return null;
        foreach (['choice_json', 'result_json'] as $field) {
            $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true, 64, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    public function result(array $spot, array $value, int $seconds): void
    {
        $this->pdo->prepare('INSERT INTO music_matches (spot_id, input_hash, result_json, expires_at)
            VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
            ON DUPLICATE KEY UPDATE input_hash = VALUES(input_hash), result_json = VALUES(result_json), expires_at = VALUES(expires_at)')
            ->execute([$spot['id'], Release::matchSignature($spot), json_encode($value, JSON_THROW_ON_ERROR), $seconds]);
    }

    public function choose(array $spot, ?array $choice): void
    {
        $this->pdo->prepare('INSERT INTO music_matches (spot_id, input_hash, choice_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE input_hash = VALUES(input_hash), choice_json = VALUES(choice_json), result_json = NULL, expires_at = NULL')
            ->execute([$spot['id'], Release::signature($spot), $choice === null ? null : json_encode($choice, JSON_THROW_ON_ERROR)]);
    }

    public function previewMetadata(array $spot, string $username): ?array
    {
        if (!$this->allowed($username) || Release::parse($spot) === null) return null;
        $row = $this->match($spot['id']);
        return $row !== null && $row['fresh'] && $row['input_hash'] === Release::matchSignature($spot)
            && !(($row['choice_json']['input'] ?? '') === Release::signature($spot) && ($row['choice_json']['blocked'] ?? false))
            && ($row['result_json']['state'] ?? '') === 'matched'
            ? $row['result_json']['metadata'] : null;
    }

    public function lock(string $key): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute(['music:' . substr(hash('sha256', $key), 0, 58)]);
        return (int) $query->fetchColumn() === 1;
    }

    public function unlock(string $key): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute(['music:' . substr(hash('sha256', $key), 0, 58)]);
        if ((int) $query->fetchColumn() !== 1) throw new RuntimeException('MusicBrainz-lock kon niet worden vrijgegeven.');
    }

    public function claimRequest(): void
    {
        // Lock is installation-wide and the timestamp survives requests/workers and cache cleanup.
        if (!$this->lock('request-pacing')) throw new RuntimeException('MusicBrainz-aanvraaglimiet is bezet; probeer later opnieuw.');
        try {
            $elapsed = $this->pdo->query('SELECT TIMESTAMPDIFF(MICROSECOND, last_request_at, UTC_TIMESTAMP(6)) FROM music_settings WHERE id = 1')->fetchColumn();
            if ($elapsed !== null && (int) $elapsed < 1100000) usleep(min(1100000, max(0, 1100000 - (int) $elapsed)));
            $this->pdo->exec('UPDATE music_settings SET last_request_at = UTC_TIMESTAMP(6) WHERE id = 1');
        } finally {
            $this->unlock('request-pacing');
        }
    }
}
