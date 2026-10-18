<?php

declare(strict_types=1);

namespace NnSpotty\Newznab;

use InvalidArgumentException;
use NnSpotty\Http\Request;
use PDO;

final class Search
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function number(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->queryScalar($key);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value) || strlen($value) > 10 || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException('Ongeldige parameter: ' . $key . '.');
        }
        return (int) $value;
    }

    /** @return array{items: list<array<string, mixed>>, total: int, offset: int} */
    public function run(Request $request, bool $showErotica, string $type): array
    {
        $limit = self::number($request, 'limit', 100, 1, 100);
        $offset = self::number($request, 'offset', 0, 0, 100000);
        $maxage = self::number($request, 'maxage', 0, 0, 100000);
        self::number($request, 'extended', 1, 0, 1);
        $query = trim($request->queryScalar('q') ?? '');
        if (strlen($query) > 200 || preg_match('//u', $query) !== 1) {
            throw new InvalidArgumentException('Zoektekst is ongeldig of langer dan 200 bytes.');
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) > 20 || ($query !== '' && $words === [])) {
            throw new InvalidArgumentException('Gebruik maximaal 20 zoekwoorden.');
        }
        $where = ["JSON_LENGTH(s.nzb_segments) > 0"];
        $parameters = [];
        if (!$showErotica) {
            $where[] = 's.is_erotica = 0';
        }
        if ($type !== 'search') {
            $where[] = 'EXISTS (SELECT 1 FROM spotnet_newznab_categories modecat WHERE modecat.spot_id = s.id AND modecat.category_id = :modecat)';
            $parameters['modecat'] = $type === 'tvsearch' ? 5000 : 2000;
        }
        $categories = $request->queryScalar('cat');
        if ($categories !== null) {
            $ids = explode(',', $categories);
            if (count($ids) > 50) {
                throw new InvalidArgumentException('Te veel categorieen.');
            }
            $placeholders = [];
            foreach ($ids as $index => $id) {
                if (!ctype_digit($id) || !isset(Categories::definitions()[(int) $id])) {
                    throw new InvalidArgumentException('Onbekende Newznab-categorie.');
                }
                $placeholders[] = ':cat' . $index;
                $parameters['cat' . $index] = (int) $id;
            }
            $where[] = 'EXISTS (SELECT 1 FROM spotnet_newznab_categories c WHERE c.spot_id = s.id AND c.category_id IN ('
                . implode(',', $placeholders) . '))';
        }
        // Dotted release names and ordinary title words must match the same query.
        $title = "REPLACE(REPLACE(REPLACE(s.title, '.', ' '), '_', ' '), '-', ' ')";
        foreach ($words as $index => $word) {
            $where[] = $title . ' LIKE :word' . $index;
            $parameters['word' . $index] = '%' . $word . '%';
        }
        if ($maxage > 0) {
            $where[] = 's.posted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :maxage DAY)';
            $parameters['maxage'] = $maxage;
        }
        $seasonValue = $request->queryScalar('season');
        $episodeValue = $request->queryScalar('ep');
        if (($seasonValue !== null || $episodeValue !== null) && $type !== 'tvsearch') {
            throw new InvalidArgumentException('season en ep vereisen tvsearch.');
        }
        if ($episodeValue !== null && $seasonValue === null) {
            throw new InvalidArgumentException('ep vereist season.');
        }
        if ($seasonValue !== null) {
            $season = self::number($request, 'season', 0, 0, 999);
            $episode = $episodeValue === null ? null : self::number($request, 'ep', 0, 0, 999);
            $s = '0*' . $season;
            $e = $episode === null ? null : '0*' . $episode;
            $where[] = 's.title REGEXP :episode';
            $parameters['episode'] = $e === null
                ? '(^|[^a-z0-9])(s' . $s . '([^0-9]|$)|' . $s . 'x[0-9])'
                : '(^|[^a-z0-9])(s' . $s . '[ ._-]*e' . $e . '([^0-9]|$)|' . $s . 'x' . $e . '([^0-9]|$))';
        }
        $year = $request->queryScalar('year');
        if ($year !== null) {
            if ($type !== 'movie') {
                throw new InvalidArgumentException('year vereist movie.');
            }
            $parameters['year'] = '(^|[^0-9])' . self::number($request, 'year', 1900, 1888, 2100) . '([^0-9]|$)';
            $where[] = 's.title REGEXP :year';
        }
        $sql = ' FROM spotnet_spots s WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $select = $this->pdo->prepare('SELECT s.id, s.title, s.description, s.category, s.subcategories, s.size, s.posted_at, s.poster'
            . $sql . ' ORDER BY s.posted_at DESC, s.id DESC LIMIT :limit OFFSET :offset');
        foreach ($parameters as $key => $value) {
            $select->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->bindValue(':offset', $offset, PDO::PARAM_INT);
        $select->execute();
        $items = $select->fetchAll();
        foreach ($items as &$item) {
            $item['subcategories'] = json_decode($item['subcategories'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'offset' => $offset];
    }
}
