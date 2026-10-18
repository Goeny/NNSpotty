<?php

declare(strict_types=1);

namespace NnSpotty\Security;

use PDO;

final class LoginRateLimiter
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isBlocked(string $ipAddress, string $username): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS failed_count
             FROM login_attempts
             WHERE success = 0
               AND attempted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)
               AND (ip_address = :ip_address OR username = :username)'
        );
        $statement->execute([
            'ip_address' => $ipAddress,
            'username' => $username,
        ]);
        $row = $statement->fetch();
        $failedCount = is_array($row) ? (int) ($row['failed_count'] ?? 0) : 0;

        return $failedCount >= self::MAX_FAILED_ATTEMPTS;
    }

    public function registerAttempt(string $ipAddress, string $username, bool $success): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (username, ip_address, success, attempted_at)
             VALUES (:username, :ip_address, :success, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'username' => $username,
            'ip_address' => $ipAddress,
            'success' => $success ? 1 : 0,
        ]);
    }

    public function lockoutMessage(): string
    {
        return 'Te veel mislukte loginpogingen. Probeer het over 15 minuten opnieuw.';
    }
}
