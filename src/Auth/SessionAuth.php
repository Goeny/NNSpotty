<?php

declare(strict_types=1);

namespace NnSpotty\Auth;

use NnSpotty\Users\UserRepository;

final class SessionAuth
{
    private const SESSION_KEY = 'nnspotty_auth_user';
    private ?array $resolved = null;
    private bool $loaded = false;

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function login(string $username, string $password): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || !$user['active']) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'username' => $user['username'],
            'is_admin' => $user['is_admin'] === 1,
            'auth_version' => $user['auth_version'],
        ];
        $this->loaded = false;

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        $this->loaded = false;
        session_regenerate_id(true);
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->loaded) return $this->resolved;
        $this->loaded = true;
        $this->resolved = null;
        $sessionUser = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($sessionUser) || !isset($sessionUser['username'], $sessionUser['is_admin'])) {
            return null;
        }

        $user = $this->users->findByUsername((string) $sessionUser['username']);
        if ($user === null || !$user['active'] || ($sessionUser['auth_version'] ?? 1) !== $user['auth_version']) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        unset($user['password_hash']);
        $user['is_admin'] = $user['is_admin'] === 1;
        return $this->resolved = $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->user() !== null;
    }

    public function newSpotsSince(): string
    {
        $user = $this->user();
        if ($user === null) throw new \LogicException('Login vereist voor persoonlijke spottellers.');
        $previous = $this->users->recordBrowseVisit($user['username']);
        // Keep the previous visit boundary fixed across navigation and refreshes.
        $_SESSION[self::SESSION_KEY]['new_spots_since'] ??= $previous;
        return $_SESSION[self::SESSION_KEY]['new_spots_since'];
    }

    public function newSpotsBoundary(): ?string
    {
        return $_SESSION[self::SESSION_KEY]['new_spots_since'] ?? null;
    }

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && !$user['must_change_password'] && $user['is_admin'] === true;
    }

    public function can(string $permission): bool
    {
        return \NnSpotty\Users\Permissions::allows($this->user(), $permission);
    }
}
