<?php

declare(strict_types=1);

use NnSpotty\Metadata\Repository as MetadataRepository;
use NnSpotty\Metadata\Service as MetadataService;
use NnSpotty\Metadata\View as MetadataView;

require_once __DIR__ . '/TvdbFixture.php';
require __DIR__ . '/TmdbFixture.php';
$tmdbRepo = new MetadataRepository($pdo);
check($tmdbRepo->providers() === ['movie' => 'tmdb', 'series' => 'tvdb'] && !$tmdbRepo->providerEnabled('tmdb'),
    'Standaard: TMDB primair voor films, TVDB voor series; TMDB uit tot er een sleutel is');
rejects(static fn () => $tmdbRepo->saveTmdbSettings(true, '', 'nl-NL'), 'TMDB inschakelen zonder sleutel geweigerd');
rejects(static fn () => $tmdbRepo->saveTmdbSettings(true, "bad key\n", 'nl-NL'), 'TMDB-sleutel met ongeldige tekens geweigerd');
rejects(static fn () => $tmdbRepo->saveTmdbSettings(true, 'abc', 'de-DE'), 'Onbekende TMDB-taal geweigerd');
rejects(static fn () => $tmdbRepo->saveProvider('music', 'tmdb'), 'Provider alleen voor films/series instelbaar');
rejects(static fn () => $tmdbRepo->saveProvider('movie', 'imdb'), 'Onbekende provider geweigerd');
$tmdbSecret = 'fixturetmdbtoken0123456789abcdef';
$tmdbRepo->saveTmdbSettings(true, $tmdbSecret, 'nl-NL');
$tmdbRepo->saveTmdbSettings(true, '', 'nl-NL');
check($tmdbRepo->tmdbSettings()['token'] === $tmdbSecret && $tmdbRepo->providerEnabled('tmdb'), 'Leeg TMDB-sleutelveld behoudt opgeslagen sleutel');
$tmdbAdminHtml = MetadataView::tmdbSettings($tmdbRepo, '') . MetadataView::providerForm($tmdbRepo, '', 'movie');
check(!str_contains($tmdbAdminHtml, $tmdbSecret) && str_contains($tmdbAdminHtml, 'not endorsed, certified, or otherwise approved by TMDB')
    && str_contains($tmdbAdminHtml, '/assets/images/tmdb.svg') && str_contains($tmdbAdminHtml, 'name="action" value="provider"'),
    'TMDB-instellingen tonen geen sleutel, wel logo, verplichte vermelding en providerkeuze');
$tmdbRepo->saveSettings(true, 'fixture-tvdb-key-no-external-access', '', 'nld');
$tmdbUsers = new \NnSpotty\Users\UserRepository($pdo);
$tmdbUsers->create('tmdb-reader', 'fixture-password', false);
check(!$tmdbRepo->allowed('tmdb-reader'), 'Persoonlijke opt-in blijft vereist met TMDB');
$tmdbRepo->savePreference('tmdb-reader', true);
check($tmdbRepo->allowed('tmdb-reader') && $tmdbRepo->effectiveProvider('movie') === 'tmdb' && $tmdbRepo->effectiveProvider('series') === 'tvdb',
    'Gedeelde voorkeur geldt voor beide providers; effectieve provider per type');

$tmdbMovie = \NnSpotty\Usenet\SpotnetParser::spot('tmdb-movie@example.invalid', Fixtures::headers('tmdb-movie@example.invalid'));
$tmdbMovie['id'] = hash('sha256', 'tmdb-movie');
$tmdbMovie['message_id'] = 'tmdb-movie@example.invalid';
$tmdbMovie['title'] = 'Fixture.Movie.2020.1080p.BluRay';
$tmdbMovie['category'] = 'Beeld';
$tmdbMovie['subcategories'] = ['1z00'];
$spots->saveSpot($tmdbMovie, 'free.pt');
$tvdbApi = new TvdbFixture();
$tvdbApi->searchResults = [TvdbFixture::candidate()];
$tmdbApi = new TmdbFixture();
$tmdbApi->moviePoster = false;
$tmdbService = new MetadataService($tmdbRepo, $tvdbApi, $tmdbApi);
$tmdbResult = $tmdbService->lookup($tmdbMovie);
$tmdbMeta = $tmdbResult['metadata'] ?? [];
check($tmdbResult['state'] === 'matched' && $tmdbMeta['provider'] === 'tmdb' && $tmdbMeta['id'] === 603
    && $tmdbMeta['title'] === 'TMDB Fixture Film' && $tmdbMeta['overview'] === 'TMDB English overview' && $tmdbMeta['runtime'] === 117,
    'TMDB primair: unieke titel+jaar koppelt; lege Nederlandse synopsis valt terug op Engels');
