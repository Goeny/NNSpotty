<?php

declare(strict_types=1);

namespace NnSpotty\Database;

use PDO;

/**
 * Idempotent schema changes that work on both MariaDB and MySQL 8.
 * MySQL does not support IF [NOT] EXISTS inside ALTER TABLE, so existence is checked in information_schema first.
 */
final class Schema
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasColumn(string $table, string $column): bool
    {
        $parameters = ['table' => self::identifier($table), 'column' => self::identifier($column)];
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    public function hasIndex(string $table, string $index): bool
    {
        $parameters = ['table' => self::identifier($table), 'index' => self::identifier($index)];
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    /** @param string $definition Column type and options, e.g. "INT NOT NULL DEFAULT 0 AFTER id". */
    public function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->hasColumn($table, $column)) {
            $this->pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', self::identifier($table), self::identifier($column), $definition));
        }
    }

    /** @param string $columns Index column list without parentheses; $kind is INDEX or FULLTEXT INDEX. */
    public function addIndex(string $table, string $index, string $columns, string $kind = 'INDEX'): void
    {
        if (!in_array($kind, ['INDEX', 'UNIQUE INDEX', 'FULLTEXT INDEX'], true)) {
            throw new \InvalidArgumentException('Onbekend indextype: ' . $kind);
        }
        if (!$this->hasIndex($table, $index)) {
            $this->pdo->exec(sprintf('ALTER TABLE `%s` ADD %s `%s` (%s)', self::identifier($table), $kind, self::identifier($index), $columns));
        }
    }

    public function dropIndex(string $table, string $index): void
    {
        if ($this->hasIndex($table, $index)) {
            $this->pdo->exec(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', self::identifier($table), self::identifier($index)));
        }
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Ongeldige schema-identifier: ' . $name);
        }

        return $name;
    }
}
