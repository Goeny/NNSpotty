<?php

declare(strict_types=1);

function envValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);

    if (($value === false || $value === '') && array_key_exists($name, $_ENV)) {
        $value = $_ENV[$name];
    }

    if (($value === false || $value === '') && array_key_exists($name, $_SERVER)) {
        $value = $_SERVER[$name];
    }

    if ($value === false || $value === '') {
        return $default;
    }

    return is_string($value) ? $value : (string) $value;
}

function loadEnvFile(string $filePath): void
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Kon .env bestand niet lezen: ' . $filePath);
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $parts = explode('=', $trimmed, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if (str_starts_with($key, 'export ')) {
            $key = trim(substr($key, 7));
        }
        if ($key === '') {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
