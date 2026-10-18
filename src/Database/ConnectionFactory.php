<?php

declare(strict_types=1);

namespace NnSpotty\Database;

use PDO;
use PDOException;

final class ConnectionFactory
{
    public static function fromEnvironment(): PDO
    {
        $host = envValue('DB_HOST');
        $port = envValue('DB_PORT', '3306');
        $database = envValue('DB_DATABASE');
        $username = envValue('DB_USERNAME');
        $password = envValue('DB_PASSWORD', '');
        $charset = envValue('DB_CHARSET', 'utf8mb4');

        if ($host === null || $database === null || $username === null) {
            throw new \RuntimeException('Databaseconfig ontbreekt. Stel DB_HOST, DB_DATABASE en DB_USERNAME in.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port ?? '3306', $database, $charset ?? 'utf8mb4');

        try {
            return new PDO($dsn, $username, $password ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException('Databaseverbinding mislukt: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