check($tmdbMeta['score'] === ['value' => 7.8, 'votes' => 1234] && $tmdbMeta['certification'] === ['country' => 'NL', 'value' => '12']
    && array_column($tmdbMeta['trailers'], 'key') === ['trailerKEY1', 'teaserKEY01'] && $tmdbMeta['imdb_id'] === 'tt1234567',
    'TMDB levert gebruikersscore, NL-classificatie en alleen veilige YouTube/Vimeo-trailers (trailer voor teaser)');
check(array_column($tmdbMeta['people'], 'name') === ['TMDB Actor', 'TMDB Director'], 'Cast plus relevante crew; overige crew genegeerd');
check(str_contains($tmdbMeta['poster'], 'artworks.thetvdb.com') && str_contains($tmdbMeta['fanart'], 'image.tmdb.org/t/p/w1280/tmdb-backdrop.jpg')
    && $tmdbMeta['links'] === ['tmdb' => 'movie/603', 'tvdb' => 'movies/1'] && $tmdbMeta['title'] === 'TMDB Fixture Film',
    'TVDB vult ontbrekende poster aan via IMDb-ID; primaire titel blijft TMDB en beide bronnen krijgen attributie');
$tmdbHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($tmdbMovie, false, true, true, $tmdbMeta);
check(str_contains($tmdbHtml, 'TMDB &middot; Film') && str_contains($tmdbHtml, 'TMDB 78%') && str_contains($tmdbHtml, 'NL 12')
    && str_contains($tmdbHtml, 'href="https://www.themoviedb.org/movie/603"') && str_contains($tmdbHtml, 'href="https://thetvdb.com/dereferrer/movies/1"')
    && str_contains($tmdbHtml, 'This website uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.')
    && str_contains($tmdbHtml, 'data-trailer-key="trailerKEY1"') && str_contains($tmdbHtml, 'Official Trailer &lt;b&gt;')
    && str_contains($tmdbHtml, 'data-trailer-frame hidden') && !str_contains($tmdbHtml, '<iframe'),
    'Detail toont score, classificatie, beide attributies, TMDB-vermelding en trailers die pas na klik laden');
$tmdbDom = new DOMDocument();
$tmdbDom->loadHTML('<?xml encoding="UTF-8">' . \NnSpotty\UI\SpotDetailPageRenderer::summary($tmdbMovie, false, true, true, $tmdbMeta, true), LIBXML_NOERROR | LIBXML_NOWARNING);
$tmdbXpath = new DOMXPath($tmdbDom);
check($tmdbXpath->query('//div[@class="hero-actions"]/div[@class="hero-buttons"]/a[@data-trailer-open and @aria-label="Trailer afspelen"]/svg')->length === 1
    && $tmdbXpath->query('//div[@class="hero-actions"]/div[@class="hero-sources"]/button[@data-tvdb-open]')->length === 1
    && $tmdbXpath->query('//div[@class="hero-sources"]//a[contains(@class,"metadata-attribution")]')->length === 2
    && $tmdbXpath->query('//div[@class="hero-copy"]//*[contains(@class,"metadata-attribution")]')->length === 0
    && $tmdbXpath->query('//section[contains(@class,"spot-hero")]//dialog')->length === 0
    && $tmdbXpath->query('//dialog[@id="trailer-dialog"]//a[@data-trailer-key="trailerKEY1"]')->length === 1,
    'Trailericoon staat bij de heroknoppen, attributie naast Metadata link en speler in een dialoog buiten de hero');
