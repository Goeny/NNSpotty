<?php

declare(strict_types=1);

namespace NnSpotty\Security;

final class Csrf
{
    private const SESSION_KEY = 'nnspotty_csrf_token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY]) || $_SESSION[self::SESSION_KEY] === '') {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . h($this->token()) . '">';
    }

    public function verify(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $submittedToken);
    }
}
