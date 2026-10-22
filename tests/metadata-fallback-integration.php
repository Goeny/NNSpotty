<?php

declare(strict_types=1);

use NnSpotty\Metadata\Service as MetadataService;
use NnSpotty\Metadata\View as MetadataView;

$resetFallback = static function (array $spot, string $type, string $primary) use ($pdo, $tmdbRepo, $tvdbApi, $tmdbApi): void {
    $tmdbRepo->saveProvider($type, $primary);
    $tmdbRepo->choose($spot['id'], 'stale', null);
    $pdo->exec('DELETE FROM tvdb_cache');
    $tvdbApi->calls = $tmdbApi->calls = [];
    $tvdbApi->fail = $tmdbApi->fail = false;
    $tvdbApi->failPaths = [];
    $tvdbApi->missingEpisode = $tmdbApi->missingEpisode = false;
    $tvdbApi->searchResults = [TvdbFixture::candidate($type)];
    $tmdbApi->movieResults = $tmdbApi->seriesResults = null;
};
$searchCount = static fn (array $calls, string $path): int => count(array_filter($calls, static fn (array $call): bool => $call[0] === $path));

foreach (['movie' => $tmdbMovie, 'series' => $tmdbEpisode] as $fallbackType => $fallbackSpot) {
    foreach (['tvdb', 'tmdb'] as $primary) {
        $other = $primary === 'tvdb' ? 'tmdb' : 'tvdb';
        $resetFallback($fallbackSpot, $fallbackType, $primary);
        if ($primary === 'tvdb') $tvdbApi->searchResults = [];
        elseif ($fallbackType === 'movie') $tmdbApi->movieResults = [];
        else $tmdbApi->seriesResults = [];
        $fallback = $tmdbService->lookup($fallbackSpot);
        check($fallback['state'] === 'matched' && !$fallback['manual'] && $fallback['metadata']['provider'] === $other
            && $fallback['fallback_from'] === $primary && !isset($fallback['warnings']),
            "Geen $primary-match voor $fallbackType: automatisch unieke $other-match");
        $callsBefore = [count($tvdbApi->calls), count($tmdbApi->calls)];
        check($tmdbService->lookup($fallbackSpot) === json_decode(json_encode($fallback, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR)
            && $callsBefore === [count($tvdbApi->calls), count($tmdbApi->calls)],
            "Geslaagde $fallbackType-terugval hergebruikt resultaatcache");
        check(str_contains(MetadataView::message($fallback), 'Terugval vanaf'),
            'Status benoemt de providerterugval');

        $resetFallback($fallbackSpot, $fallbackType, $primary);
        $primaryMatch = $tmdbService->lookup($fallbackSpot);
        check($primaryMatch['metadata']['provider'] === $primary && !isset($primaryMatch['fallback_from'])
            && $searchCount($primary === 'tvdb' ? $tmdbApi->calls : $tvdbApi->calls,
                $primary === 'tvdb' ? ($fallbackType === 'movie' ? '/search/movie' : '/search/tv') : '/search') === 0,
            "Geslaagde $primary-match blijft primair; aanvulling gebruikt externe IDs, geen tweede titelzoekactie");
    }
}

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->searchResults[] = array_replace(TvdbFixture::candidate(), ['tvdb_id' => '2']);
check($tmdbService->lookup($tmdbMovie)['metadata']['provider'] === 'tmdb',
    'Ambigue primaire matches worden niet gegokt; unieke secundaire match wordt geprobeerd');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->searchResults = [];
$tmdbApi->movieResults = [];
$neither = $tmdbService->lookup($tmdbMovie);
check($neither['state'] === 'unmatched' && $neither['providers'] === ['tvdb', 'tmdb']
    && str_contains(MetadataView::message($neither), 'TheTVDB of TMDB'),
    'Beide providers leeg: negatieve cache en status benoemen beide pogingen');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->searchResults = [array_replace(TvdbFixture::candidate(), ['year' => '1999'])];
$tmdbApi->movieResults = [['id' => 603, 'title' => 'Other Movie', 'release_date' => '2020-01-01']];
check($tmdbService->lookup($tmdbMovie)['state'] === 'ambiguous',
    'Terugval versoepelt titel- en jaaridentiteit niet');

foreach (['tvdb', 'tmdb'] as $primary) {
    $resetFallback($tmdbEpisode, 'series', $primary);
    if ($primary === 'tvdb') $tvdbApi->missingEpisode = true;
    else $tmdbApi->missingEpisode = true;
    $episodeFallback = $tmdbService->lookup($tmdbEpisode);
    check($episodeFallback['metadata']['provider'] !== $primary && $episodeFallback['metadata']['type'] === 'episode'
        && $episodeFallback['metadata']['season'] === 3 && $episodeFallback['metadata']['episode'] === 2,
        "Ontbrekende aflevering bij $primary: andere provider bevestigt exact S03E02");
}
$resetFallback($tmdbEpisode, 'series', 'tvdb');
$tvdbApi->missingEpisode = $tmdbApi->missingEpisode = true;
$missingBoth = $tmdbService->lookup($tmdbEpisode);
check($missingBoth['metadata']['provider'] === 'tvdb' && $missingBoth['metadata']['type'] === 'series'
    && $missingBoth['metadata']['episode_missing'] && $missingBoth['metadata']['episode'] === null,
    'Beide afleveringen ontbreken: primaire seriemetadata blijft behouden, geen verzonnen aflevering');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->failPaths = ['/search'];
$outageFallback = $tmdbService->lookup($tmdbMovie);
check($outageFallback['metadata']['provider'] === 'tmdb' && count($outageFallback['warnings']) === 1
    && $searchCount($tvdbApi->calls, '/search') === 1
    && $searchCount($tvdbApi->calls, '/search/remoteid/tt1234567') === 0
    && str_contains(MetadataView::message($outageFallback), 'TVDB kon niet worden opgehaald'),
    'Primaire zoekfout: secundaire match plus zichtbare waarschuwing, geen herhaalde request naar falende provider');
$warningTtl = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) FROM tvdb_matches WHERE spot_id = ?');
$warningTtl->execute([$tmdbMovie['id']]);
check((int) $warningTtl->fetchColumn() <= 300, 'Resultaat met providerwaarschuwing vernieuwt na vijf minuten, niet na zeven dagen');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->failPaths = ['/movies/1/extended'];
check($tmdbService->lookup($tmdbMovie)['metadata']['provider'] === 'tmdb',
    'Ook een fout bij primaire details valt terug op de andere provider');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tvdbApi->fail = $tmdbApi->fail = true;