check(!str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($tmdbMovie, true, true, true, $tmdbMeta), 'data-trailer-key'),
    'Preview toont geen trailers');
require __DIR__ . '/metadata-gallery-integration.php';
$tmdbDrawer = MetadataView::controls($tmdbMovie, true, '', $tmdbRepo);
check(str_contains($tmdbDrawer, 'name="provider"') && str_contains($tmdbDrawer, '<option value="tmdb" selected>TMDB</option>')
    && str_contains($tmdbDrawer, 'data-choice-id="metadata_id"') && str_contains($tmdbDrawer, 'Metadata link'),
    'Matchpaneel laat provider kiezen met de effectieve provider als standaard');
check(array_column($tmdbService->search('Fixture Movie', 'movie'), 'provider') === ['tmdb', 'tmdb']
    && array_unique(array_column($tmdbService->search('Fixture Movie', 'movie', 'tvdb'), 'provider')) === ['tvdb'], 'Zoeken gebruikt standaard TMDB en kan expliciet TVDB gebruiken');

$unknownRejected = false;
try {
    $tmdbService->choose($tmdbMovie, ['id' => 999, 'type' => 'movie', 'provider' => 'tmdb']);
} catch (RuntimeException) {
    $unknownRejected = true;
}
check($unknownRejected && $tmdbRepo->match($tmdbMovie['id'])['choice_json'] === null, 'Onbekend TMDB-record wordt niet als keuze opgeslagen');
rejects(static fn () => $tmdbService->choose($tmdbMovie, ['id' => 1, 'type' => 'movie', 'provider' => 'imdb']), 'Onbekende provider in handmatige keuze geweigerd');
$tmdbService->choose($tmdbMovie, ['id' => 1, 'type' => 'movie', 'provider' => 'tvdb']);
$manualTvdb = $tmdbService->lookup($tmdbMovie);
check($manualTvdb['manual'] && $manualTvdb['metadata']['provider'] === 'tvdb' && $manualTvdb['metadata']['score'] === ['value' => 7.8, 'votes' => 1234],
    'Handmatige TVDB-keuze blijft TVDB en wordt aangevuld met TMDB-score via IMDb-ID');
$tmdbService->choose($tmdbMovie, null);

$tmdbRepo->saveProvider('movie', 'tvdb');
check($tmdbRepo->effectiveProvider('movie') === 'tvdb', 'Primaire provider voor films instelbaar');
$tmdbRepo->saveProvider('movie', 'tmdb');
$tmdbRepo->saveTmdbSettings(false, '', 'nl-NL');
$tvdbApi->searchResults = [TvdbFixture::candidate()];
$tvdbFallback = $tmdbService->lookup($tmdbMovie);
check($tmdbRepo->effectiveProvider('movie') === 'tvdb' && $tvdbFallback['metadata']['provider'] === 'tvdb'
    && $tvdbFallback['metadata']['score'] === null && !isset($tvdbFallback['metadata']['links']['tmdb']),
    'TMDB uitgeschakeld: films vallen terug op TVDB zonder TMDB-aanvulling');
$tmdbRepo->saveTmdbSettings(true, '', 'nl-NL');

$tmdbEpisode = $tmdbMovie;
$tmdbEpisode['id'] = hash('sha256', 'tmdb-episode');
$tmdbEpisode['message_id'] = 'tmdb-episode@example.invalid';
$tmdbEpisode['title'] = 'Fixture.Show.2017.S03E02.1080p';
$tmdbEpisode['subcategories'] = ['1z01'];
$spots->saveSpot($tmdbEpisode, 'free.pt');
$tvdbApi->searchResults = [TvdbFixture::candidate('series')];
$pdo->exec('DELETE FROM tvdb_cache');
$episodeMeta = $tmdbService->lookup($tmdbEpisode)['metadata'] ?? [];
check($episodeMeta['provider'] === 'tvdb' && $episodeMeta['type'] === 'episode' && $episodeMeta['episode_title'] === 'Fixture Episode'
    && $episodeMeta['score'] === ['value' => 8.1, 'votes' => 321] && $episodeMeta['episode_score'] === ['value' => 9.0, 'votes' => 12]
    && $episodeMeta['certification'] === ['country' => 'NL', 'value' => '16'] && $episodeMeta['trailers'][0]['site'] === 'Vimeo'
    && $episodeMeta['links']['tmdb'] === 'tv/700/season/3/episode/2',
    'Series: TVDB primair, TMDB vult via TVDB-ID score, afleveringsscore, classificatie en trailer van dezelfde aflevering aan');
