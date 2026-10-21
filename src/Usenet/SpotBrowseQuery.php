<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use PDO;

/**
 * Overview queries on the narrow spot_browse read model.
 *
 * Plans: an index-ordered date walk for broad selections, a combination-driven lookup for rare
 * subcategory combinations and a FULLTEXT candidate search confirmed by a whole-word expression.
 */
final class SpotBrowseQuery
{
    /** Up to this many matching spots the combination plan beats walking the date index. */
    private const COMBO_PLAN_LIMIT = 20000;
    /** Newest rows checked by the bounded search walk before the FULLTEXT index is consulted. */
    private const SEARCH_WINDOW = 5000;
    /** A term this frequent among the newest rows counts as broad for filter availability. */
    private const BROAD_MATCHES = 50;
    private const FILTER_CACHE_MINUTES = 10;
    private const TIMEOUT_ERRORS = [1969, 3024];
    /** InnoDB "FTS query exceeds result cache limit" for extremely common words. */
    private const FULLTEXT_CACHE_LIMIT = 188;
    private const MISSING_FULLTEXT = 1191;

    private ?int $minimumToken = null;
    private ?bool $mariaDb = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $codes
     * @param array{date: string, id: int}|null $after
     * @return list<array{browse_id: int, spot_id: string, posted_at: string}>
     */
    public function page(?string $category, int $limit, int $offset, bool $showErotica, array $codes, string $search,
        ?array $after, ?string $favoriteUser): array
    {
        $terms = SearchTerms::parse($search);
        if ($terms !== null && $terms->words === []) {
            return [];
        }
        $where = [];
        $parameters = [];
        $this->scope('b', $category, $showErotica, $where, $parameters);
        $keyset = [];
        $keysetParameters = [];
        if ($after !== null) {
            $keyset[] = '(b.posted_at < :before_date OR (b.posted_at = :same_date AND b.id < :before_id))';
            $keysetParameters = ['before_date' => $after['date'], 'same_date' => $after['date'], 'before_id' => $after['id']];
            $where = array_merge($where, $keyset);
            $parameters += $keysetParameters;
        }
        $joinParameters = [];
        $joins = $this->codeJoins('b.combo_id', $codes, $joinParameters);
        $order = ' ORDER BY b.posted_at DESC, b.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        if ($favoriteUser !== null) {
            $parameters['favorite_user'] = $favoriteUser;
            $where[] = 'f.username = :favorite_user';
            $this->searchConditions($terms, false, $where, $parameters);
            return $this->rows('SELECT b.id, b.spot_id, b.posted_at FROM spot_favorites f
                JOIN spot_browse b ON b.spot_id = f.spot_id' . $joins . $this->where($where) . $order, $parameters + $joinParameters);
        }

        if ($terms !== null && $this->fulltextQuery($terms) !== null) {
            // Common words fill a page from the newest rows at once; FULLTEXT would first collect every match.
            $recent = $this->recent($category, $where, $parameters + $joinParameters, $joins, $terms, $order);
            if (count($recent) >= max(1, $limit)) {
                return $recent;
            }
            $searchWhere = $where;
            $searchParameters = $parameters + $joinParameters;
            $this->searchConditions($terms, true, $searchWhere, $searchParameters);
            try {
                return $this->rows($this->budgeted('SELECT b.id, b.spot_id, b.posted_at FROM spot_browse b' . $joins
                    . $this->where($searchWhere) . $order), $searchParameters);
            } catch (\PDOException $exception) {
                // A broad search term is cheaper as a date walk that stops after one page.
                if (!$this->fallbackError($exception)) {
                    throw $exception;
                }
            }
        }
        $this->searchConditions($terms, false, $where, $parameters);

        if ($codes !== []) {
            $total = $this->comboTotal($category, $showErotica, $codes);
            if ($total === 0) {
                return [];
            }
            if ($total <= self::COMBO_PLAN_LIMIT) {
                $comboParameters = [];
                $comboWhere = ['c.spot_count > 0'];
                $this->scope('c', $category, $showErotica, $comboWhere, $comboParameters, 'm_');
                $comboJoins = $this->codeJoins('c.id', $codes, $comboParameters, 'm');
                $combos = '(SELECT c.id FROM spot_combos c' . $comboJoins . $this->where($comboWhere) . ') m
                    JOIN spot_browse b FORCE INDEX (idx_browse_combo) ON b.combo_id = m.id';
                if ($terms !== null) {
                    return $this->rows('SELECT STRAIGHT_JOIN b.id, b.spot_id, b.posted_at FROM ' . $combos
                        . $this->where($where) . $order, $parameters + $comboParameters);
                }
                // The combination already fixes category, type and erotica, so the index alone covers the page.
                return $this->rows('SELECT b.id, b.spot_id, b.posted_at FROM (SELECT STRAIGHT_JOIN b.id FROM ' . $combos
                    . $this->where($keyset) . $order . ') page
                    JOIN spot_browse b ON b.id = page.id ORDER BY b.posted_at DESC, b.id DESC', $keysetParameters + $comboParameters);
            }
        }
        $index = $this->walkIndex($category);
        return $this->rows('SELECT b.id, b.spot_id, b.posted_at FROM (SELECT STRAIGHT_JOIN b.id FROM spot_browse b'
            . ($index === null ? '' : ' FORCE INDEX (' . $index . ')') . $joins . $this->where($where) . $order . ') page
            JOIN spot_browse b ON b.id = page.id ORDER BY b.posted_at DESC, b.id DESC', $parameters + $joinParameters);
    }

    /** @param list<string> $codes */
    public function count(?string $category, bool $showErotica, array $codes, string $search): int
    {
        $terms = SearchTerms::parse($search);
        if ($terms !== null && $terms->words === []) {
            return 0;
        }
        $where = [];
        $parameters = [];
        $this->scope('b', $category, $showErotica, $where, $parameters);
        $joins = $this->codeJoins('b.combo_id', $codes, $parameters);
        $this->searchConditions($terms, $terms !== null && $this->fulltextQuery($terms) !== null, $where, $parameters);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM spot_browse b' . $joins . $this->where($where));
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /**
     * Per-code totals within the current selection. Without search, favourites or a "new since" boundary the
     * answer comes from the maintained combination counts and never touches individual spots.
     *
     * @param list<string> $codes
     * @return list<array{code: string, total: int|string}>
     */
    public function facets(?string $category, bool $showErotica, string $search, array $codes, ?string $newSince,
        ?string $favoriteUser, bool $budgeted = false): array
    {
        $terms = SearchTerms::parse($search);
        if ($terms !== null && $terms->words === []) {
            return [];
        }
        $parameters = [];
        if ($terms === null && $newSince === null && $favoriteUser === null) {
            $inner = 'SELECT id AS combo_id, spot_count AS n FROM spot_combos WHERE spot_count > 0';
        } else {
            $innerWhere = [];
            $from = ' FROM spot_browse b';
            if ($favoriteUser !== null) {
                $from = ' FROM spot_favorites f JOIN spot_browse b ON b.spot_id = f.spot_id';
                $innerWhere[] = 'f.username = :favorite_user';
                $parameters['favorite_user'] = $favoriteUser;
            }
            if ($newSince !== null) {
                $innerWhere[] = 'b.created_at > :new_since';
                $parameters['new_since'] = $newSince;
            }
            $this->scope('b', $category, $showErotica, $innerWhere, $parameters, 'i_');
            $this->searchConditions($terms, $terms !== null && $this->fulltextQuery($terms) !== null, $innerWhere, $parameters);
            $inner = 'SELECT b.combo_id, COUNT(*) AS n' . $from . $this->where($innerWhere) . ' GROUP BY b.combo_id';
        }
        $where = [];
        $this->scope('c', $category, $showErotica, $where, $parameters);
        $sql = 'SELECT cc.code, SUM(t.n) AS total FROM (' . $inner . ') t
            JOIN spot_combos c ON c.id = t.combo_id' . $this->codeJoins('c.id', $codes, $parameters, 's') . '
            JOIN spot_combo_codes cc ON cc.combo_id = c.id' . $this->where($where) . '
            GROUP BY cc.code HAVING SUM(t.n) > 0 ORDER BY cc.code';
        $statement = $this->pdo->prepare($budgeted ? $this->budgeted($sql, 0.5) : $sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Codes that still have spots within the selection, without search, favourites or "new since". Only reads
     * combinations; the result is cached briefly and keyed on the newest combination so new codes appear at once.
     *
     * @param list<string> $codes
     * @return list<string>
     */
    public function available(?string $category, bool $showErotica, array $codes): array
    {
        $codes = array_values(array_unique($codes));
        sort($codes);
        $newest = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM spot_combos')->fetchColumn();
        $key = sha1(json_encode([$category, $showErotica, $codes, $newest], JSON_THROW_ON_ERROR));
        $cached = $this->pdo->prepare('SELECT codes FROM spot_filter_cache WHERE cache_key = :key AND expires_at > UTC_TIMESTAMP()');
        $cached->execute(['key' => $key]);
        $hit = $cached->fetchColumn();
        if (is_string($hit)) {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $parameters = [];
        $where = ['c.spot_count > 0'];
        $this->scope('c', $category, $showErotica, $where, $parameters);
        $statement = $this->pdo->prepare('SELECT DISTINCT cc.code FROM spot_combos c' . $this->codeJoins('c.id', $codes, $parameters, 's')
            . ' JOIN spot_combo_codes cc ON cc.combo_id = c.id' . $this->where($where) . ' ORDER BY cc.code');
        $statement->execute($parameters);
        $available = $statement->fetchAll(PDO::FETCH_COLUMN);
        try {
            $this->pdo->prepare('INSERT INTO spot_filter_cache (cache_key, codes, expires_at)
                VALUES (:key, :codes, UTC_TIMESTAMP() + INTERVAL ' . self::FILTER_CACHE_MINUTES . ' MINUTE)
                ON DUPLICATE KEY UPDATE codes = VALUES(codes), expires_at = VALUES(expires_at)')
                ->execute(['key' => $key, 'codes' => json_encode($available, JSON_THROW_ON_ERROR)]);
            if (random_int(1, 50) === 1) {
                $this->pdo->exec('DELETE FROM spot_filter_cache WHERE expires_at < UTC_TIMESTAMP()');
            }
        } catch (\PDOException) {
            // The cache is an optimisation only; a concurrent writer or read-only replica must not break browsing.
        }
        return $available;
    }

    /**
     * True when the newest spots in the selection already contain a full page of matches. Such broad terms make
     * an exact per-code search aggregation expensive while hiding almost nothing.
     *
     * @param list<string> $codes
     */
    public function broadSearch(?string $category, bool $showErotica, array $codes, string $search): bool
    {
        $terms = SearchTerms::parse($search);
        if ($terms === null || $terms->words === []) {
            return false;
        }
        $where = [];
        $parameters = [];
        $this->scope('b', $category, $showErotica, $where, $parameters);
        $joins = $this->codeJoins('b.combo_id', $codes, $parameters);
        return count($this->recent($category, $where, $parameters, $joins, $terms,
            ' ORDER BY b.posted_at DESC, b.id DESC LIMIT ' . self::BROAD_MATCHES)) >= self::BROAD_MATCHES;
    }

    /**
     * Searches only the newest SEARCH_WINDOW rows of the selection, walking the date index.
     *
     * @param list<string> $where
     * @param array<string, mixed> $parameters
     * @return list<array{browse_id: int, spot_id: string, posted_at: string}>
     */
    private function recent(?string $category, array $where, array $parameters, string $joins, SearchTerms $terms, string $order): array
    {
        $recentWhere = [];
        $this->searchConditions($terms, false, $recentWhere, $parameters);
        $index = $this->walkIndex($category);
        return $this->rows('SELECT b.id, b.spot_id, b.posted_at FROM (SELECT STRAIGHT_JOIN b.id FROM spot_browse b'
            . ($index === null ? '' : ' FORCE INDEX (' . $index . ')') . $joins . $this->where($where)
            . ' ORDER BY b.posted_at DESC, b.id DESC LIMIT ' . self::SEARCH_WINDOW . ') recent
            JOIN spot_browse b ON b.id = recent.id' . $this->where($recentWhere) . $order, $parameters);
    }

    /** @param list<string> $codes */
    private function comboTotal(?string $category, bool $showErotica, array $codes): int
    {
        $where = ['c.spot_count > 0'];
        $parameters = [];
        $this->scope('c', $category, $showErotica, $where, $parameters);
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(c.spot_count), 0) FROM spot_combos c'
            . $this->codeJoins('c.id', $codes, $parameters, 't') . $this->where($where));
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @param list<string> $where
     *  @param array<string, mixed> $parameters
     */
    private function scope(string $alias, ?string $category, bool $showErotica, array &$where, array &$parameters,
        string $prefix = ''): void
    {
        if (!$showErotica) {
            $where[] = $alias . '.is_erotica = 0';
        }
        if ($category === null) {
            return;
        }
        if (in_array($category, SpotCategories::HEADS, true)) {
            $where[] = $alias . '.category = :' . $prefix . 'category';
            $parameters[$prefix . 'category'] = $category;
        } elseif (SpotCategories::headForCategory($category) !== null) {
            $where[] = $alias . '.browse_type = :' . $prefix . 'type';
            $parameters[$prefix . 'type'] = $category;
        } else {
            $where[] = '(' . $alias . '.browse_type = :' . $prefix . 'type OR ' . $alias . '.category = :' . $prefix . 'head)';
            $parameters[$prefix . 'type'] = $category;
            $parameters[$prefix . 'head'] = $category;
        }
    }

    /** @param list<string> $codes
     *  @param array<string, mixed> $parameters
     */
    private function codeJoins(string $combo, array $codes, array &$parameters, string $prefix = 'x'): string
    {
        $joins = '';
        foreach (array_values(array_unique($codes)) as $index => $code) {
            $alias = $prefix . $index;
            $joins .= ' JOIN spot_combo_codes ' . $alias . ' ON ' . $alias . '.combo_id = ' . $combo . ' AND ' . $alias
                . '.code = :' . $alias;
            $parameters[$alias] = $code;
        }
        return $joins;
    }

    /** @param list<string> $where
     *  @param array<string, mixed> $parameters
     */
    private function searchConditions(?SearchTerms $terms, bool $fulltext, array &$where, array &$parameters): void
    {
        if ($terms === null) {
            return;
        }
        if ($fulltext) {
            $where[] = 'MATCH (b.title) AGAINST (:search_ft IN BOOLEAN MODE)';
            $parameters['search_ft'] = $this->fulltextQuery($terms);
        }
        $where[] = 'b.title REGEXP :search_phrase';
        $parameters['search_phrase'] = $terms->phrasePattern();
    }

    private function fulltextQuery(SearchTerms $terms): ?string
    {
        $this->minimumToken ??= (int) ($this->pdo->query('SELECT @@innodb_ft_min_token_size')->fetchColumn() ?: 3);
        return $terms->fulltext($this->minimumToken);
    }

    private function walkIndex(?string $category): ?string
    {
        return match (true) {
            $category === null => 'idx_browse_date',
            in_array($category, SpotCategories::HEADS, true) => 'idx_browse_category',
            SpotCategories::headForCategory($category) !== null => 'idx_browse_type',
            default => null,
        };
    }

    private function budgeted(string $select, float $seconds = 1.0): string
    {
        $this->mariaDb ??= str_contains((string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB');
        return $this->mariaDb ? 'SET STATEMENT max_statement_time=' . $seconds . ' FOR ' . $select
            : 'SELECT /*+ MAX_EXECUTION_TIME(' . (int) ($seconds * 1000) . ') */ ' . substr($select, 7);
    }

    private function fallbackError(\PDOException $exception): bool
    {
        $code = (int) ($exception->errorInfo[1] ?? 0);
        return in_array($code, self::TIMEOUT_ERRORS, true) || $code === self::MISSING_FULLTEXT || $code === self::FULLTEXT_CACHE_LIMIT;
    }

    /** @param list<string> $where */
    private function where(array $where): string
    {
        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    /** @param array<string, mixed> $parameters
     *  @return list<array{browse_id: int, spot_id: string, posted_at: string}>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return array_map(static fn (array $row): array => [
            'browse_id' => (int) $row['id'], 'spot_id' => $row['spot_id'], 'posted_at' => $row['posted_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
