<?php

declare(strict_types=1);

namespace NnSpotty\Auth;

use NnSpotty\Users\UserRepository;

final class SessionAuth
{
    private const SESSION_KEY = 'nnspotty_auth_user';

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function login(string $username, string $password): bool
    {
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'username' => $user['username'],
            'is_admin' => $user['is_admin'] === 1,
        ];

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    /**
     * @return array{username: string, is_admin: bool}|null
     */
    public function user(): ?array
    {
        $sessionUser = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($sessionUser) || !isset($sessionUser['username'], $sessionUser['is_admin'])) {
            return null;
        }

        return [
            'username' => (string) $sessionUser['username'],
            'is_admin' => (bool) $sessionUser['is_admin'],
        ];
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

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && $user['is_admin'] === true;
    }
}
