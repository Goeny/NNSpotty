<?php

declare(strict_types=1);

namespace NnSpotty\Newznab;

use InvalidArgumentException;
use NnSpotty\Http\Request;
use NnSpotty\Usenet\SearchCandidates;
use NnSpotty\Usenet\SpotBrowseQuery;
use PDO;

final class Search
{
    private ?bool $metadataSearch = null;

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
        $where = ['b.has_nzb = 1'];
        $parameters = [];
        if (!$showErotica) {
            $where[] = 'b.is_erotica = 0';
        }
        if ($type !== 'search') {
            $where[] = 'EXISTS (SELECT 1 FROM spotnet_newznab_categories modecat WHERE modecat.spot_id = b.spot_id AND modecat.category_id = :modecat)';
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
            $where[] = 'EXISTS (SELECT 1 FROM spotnet_newznab_categories c WHERE c.spot_id = b.spot_id AND c.category_id IN ('
                . implode(',', $placeholders) . '))';
        }
        $metadataSearch = $this->metadataSearchEnabled();
        $idFilters = [];
        $imdbId = $request->queryScalar('imdbid');
        if ($imdbId !== null) {
            if (preg_match('/^tt\d{5,10}$/D', $imdbId) !== 1) {
                throw new InvalidArgumentException('Ongeldige imdbid-parameter.');
            }
            if ($metadataSearch) {
                $idFilters[] = 'm.imdb_id = :imdb_id';
                $parameters['imdb_id'] = $imdbId;
            }
        }
        $tvdbId = $request->queryScalar('tvdbid');
        if ($tvdbId !== null) {
            if (!ctype_digit($tvdbId) || strlen($tvdbId) > 10 || (int) $tvdbId < 1) {
                throw new InvalidArgumentException('Ongeldige tvdbid-parameter.');
            }
            if ($metadataSearch) {
                $idFilters[] = 'm.tvdb_id = :tvdb_id';
                $parameters['tvdb_id'] = $tvdbId;
            }
        }
        $tmdbId = $request->queryScalar('tmdbid');
        if ($tmdbId !== null) {
            if (!ctype_digit($tmdbId) || strlen($tmdbId) > 10 || (int) $tmdbId < 1) {
                throw new InvalidArgumentException('Ongeldige tmdbid-parameter.');
            }
            if ($metadataSearch) {
                $idFilters[] = 'm.tmdb_id = :tmdb_id';
                $parameters['tmdb_id'] = $tmdbId;
            }
        }
        if (($imdbId !== null || $tvdbId !== null || $tmdbId !== null) && !$metadataSearch) {
            throw new InvalidArgumentException('ID-zoeken vereist metadata-index. Voer db migrate uit en activeer metadataverrijking.');
        }
        if ($idFilters !== []) {
            $where[] = 'b.spot_id IN (SELECT spot_id FROM (' . implode(' UNION ', array_map(
                static fn (string $filter): string => 'SELECT m.spot_id FROM metadata_search_index m WHERE ' . $filter,
                $idFilters
            )) . ') id_candidates)';
        }
        // Every word must occur as a whole word; FULLTEXT preselects candidates and the expressions confirm word boundaries.
        $terms = $query === '' ? null : \NnSpotty\Usenet\SearchTerms::parse($query);
        $fulltextWhere = null;
        $textWhere = [];
        if ($terms !== null) {
            $fulltext = $terms->fulltext((int) ($this->pdo->query('SELECT @@innodb_ft_min_token_size')->fetchColumn() ?: 3));
            if ($fulltext !== null) {
                // API words may occur across title and metadata. One indexable word is a safe preselection;
                // the expressions below still require every word, including short words, in either source.
                $tokens = explode(' ', $fulltext);
                usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
                if (!(new SpotBrowseQuery($this->pdo))->broadSearch(null, $showErotica, [], substr($tokens[0], 1))) {
                    $textWhere[] = $fulltextWhere = SearchCandidates::condition('b.spot_id', $tokens[0], $metadataSearch, $parameters);
                }
            }
            foreach ($terms->wordPatterns() as $index => $pattern) {
                $textWhere[] = $metadataSearch
                    ? '(b.title REGEXP :word' . $index . ' OR m.search_text REGEXP :word_meta' . $index . ')'
                    : 'b.title REGEXP :word' . $index;
                $parameters['word' . $index] = $pattern;
                if ($metadataSearch) {
                    $parameters['word_meta' . $index] = $pattern;
                }
            }
        }
        if ($maxage > 0) {
            $where[] = 'b.posted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :maxage DAY)';
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
            $textWhere[] = 'b.title REGEXP :episode';
            $parameters['episode'] = $e === null
                ? '(^|[^a-z0-9])(s' . $s . '([^0-9]|$)|' . $s . 'x[0-9])'
                : '(^|[^a-z0-9])(s' . $s . '[ ._-]*e' . $e . '([^0-9]|$)|' . $s . 'x' . $e . '([^0-9]|$))';
        }
        $year = $request->queryScalar('year');
        if ($year !== null) {
            if ($type !== 'movie') {
                throw new InvalidArgumentException('year vereist movie.');
            }
            $yearNumber = self::number($request, 'year', 1900, 1888, 2100);
            $parameters['year'] = '(^|[^0-9])' . $yearNumber . '([^0-9]|$)';
            if ($metadataSearch) {
                $parameters['year_number'] = $yearNumber;
            }
            $textWhere[] = $metadataSearch
                ? '(b.title REGEXP :year OR m.year = :year_number)'
                : 'b.title REGEXP :year';
        }
        try {
            return $this->fetch($where, $textWhere, $parameters, $limit, $offset);
        } catch (\PDOException $exception) {
            // Extremely common words exceed the FULLTEXT result cache; the whole-word expressions alone stay correct.
            if ($fulltextWhere === null || (int) ($exception->errorInfo[1] ?? 0) !== 188) {
                throw $exception;
            }
            unset($parameters['search_ft'], $parameters['search_ft_meta']);
            return $this->fetch($where, array_values(array_diff($textWhere, [$fulltextWhere])), $parameters, $limit, $offset);
        }
    }

    /**
     * @param list<string> $where
     * @param list<string> $textWhere
     * @param array<string, mixed> $parameters
     * @return array{items: list<array<string, mixed>>, total: int, offset: int}
     */
    private function fetch(array $where, array $textWhere, array $parameters, int $limit, int $offset): array
    {
        $textJoins = $this->metadataSearchEnabled()
            ? ' LEFT JOIN metadata_search_index m ON m.spot_id = b.spot_id' : '';
        $sql = ' FROM spot_browse b' . ($textWhere === [] ? '' : $textJoins)
            . ' WHERE ' . implode(' AND ', array_merge($where, $textWhere));
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $page = 'SELECT b.spot_id AS id, b.posted_at' . $sql
            . ' ORDER BY b.posted_at DESC, b.spot_id DESC LIMIT :limit OFFSET :offset';
        $select = $this->pdo->prepare('SELECT s.id, s.title, s.description, s.category, s.subcategories, s.size, s.posted_at, s.poster'
            . ($this->metadataSearchEnabled() ? ', m.imdb_id, m.tvdb_id, m.tmdb_id, m.year AS metadata_year
                FROM (' . $page . ') page JOIN spotnet_spots s ON s.id = page.id
                LEFT JOIN metadata_search_index m ON m.spot_id = s.id'
                : ', \'\' AS imdb_id, \'\' AS tvdb_id, \'\' AS tmdb_id, NULL AS metadata_year
                FROM (' . $page . ') page JOIN spotnet_spots s ON s.id = page.id')
            . ' ORDER BY page.posted_at DESC, page.id DESC');
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

    private function metadataSearchEnabled(): bool
    {
        if ($this->metadataSearch !== null) {
            return $this->metadataSearch;
        }
        $this->metadataSearch = $this->pdo->query("SHOW TABLES LIKE 'metadata_search_index'")->fetchColumn() !== false;
        return $this->metadataSearch;
    }
}
