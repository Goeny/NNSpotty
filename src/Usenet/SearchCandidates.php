<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

final class SearchCandidates
{
    /**
     * Materialize the union before looking up spots; a correlated OR/EXISTS can scan every title instead
     * of using either FULLTEXT index. UNION also removes spots found in both indexes.
     *
     * @param array<string, mixed> $parameters
     */
    public static function condition(string $spotId, string $fulltext, bool $metadata, array &$parameters): string
    {
        $parameters['search_ft'] = $fulltext;
        $candidates = 'SELECT spot_id FROM spot_browse WHERE MATCH (title) AGAINST (:search_ft IN BOOLEAN MODE)';
        if ($metadata) {
            $parameters['search_ft_meta'] = $fulltext;
            $candidates .= ' UNION SELECT spot_id FROM metadata_search_index
                WHERE MATCH (search_text) AGAINST (:search_ft_meta IN BOOLEAN MODE)';
        }
        return $spotId . ' IN (SELECT spot_id FROM (' . $candidates . ') search_candidates)';
    }
}