$bothFail = $tmdbService->lookup($tmdbMovie);
check($bothFail['state'] === 'error' && count($bothFail['warnings']) === 2
    && str_contains(MetadataView::message($bothFail), 'TMDB kon niet worden opgehaald'),
    'Beide providers falen: expliciete fout met beide waarschuwingen, geen unmatched-success');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tmdbService->choose($tmdbMovie, ['id' => 1, 'type' => 'movie', 'provider' => 'tvdb']);
$pdo->exec('DELETE FROM tvdb_cache');
$tvdbApi->fail = true;
$manualFailure = $tmdbService->lookup($tmdbMovie);
check($manualFailure['state'] === 'error' && $searchCount($tmdbApi->calls, '/search/movie') === 0,
    'Handmatige koppeling wordt bij providerfout niet automatisch vervangen');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tmdbService->choose($tmdbMovie, ['blocked' => true]);
$blockedCalls = [count($tvdbApi->calls), count($tmdbApi->calls)];
check($tmdbService->lookup($tmdbMovie)['state'] === 'blocked'
    && $blockedCalls === [count($tvdbApi->calls), count($tmdbApi->calls)], 'Blokkering doet ook geen terugvalrequests');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$tmdbRepo->saveTmdbSettings(false, '', 'nl-NL');
$tvdbApi->searchResults = [];
check($tmdbService->lookup($tmdbMovie)['state'] === 'unmatched' && $tmdbApi->calls === [],
    'Uitgeschakelde tweede provider wordt niet voor terugval gebruikt');
$tmdbRepo->saveTmdbSettings(true, '', 'nl-NL');

$resetFallback($tmdbMovie, 'movie', 'tvdb');
$oldFallbackHash = MetadataService::signature($tmdbMovie, $tmdbRepo->profile() . ':release-v3');
$tmdbRepo->result($tmdbMovie['id'], $oldFallbackHash, ['state' => 'unmatched'], 86400);
$tvdbApi->searchResults = [];
check($tmdbService->lookup($tmdbMovie)['metadata']['provider'] === 'tmdb',
    'Oude verse negatieve cache wordt bij detail opnieuw beoordeeld met beide providers');

$resetFallback($tmdbMovie, 'movie', 'tmdb');
$resetFallback($tmdbEpisode, 'series', 'tvdb');
$tvdbApi->searchResults = [TvdbFixture::candidate()];
