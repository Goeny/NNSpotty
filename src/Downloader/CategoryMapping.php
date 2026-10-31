<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use NnSpotty\Usenet\SpotCategories;
use PDO;

final class CategoryMapping
{
    /** @return array<string, string> */
    public static function load(PDO $pdo, string $table): array
    {
        return array_column($pdo->query('SELECT type, category FROM ' . self::table($table))->fetchAll(), 'category', 'type');
    }

    /** @param array<string, string> $mapping */
    public static function save(PDO $pdo, string $table, array $mapping, string $label): void
    {
        if (array_diff(array_keys($mapping), ConnectionRepository::TYPES) !== []) {
            throw new InvalidArgumentException('Onbekend spottype in ' . $label . '-mapping.');
        }
        foreach ($mapping as $category) {
            if (!is_string($category) || strlen($category) > 100 || preg_match('/[\x00-\x1f\x7f]/', $category)) {
                throw new InvalidArgumentException('Ongeldige ' . $label . '-categorienaam.');
            }
        }
        $table = self::table($table);
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM ' . $table);
            $insert = $pdo->prepare('INSERT INTO ' . $table . ' (type, category) VALUES (?, ?)');
            foreach ($mapping as $type => $category) {
                $insert->execute([$type, trim($category)]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function category(array $spot, array $settings): string
    {
        $type = SpotCategories::classify($spot['category'], $spot['subcategories'])['type'];
        return $settings['mappings'][$type] ?? '';
    }

    private static function table(string $table): string
    {
        if (!in_array($table, ['sabnzbd_category_mapping', 'nzbget_category_mapping'], true)) {
            throw new InvalidArgumentException('Onbekende mappingtabel.');
        }
        return $table;
    }
}
