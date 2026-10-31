<?php

declare(strict_types=1);

use NnSpotty\Database\Schema;

// MySQL 8 rejects IF [NOT] EXISTS inside ALTER TABLE; schema changes must use NnSpotty\Database\Schema.
$alterIfExists = '/\b(ADD|DROP)\s+(COLUMN\s+|(FULLTEXT\s+|UNIQUE\s+|SPATIAL\s+)?(INDEX|KEY)\s+)?IF\s+(NOT\s+)?EXISTS\b/i';
$offenders = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php' && preg_match($alterIfExists, (string) file_get_contents($file->getPathname())) === 1) {
        $offenders[] = $file->getFilename();
    }
}
check($offenders === [], 'Geen MySQL 8-incompatibele ALTER ... IF [NOT] EXISTS in src' . ($offenders === [] ? '' : ': ' . implode(', ', $offenders)));

$schema = (new ReflectionClass(Schema::class))->newInstanceWithoutConstructor();
rejects(static fn () => $schema->hasColumn('spots`; DROP TABLE x', 'id'), 'Schema-helper weigert ongeldige tabelnaam');
rejects(static fn () => $schema->dropIndex('spots', 'idx` ; DROP'), 'Schema-helper weigert ongeldige indexnaam');
rejects(static fn () => $schema->addIndex('spots', 'idx', 'id', 'INDEX; DROP'), 'Schema-helper weigert onbekend indextype');
