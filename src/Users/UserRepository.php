<?php

declare(strict_types=1);

namespace NnSpotty\Users;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $username, string $password, bool $isAdmin): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, is_admin, created_at, updated_at)
             VALUES (:username, :password_hash, :is_admin, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => $isAdmin ? 1 : 0,
        ]);
    }

    public function delete(string $username): int
    {
        $statement = $this->pdo->prepare('DELETE FROM app_users WHERE username = :username');
        $statement->execute(['username' => $username]);

        return $statement->rowCount();
    }

    public function recordBrowseVisit(string $username): string
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare('INSERT INTO user_browse_visits (username, last_visit_at)
                VALUES (:username, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE username = VALUES(username)');
            $insert->execute(['username' => $username]);
            $select = $this->pdo->prepare('SELECT last_visit_at FROM user_browse_visits WHERE username = :username FOR UPDATE');
            $select->execute(['username' => $username]);
            $previous = $select->fetchColumn();
            if (!is_string($previous)) throw new \RuntimeException('Vorig spotbezoek kon niet worden gelezen.');
            $update = $this->pdo->prepare('UPDATE user_browse_visits
                SET last_visit_at = GREATEST(last_visit_at, UTC_TIMESTAMP(6)) WHERE username = :username');
            $update->execute(['username' => $username]);
            $this->pdo->commit();
            return $previous;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function updatePassword(string $username, string $password): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_users
             SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP()
             WHERE username = :username'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return $statement->rowCount();
    }

    public function setAdmin(string $username, bool $isAdmin): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_users
             SET is_admin = :is_admin, updated_at = UTC_TIMESTAMP()
             WHERE username = :username'
        );
        $statement->execute([
            'username' => $username,
            'is_admin' => $isAdmin ? 1 : 0,
        ]);

        return $statement->rowCount();
    }

    /**
     * @return array<int, array{username: string, is_admin: int, created_at: string}>
     */
    public function list(): array
    {
        $statement = $this->pdo->query(
            'SELECT username, is_admin, DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at
             FROM app_users
             ORDER BY username ASC'
        );
        $rows = $statement->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'username' => (string) $row['username'],
                'is_admin' => (int) $row['is_admin'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * @return array{username: string, password_hash: string, is_admin: int}|null
     */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT username, password_hash, is_admin
             FROM app_users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'is_admin' => (int) $row['is_admin'],
        ];
    }
}
