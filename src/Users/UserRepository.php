<?php

declare(strict_types=1);

namespace NnSpotty\Users;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $username, string $password, bool $isAdmin, bool $temporary = false): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,190}$/D', $username)) {
            throw new \InvalidArgumentException('Loginnaam: 1-191 letters, cijfers, punt, @, underscore of koppelteken.');
        }
        self::validatePassword($password);
        if ($this->findByUsername($username) !== null) throw new \InvalidArgumentException('Loginnaam bestaat al.');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO app_users (username, password_hash, is_admin, must_change_password, created_at, updated_at)
                 VALUES (:username, :password_hash, :is_admin, :temporary, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'is_admin' => $isAdmin ? 1 : 0,
                'temporary' => (int) $temporary,
            ]);
            $this->pdo->prepare('INSERT INTO user_group_memberships (username, group_id)
                SELECT :username, id FROM user_groups WHERE system_key = :key')->execute([
                    'username' => $username, 'key' => $isAdmin ? 'admins' : 'users',
                ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function delete(string $username): int
    {
        return $this->changeAccount($username, static function (PDO $pdo) use ($username): int {
            $statement = $pdo->prepare('DELETE FROM app_users WHERE username = :username');
            $statement->execute(['username' => $username]);
            return $statement->rowCount();
        }, removingAdmin: true);
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

    public function updatePassword(string $username, string $password, bool $temporary = false): int
    {
        self::validatePassword($password);
        if ($this->findByUsername($username) === null) throw new \InvalidArgumentException('Gebruiker niet gevonden.');
        $statement = $this->pdo->prepare(
            'UPDATE app_users
             SET password_hash = :password_hash, must_change_password = :temporary,
                 auth_version = auth_version + 1, updated_at = UTC_TIMESTAMP()
             WHERE username = :username'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'temporary' => (int) $temporary,
        ]);

        return $statement->rowCount();
    }

    public function setAdmin(string $username, bool $isAdmin): int
    {
        $user = $this->findByUsername($username);
        if ($user === null) throw new \InvalidArgumentException('Gebruiker niet gevonden.');
        $ids = array_column($this->memberships($username), 'id');
        $adminId = (int) $this->pdo->query("SELECT id FROM user_groups WHERE system_key = 'admins'")->fetchColumn();
        $ids = array_values(array_filter($ids, static fn ($id): bool => (int) $id !== $adminId));
        if ($isAdmin) $ids[] = $adminId;
        if ($ids === []) $ids[] = (int) $this->pdo->query("SELECT id FROM user_groups WHERE system_key = 'users'")->fetchColumn();
        $this->manage($username, $user['active'], array_map('intval', $ids));
        return 1;
    }

    /** @return list<array{username: string, is_admin: int, active: bool, display_name: string, created_at: string}> */
    public function list(): array
    {
        $statement = $this->pdo->query(
            'SELECT username, is_admin, active, display_name, DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at
             FROM app_users
             ORDER BY username ASC'
        );
        $rows = $statement->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'username' => (string) $row['username'],
                'is_admin' => (int) $row['is_admin'],
                'active' => (bool) $row['active'],
                'display_name' => (string) $row['display_name'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT username, password_hash, is_admin, active, must_change_password, auth_version,
                display_name, signature, email, avatar_data IS NOT NULL AS has_avatar
             FROM app_users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $permissions = $this->pdo->prepare('SELECT DISTINCT p.permission FROM group_permissions p
            JOIN user_group_memberships m ON m.group_id = p.group_id WHERE m.username = :username');
        $permissions->execute(['username' => $username]);
        return [
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'is_admin' => (int) $row['is_admin'],
            'active' => (bool) $row['active'],
            'must_change_password' => (bool) $row['must_change_password'],
            'auth_version' => (int) $row['auth_version'],
            'display_name' => $row['display_name'] !== '' ? (string) $row['display_name'] : (string) $row['username'],
            'signature' => (string) $row['signature'],
            'email' => (string) $row['email'],
            'has_avatar' => (bool) $row['has_avatar'],
            'permissions' => $permissions->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException('Wachtwoord moet 12-72 bytes bevatten, zonder NUL-teken.');
        }
    }

    public static function profileValues(string $name, string $signature, string $email): array
    {
        $name = trim($name);
        $signature = trim(str_replace(["\r\n", "\r"], "\n", $signature));
        $email = trim($email);
        if ($name === '' || !mb_check_encoding($name . $signature, 'UTF-8') || mb_strlen($name) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $name) || mb_strlen($signature) > 200
            || substr_count($signature, "\n") > 2 || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $signature)
            || ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)))) {
            throw new \InvalidArgumentException('Naam: 1-80 tekens zonder regeleinden. Handtekening: maximaal 200 tekens / 3 regels. Vul een geldig e-mailadres in of laat het leeg.');
        }
        if (preg_match('/[^\x{0000}-\x{00ff}]/u', $name . $signature)) {
            throw new \InvalidArgumentException('Naam en handtekening moeten in de Usenet-tekenset passen; gebruik emoticoncodes in plaats van emoji.');
        }
        return ['display_name' => $name, 'signature' => $signature, 'email' => $email];
    }

    public function saveProfile(string $username, string $name, string $signature, string $email): void
    {
        if ($this->findByUsername($username) === null) throw new \InvalidArgumentException('Gebruiker niet gevonden.');
        $values = self::profileValues($name, $signature, $email);
        $this->pdo->prepare('UPDATE app_users SET display_name = :display_name, signature = :signature,
            email = :email, updated_at = UTC_TIMESTAMP() WHERE username = :username')->execute($values + ['username' => $username]);
    }

    public function groups(): array
    {
        $groups = $this->pdo->query('SELECT id, name, system_key FROM user_groups ORDER BY name')->fetchAll();
        foreach ($groups as &$group) {
            $statement = $this->pdo->prepare('SELECT permission FROM group_permissions WHERE group_id = ? ORDER BY permission');
            $statement->execute([$group['id']]);
            $group['permissions'] = $statement->fetchAll(PDO::FETCH_COLUMN);
        }
        return $groups;
    }

    public function memberships(string $username): array
    {
        $statement = $this->pdo->prepare('SELECT g.id, g.name, g.system_key FROM user_groups g
            JOIN user_group_memberships m ON m.group_id = g.id WHERE m.username = ? ORDER BY g.name');
        $statement->execute([$username]);
        return $statement->fetchAll();
    }

    private function changeAccount(string $username, callable $change, bool $removingAdmin): int
    {
        $this->pdo->beginTransaction();
        try {
            // Serialize admin membership/status changes, including concurrent removal of the last admin.
            $this->pdo->query("SELECT id FROM user_groups WHERE system_key = 'admins' FOR UPDATE")->fetchColumn();
            $statement = $this->pdo->prepare('SELECT active, is_admin FROM app_users WHERE username = ? FOR UPDATE');
            $statement->execute([$username]);
            $target = $statement->fetch();
            if (!is_array($target)) throw new \InvalidArgumentException('Gebruiker niet gevonden.');
            if ($removingAdmin && $target['active'] && $target['is_admin']
                && (int) $this->pdo->query('SELECT COUNT(*) FROM app_users WHERE active = 1 AND is_admin = 1')->fetchColumn() <= 1) {
                throw new \InvalidArgumentException('De laatste actieve beheerder moet behouden blijven.');
            }
            $result = $change($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array{display_name: string, signature: string, email: string}|null $profile */
    public function manage(string $username, bool $active, array $groupIds, ?array $profile = null): void
    {
        $available = $this->groups();
        $known = array_map('intval', array_column($available, 'id'));
        if ($groupIds === [] || array_filter($groupIds, static fn ($id): bool => !is_int($id) || !in_array($id, $known, true))) {
            throw new \InvalidArgumentException('Selecteer minimaal een bestaande groep.');
        }
        $adminId = (int) array_values(array_filter($available, static fn (array $group): bool => $group['system_key'] === 'admins'))[0]['id'];
        $isAdmin = in_array($adminId, $groupIds, true);
        $this->changeAccount($username, function (PDO $pdo) use ($username, $active, $groupIds, $isAdmin, $profile): int {
            $pdo->prepare('UPDATE app_users SET auth_version = auth_version + IF(active <> ?, 1, 0),
                active = ?, is_admin = ?, updated_at = UTC_TIMESTAMP() WHERE username = ?')
                ->execute([(int) $active, (int) $active, (int) $isAdmin, $username]);
            $pdo->prepare('DELETE FROM user_group_memberships WHERE username = ?')->execute([$username]);
            $insert = $pdo->prepare('INSERT INTO user_group_memberships (username, group_id) VALUES (?, ?)');
            foreach (array_unique($groupIds) as $id) $insert->execute([$username, $id]);
            if ($profile !== null) $this->saveProfile($username, $profile['display_name'], $profile['signature'], $profile['email']);
            return 1;
        }, !$active || !$isAdmin);
    }

    public function saveGroup(?int $id, string $name, array $permissions): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || array_diff($permissions, array_keys(Permissions::LABELS))) {
            throw new \InvalidArgumentException('Ongeldige groepsnaam of rechten.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($id !== null) {
                $check = $this->pdo->prepare('SELECT system_key FROM user_groups WHERE id = ? FOR UPDATE');
                $check->execute([$id]);
                $key = $check->fetchColumn();
                if ($key === false || $key === 'admins') throw new \InvalidArgumentException('Beheerdersgroep is beschermd of groep bestaat niet.');
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM user_groups WHERE name = ? AND id <> ?');
            $duplicate->execute([$name, $id ?? 0]);
            if ($duplicate->fetchColumn() !== false) throw new \InvalidArgumentException('Groepsnaam bestaat al.');
            if ($id === null) {
                $this->pdo->prepare('INSERT INTO user_groups (name) VALUES (?)')->execute([$name]);
                $id = (int) $this->pdo->lastInsertId();
            } else $this->pdo->prepare('UPDATE user_groups SET name = ? WHERE id = ?')->execute([$name, $id]);
            $this->pdo->prepare('DELETE FROM group_permissions WHERE group_id = ?')->execute([$id]);
            $insert = $this->pdo->prepare('INSERT INTO group_permissions (group_id, permission) VALUES (?, ?)');
            foreach (array_unique($permissions) as $permission) $insert->execute([$id, $permission]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function deleteGroup(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare('SELECT system_key FROM user_groups WHERE id = ? FOR UPDATE');
            $check->execute([$id]);
            if ($check->fetchColumn() !== null) throw new \InvalidArgumentException('Alleen eigen groepen kunnen worden verwijderd.');
            $members = $this->pdo->prepare('SELECT COUNT(*) FROM user_group_memberships WHERE group_id = ?');
            $members->execute([$id]);
            if ((int) $members->fetchColumn() !== 0) throw new \InvalidArgumentException('Wijs groepsleden eerst aan een andere groep toe.');
            $this->pdo->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function page(string $search, int $page): array
    {
        $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($search, 0, 100));
        $where = " WHERE username LIKE :search ESCAPE '!'";
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM app_users' . $where);
        $count->execute(['search' => '%' . $search . '%']);
        $query = $this->pdo->prepare('SELECT username, display_name, active, is_admin, created_at FROM app_users'
            . $where . ' ORDER BY username LIMIT 50 OFFSET ' . ((max(1, $page) - 1) * 50));
        $query->execute(['search' => '%' . $search . '%']);
        return ['total' => (int) $count->fetchColumn(), 'rows' => $query->fetchAll()];
    }
}
