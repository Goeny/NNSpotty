<?php

declare(strict_types=1);

namespace NnSpotty\Newznab;

use PDO;

final class ApiKeys
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exists(string $username): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM user_api_keys WHERE username = :username');
        $query->execute(['username' => $username]);
        return $query->fetchColumn() !== false;
    }

    public function rotate(string $username): string
    {
        $key = bin2hex(random_bytes(32));
        $query = $this->pdo->prepare('INSERT INTO user_api_keys (username, key_hash, created_at, window_start, request_count)
            VALUES (:username, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
            ON DUPLICATE KEY UPDATE key_hash = VALUES(key_hash), created_at = VALUES(created_at), request_count = 0');
        $query->execute(['username' => $username, 'hash' => hash('sha256', $key)]);
        return $key;
    }

    public function revoke(string $username): void
    {
        $query = $this->pdo->prepare('DELETE FROM user_api_keys WHERE username = :username');
        $query->execute(['username' => $username]);
    }

    public function authenticate(string $key): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            return null;
        }
        $query = $this->pdo->prepare('SELECT username FROM user_api_keys WHERE key_hash = :hash');
        $query->execute(['hash' => hash('sha256', $key)]);
        $username = $query->fetchColumn();
        if (!is_string($username)) return null;
        $user = (new \NnSpotty\Users\UserRepository($this->pdo))->findByUsername($username);
        return \NnSpotty\Users\Permissions::allows($user, 'api.use') ? $username : null;
    }

    public function allowRequest(string $username): bool
    {
        $query = $this->pdo->prepare('UPDATE user_api_keys
            SET request_count = IF(window_start <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), 1, request_count + 1),
                window_start = IF(window_start <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), UTC_TIMESTAMP(), window_start)
            WHERE username = :username
              AND (window_start <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) OR request_count < 120)');
        $query->execute(['username' => $username]);
        return $query->rowCount() === 1;
    }
}