$tmdbRepo->saveProvider('series', 'tmdb');
$tmdbEpisodeMeta = $tmdbService->lookup($tmdbEpisode)['metadata'] ?? [];
check($tmdbEpisodeMeta['provider'] === 'tmdb' && $tmdbEpisodeMeta['episode_title'] === 'TMDB Episode' && $tmdbEpisodeMeta['id'] === 7002
    && $tmdbEpisodeMeta['links']['tmdb'] === 'tv/700/season/3/episode/2',
    'Series met TMDB primair koppelen de exacte TMDB-aflevering');
$episodeGalleryHtml = MetadataView::hero($tmdbEpisode, $tmdbEpisodeMeta, '', true);
check(str_contains($episodeGalleryHtml, 'data-image-gallery="posters"')
    && str_contains($episodeGalleryHtml, 'data-image-gallery="fanart"')
    && str_contains($episodeGalleryHtml, '<template data-image-gallery-items="posters"><a href="https://image.tmdb.org/t/p/original/still.jpg"')
    && count($tmdbEpisodeMeta['posters']) >= 2 && count($tmdbEpisodeMeta['backdrops']) >= 2,
    'Aflevering behoudt de still als eerste vergroting en kan door serieposters en fanart bladeren');
rejects(static fn () => $tmdbService->choose($tmdbEpisode, ['id' => 700, 'type' => 'series', 'provider' => 'tmdb', 'season' => 9, 'episode' => 9]),
    'Onvindbare handmatige TMDB-aflevering wordt geweigerd');
$tmdbRepo->saveProvider('series', 'tvdb');

require __DIR__ . '/metadata-fallback-integration.php';

$tmdbApi->fail = true;
$tmdbFailing = $tmdbMovie;
$tmdbFailing['title'] = 'Fixture.Movie.2020.720p.WEB';
$tmdbFailing['id'] = hash('sha256', 'tmdb-failing');
$tmdbFailing['message_id'] = 'tmdb-failing@example.invalid';
$spots->saveSpot($tmdbFailing, 'free.pt');
$pdo->exec('DELETE FROM tvdb_cache');
$tmdbFallbackResult = $tmdbService->lookup($tmdbFailing);
check($tmdbFallbackResult['state'] === 'matched' && $tmdbFallbackResult['metadata']['provider'] === 'tvdb'
    && $tmdbFallbackResult['fallback_from'] === 'tmdb' && count($tmdbFallbackResult['warnings']) === 1,
    'TMDB-storing valt terug op TVDB met expliciete waarschuwing en laat de spot intact');
$tmdbApi->fail = false;
$tvdbApi->fail = true;
$pdo->exec('DELETE FROM tvdb_cache');
$tmdbRepo->choose($tmdbFailing['id'], 'stale', null);
$partial = $tmdbService->lookup($tmdbFailing);
check($partial['state'] === 'matched' && $partial['metadata']['provider'] === 'tmdb' && !isset($partial['metadata']['links']['tvdb'])
    && count($partial['warnings']) === 1 && str_contains(MetadataView::message($partial), 'Aanvullen via TVDB is mislukt'),
    'Storing bij de aanvullende provider breekt het primaire resultaat niet en blijft zichtbaar');
$tvdbApi->fail = false;

$tmdbRepo->saveSettings(false, '', '', 'nld');
$tmdbRepo->saveTmdbSettings(false, '', 'nl-NL', true);
check(!$tmdbRepo->allowed('tmdb-reader') && $tmdbRepo->tmdbSettings()['token'] === '', 'TMDB-sleutel wisbaar; zonder providers geen metadata');
$tmdbUsers->delete('tmdb-reader');
foreach ([$tmdbMovie['id'], $tmdbEpisode['id'], $tmdbFailing['id']] as $id) $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$id]);
