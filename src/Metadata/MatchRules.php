<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MatchRules
{
    public const TYPES = ['movie', 'series', 'music'];
    public const OPERATIONS = ['remove_word', 'remove_prefix', 'remove_suffix', 'remove_bracketed',
        'year_marker', 'season_marker', 'episode_marker'];
    private const MAX_RULES = 100;
    private const MAX_TITLE_BYTES = 16384;
    private const PATTERN_LIMITS = '(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function rows(): array
    {
        $rows = $this->pdo->query('SELECT id, media_type, operation, term, enabled FROM metadata_match_rules ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'media_type' => (string) $row['media_type'],
            'operation' => (string) $row['operation'], 'term' => (string) $row['term'],
            'enabled' => (bool) $row['enabled'],
        ], $rows);
    }

    public function add(string $type, string $operation, string $term): void
    {
        $term = self::validate($type, $operation, $term);
        $owned = !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            // Lock the type's range before checking its bound, including disabled rules.
            $lock = $this->pdo->prepare('SELECT id FROM metadata_match_rules WHERE media_type = ?'
                . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lock->execute([$type]);
            $lock->fetchAll(PDO::FETCH_COLUMN);
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM metadata_match_rules WHERE media_type = ?');
            $count->execute([$type]);
            if ((int) $count->fetchColumn() >= self::MAX_RULES) {
                throw new InvalidArgumentException('Maximaal 100 matchregels per mediatype.');
            }
            $existing = $this->pdo->prepare('SELECT term FROM metadata_match_rules WHERE media_type = ? AND operation = ?');
            $existing->execute([$type, $operation]);
            foreach ($existing->fetchAll(PDO::FETCH_COLUMN) as $value) {
                if (self::replace('~\A' . preg_quote((string) $value, '~') . '\z~iu', '', $term) === '') {
                    throw new InvalidArgumentException('Deze matchregel bestaat al.');
                }
            }
            $insert = $this->pdo->prepare('INSERT INTO metadata_match_rules (media_type, operation, term, enabled) VALUES (?, ?, ?, 1)');
            $insert->execute([$type, $operation, $term]);
            if ($owned) $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function delete(int $id): void
    {
        if ($id < 1) throw new InvalidArgumentException('Ongeldig matchregelnummer.');
        $statement = $this->pdo->prepare('DELETE FROM metadata_match_rules WHERE id = ?');
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) throw new InvalidArgumentException('Matchregel niet gevonden.');
    }

    public function revision(): string
    {
        $video = hash_file('sha256', __DIR__ . DIRECTORY_SEPARATOR . 'Release.php');
        $music = hash_file('sha256', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Music' . DIRECTORY_SEPARATOR . 'Release.php');
        if ($video === false || $music === false) throw new RuntimeException('Ingebouwde matchregels zijn niet leesbaar.');
        return hash('sha256', json_encode(['rules' => $this->rows(), 'video' => $video, 'music' => $music],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function apply(array $spot, string $type): array
    {
        if (!is_string($spot['title'] ?? null)) throw new InvalidArgumentException('Spottitel ontbreekt.');
        $spot['title'] = self::transform($spot['title'], $this->rows(), $type);
        return $spot;
    }

    /** Rules run in the supplied order; database rows are ordered by id. */
    public static function transform(string $title, array $rows, string $type): string
    {
        self::validateType($type);
        if (strlen($title) > self::MAX_TITLE_BYTES || preg_match('//u', $title) !== 1) return $title;
        $count = 0;
        foreach ($rows as $row) {
            if (($row['media_type'] ?? null) !== $type || !(bool) ($row['enabled'] ?? true)) continue;
            if (++$count > self::MAX_RULES) throw new InvalidArgumentException('Maximaal 100 actieve matchregels per mediatype.');
            if (!is_string($row['operation'] ?? null) || !is_string($row['term'] ?? null)) {
                throw new InvalidArgumentException('Ongeldige matchregel.');
            }
            $term = self::validate($type, $row['operation'], $row['term']);
            $literal = preg_quote($term, '~');
            $word = '(?<![\p{L}\p{M}\p{N}])' . $literal . '(?![\p{L}\p{M}\p{N}])';
            $separator = '[\s\p{P}\p{S}]*';
            $before = $title;
            switch ($row['operation']) {
                case 'remove_word':
                    $title = self::remove('~' . $word . '~iu', $title, ' ');
                    break;
                case 'remove_prefix':
                    $title = self::remove('~\A' . $separator . $word . $separator . '~iu', $title);
                    break;
                case 'remove_suffix':
                    $title = self::remove('~' . $separator . $word . $separator . '\z~iu', $title);
                    break;
                case 'remove_bracketed':
                    $title = self::replaceCallback('~\([^()\[\]]*\)|\[[^()\[\]]*\]~u',
                        static function (array $match) use ($word): string {
                            return preg_match(self::pattern('~' . $word . '~iu'), $match[0]) === 1
                                && preg_match('/\p{N}/u', $match[0]) !== 1 ? ' ' : $match[0];
                        }, $title);
                    break;
                default:
                    $digits = match ($row['operation']) {
                        'year_marker' => '(?:19|20)[0-9]{2}',
                        'season_marker' => '[0-9]{1,3}',
                        'episode_marker' => '[0-9]{1,4}',
                    };
                    $canonical = match ($row['operation']) {
                        'year_marker' => '',
                        'season_marker' => 'Season ',
                        'episode_marker' => 'Episode ',
                    };
                    // Only the marker and its separators change, not the digits or range tail.
                    $title = self::replaceCallback('~(?<![\p{L}\p{N}])' . $literal
                        . '[\s._:=\-]*(' . $digits . ')(?![\p{L}\p{N}])~iu',
                        static fn (array $match): string => $canonical . $match[1], $title);
            }
            if ($title !== $before) $title = trim(self::replace('~[\p{Z}\t ]{2,}~u', ' ', $title));
        }
        return $title;
    }

    private static function validateType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException('Ongeldig mediatype.');
    }

    private static function validate(string $type, string $operation, string $term): string
    {
        self::validateType($type);
        if (!in_array($operation, self::OPERATIONS, true)) throw new InvalidArgumentException('Ongeldige matchbewerking.');
        if ($term === '' || strlen($term) > 160 || preg_match('//u', $term) !== 1
            || preg_match('/\p{C}/u', $term) === 1) {
            throw new InvalidArgumentException('Gebruik 1 tot 160 bytes geldige Unicode zonder controletekens.');
        }
        $term = preg_replace('/^\p{Z}+|\p{Z}+$/u', '', $term) ?? $term;
        if ($term === '') throw new InvalidArgumentException('Vul een letterlijke term in.');
        if (str_ends_with($operation, '_marker') && preg_match('/\p{L}/u', $term) !== 1) {
            throw new InvalidArgumentException('Een marker moet minstens één letter bevatten.');
        }
        return $term;
    }

    private static function remove(string $pattern, string $title, string $replacement = ''): string
    {
        return self::replaceCallback($pattern, static fn (array $match): string =>
            preg_match('/\p{N}/u', $match[0]) === 1 ? $match[0] : $replacement, $title);
    }

    private static function pattern(string $pattern): string
    {
        return $pattern[0] . self::PATTERN_LIMITS . substr($pattern, 1);
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace(self::pattern($pattern), $replacement, $subject, self::MAX_RULES);
        if ($result === null) throw new RuntimeException('Matchregel kon niet veilig worden uitgevoerd.');
        return $result;
    }

    private static function replaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = preg_replace_callback(self::pattern($pattern), $callback, $subject, self::MAX_RULES);
        if ($result === null) throw new RuntimeException('Matchregel kon niet veilig worden uitgevoerd.');
        return $result;
    }
}
