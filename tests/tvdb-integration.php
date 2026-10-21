<?php

declare(strict_types=1);

use NnSpotty\Metadata\Repository as MetadataRepository;
use NnSpotty\Metadata\Service as MetadataService;
use NnSpotty\Metadata\View as MetadataView;

require __DIR__ . '/TvdbFixture.php';
$metaRepo = new MetadataRepository($pdo);
$metadataUser = new \NnSpotty\Users\UserRepository($pdo);
$metadataUser->create('metadata-reader', 'fixture-password', false);
check(!$metaRepo->settings()['enabled'] && !$metaRepo->preference('metadata-reader'), 'TVDB en persoonlijke toestemming zijn standaard uit');
$metaRepo->savePreference('metadata-reader', true);
check(!$metaRepo->allowed('metadata-reader'), 'Persoonlijke toestemming kan globale blokkade niet omzeilen');
$metaKey = 'fixture-tvdb-key-no-external-access';
$metaRepo->saveSettings(true, $metaKey, 'fixture-pin', 'nld');
check($metaRepo->allowed('metadata-reader'), 'Effectieve toestemming vereist globale en persoonlijke opt-in');
$metaRepo->saveSettings(true, '', '', 'nld');
check($metaRepo->settings()['api_key'] === $metaKey && $metaRepo->settings()['pin'] === 'fixture-pin', 'Lege TVDB-geheimvelden behouden opgeslagen key en PIN');
check(!str_contains(MetadataView::settings($metaRepo, '', null), $metaKey)
    && !str_contains(MetadataView::settings($metaRepo, '', 'metadata-reader'), 'fixture-pin'), 'TVDB-instellingen renderen geen geheimen');
$metaRepo->saveSettings(true, '', '', 'nld', true);
check($metaRepo->settings()['pin'] === '', 'Opgeslagen TVDB-PIN expliciet verwijderbaar');
rejects(static fn () => $metaRepo->saveSettings(true, "invalid\nkey", '', 'nld'), 'TVDB-key met controltekens geweigerd');
rejects(static fn () => $metaRepo->saveSettings(true, '', '', 'bogus'), 'Onbekende TVDB-taal geweigerd');
$metaSpot = \NnSpotty\Usenet\SpotnetParser::spot('metadata-movie@example.invalid', Fixtures::headers('metadata-movie@example.invalid'));
$metaSpot['id'] = hash('sha256', 'metadata-movie');
$metaSpot['message_id'] = 'metadata-movie@example.invalid';
$metaSpot['title'] = 'Fixture.Movie.2020.1080p.WEB-DL';
$metaSpot['category'] = 'Beeld';
$metaSpot['subcategories'] = ['1z00'];
$spots->saveSpot($metaSpot, 'free.pt');
$metaApi = new TvdbFixture();
$metaApi->searchResults = [TvdbFixture::candidate()];
$metadata = new MetadataService($metaRepo, $metaApi);
$movieResult = $metadata->lookup($metaSpot);
check($movieResult['state'] === 'matched' && $movieResult['metadata']['title'] === 'Voorbeeldtitel'
    && str_contains($movieResult['metadata']['poster'], 'poster.jpg') && str_contains($movieResult['metadata']['fanart'], 'fanart.jpg'),
    'Film wordt overtuigend gekoppeld met vertaalde synopsis, poster en fanart');
check($movieResult['metadata']['date'] === '2020-01-01' && $movieResult['metadata']['runtime'] === 116,
    'Film gebruikt releasedatum en filmduur, niet serievelden');
check($movieResult['metadata']['people'][0]['id'] === 55 && $movieResult['metadata']['companies'][0]['id'] === 60
    && count($movieResult['metadata']['companies']) === 1
    && $movieResult['metadata']['remote_ids'][0]['id'] === 'tt1234567' && !isset($movieResult['metadata']['rating']),
    'Persoon/bedrijf/externe IDs bewaard voor vervolgfeatures; populariteit niet als rating gebruikt');
$before = count($metaApi->calls);
check($metadata->lookup($metaSpot) === $movieResult && count($metaApi->calls) === $before, 'Heropenen detail gebruikt persistente match zonder API-aanvraag');
$metaRepo->result($metaSpot['id'], MetadataService::signature($metaSpot, 'nld'), ['state' => 'unmatched'], 86400);
check($metadata->lookup($metaSpot)['state'] === 'matched', 'Nieuwe releaseparser hercontroleert oude negatieve matches zonder cache handmatig te wissen');
$copy = $metaSpot;
$copy['id'] = hash('sha256', 'metadata-copy');
$copy['message_id'] = 'metadata-copy@example.invalid';
$spots->saveSpot($copy, 'free.pt');
check($metadata->lookup($copy)['state'] === 'matched' && count($metaApi->calls) === $before, 'Andere spot van dezelfde release deelt zoek- en entiteitcache');
$html = \NnSpotty\UI\SpotDetailPageRenderer::summary($metaSpot, false, true, true, $movieResult['metadata']);
check(str_contains($html, 'metadata-hero') && str_contains($html, 'Cast &amp; Crew') && str_contains($html, 'Oorspronkelijke spottitel:')
    && str_contains($html, '&lt;script&gt;niet uitvoeren&lt;/script&gt;') && !str_contains($html, '<script>niet uitvoeren')
    && str_contains($html, 'Omschrijving') && str_contains($html, 'data-sab-send='),
    'Metadata verrijkt hero en cast veilig; originele titel, omschrijving en acties blijven aanwezig');
$hiddenHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($metaSpot, false, false, true, $movieResult['metadata']);
foreach ([$html, $hiddenHtml] as $attributedHtml) {
    check(str_contains($attributedHtml, 'class="metadata-attribution small"')
        && str_contains($attributedHtml, 'href="https://thetvdb.com/dereferrer/movies/1"')
        && str_contains($attributedHtml, '/assets/images/tvdb-dark.png')
        && str_contains($attributedHtml, '/assets/images/tvdb-light.png')
        && str_contains($attributedHtml, 'Metadata van TheTVDB'),
        'Zichtbare metadata krijgt klikbare TVDB-attributie met lokaal thema-logo, ook met postafbeeldingen uit');
}
$withLink = \NnSpotty\UI\SpotDetailPageRenderer::summary($metaSpot, false, metadataLink: true);
check(str_contains($withLink, 'data-tvdb-open') && !str_contains($withLink, 'metadata-attribution'),
    'Oorspronkelijke hero biedt matchpaneellink maar claimt zonder TVDB-data geen attributie');
$drawer = MetadataView::controls($metaSpot, true, '');
check(str_starts_with($drawer, '<dialog id="metadata-drawer"') && str_contains($drawer, 'aria-labelledby="metadata-title"')
    && str_contains($drawer, 'data-tvdb-close') && !str_contains($drawer, '<details'),
    'Alle matchinformatie en beheeropties staan in een aanvankelijk gesloten toegankelijk slide-in-dialoog');
$fullMetadataPage = \NnSpotty\UI\SpotDetailPageRenderer::render('metadata-reader', true, $metaSpot, [], '', metadataControls: $drawer);
check(strpos($fullMetadataPage, 'data-tvdb-open') < strpos($fullMetadataPage, '<dialog id="metadata-drawer"')
    && !str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($metaSpot, true), 'data-tvdb-open'),
    'TVDB-link staat in hero, dialoog buiten vervangbare hero-inhoud; preview blijft zonder matchbeheer');
check(!str_contains($hiddenHtml, 'https://artworks.thetvdb.com') && !str_contains($hiddenHtml, 'hero-backdrop'),
    'Afbeeldingen uit blokkeert ook TVDB-posters, fanart en castfoto');
check(str_contains($html, 'class="release-quality"') && str_contains($withLink, 'class="release-quality"'),
    'Releasekwaliteit is aanwezig met en zonder TVDB-metadata');
$peopleMetadata = $movieResult['metadata'];
$peopleMetadata['people'][] = ['id' => 56, 'name' => 'Person without photo', 'role' => 'Director', 'type' => 'Director', 'image' => ''];
$peopleHtml = MetadataView::people($peopleMetadata, true);
check(str_contains($peopleHtml, 'src="/assets/images/placeholder-small.png"')
    && str_contains($peopleHtml, "this.onerror=null;this.src='/assets/images/placeholder-small.png'")
    && !str_contains($peopleHtml, 'this.hidden=true'), 'Ontbrekende en defecte cast/crewfoto krijgen dezelfde ronde lokale placeholder zonder errorloop');
check(!str_contains(MetadataView::people($peopleMetadata, false), '<img'), 'Afbeeldingen uit voorkomt ook lokale cast/crew-placeholderverzoeken');
$metadata->choose($metaSpot, ['blocked' => true]);
check($metadata->lookup($metaSpot)['state'] === 'blocked', 'Admin kan metadata per spot expliciet onderdrukken');
$metaRepo->result($metaSpot['id'], MetadataService::signature($metaSpot, 'nld'), ['state' => 'unmatched'], 86400);
check($metadata->lookup($metaSpot)['state'] === 'blocked', 'Parserupdate behoudt eerder opgeslagen gedeelde blokkeringen');
$metadata->choose($metaSpot, null);
check($metadata->lookup($metaSpot)['state'] === 'matched', 'Reset herstelt automatische matching');
$metadata->choose($metaSpot, ['id' => 1, 'type' => 'movie']);
check($metadata->lookup($metaSpot)['manual'] === true, 'Handmatige gedeelde filmcorrectie blijft bewaard');
$metaRepo->result($metaSpot['id'], MetadataService::signature($metaSpot, 'nld'), ['state' => 'matched'], 604800);
check($metadata->lookup($metaSpot)['manual'] === true, 'Parserupdate behoudt eerder opgeslagen handmatige keuzes met originele inputidentiteit');
$changed = $metaSpot;
$changed['title'] = 'Different Movie 2025';
check($metadata->lookup($changed)['state'] === 'ambiguous', 'Gewijzigde bronrelease invalideert oude match en correctie');
$metaRepo->lock($copy['id']);
$secondPdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$secondPdo->exec('USE `' . $database . '`');
$pdo->exec("UPDATE tvdb_matches SET expires_at = NULL WHERE spot_id = '" . $copy['id'] . "'");
check((new MetadataService(new MetadataRepository($secondPdo), $metaApi))->lookup($copy)['state'] === 'busy', 'Parallelle matching doet geen dubbele providerrequests');
$metaRepo->unlock($copy['id']);
$seriesSpot = $metaSpot;
$seriesSpot['id'] = hash('sha256', 'metadata-episode');
$seriesSpot['message_id'] = 'metadata-episode@example.invalid';
$seriesSpot['title'] = 'Fixture.Show.2017.S03E02.1080p';
$seriesSpot['subcategories'] = ['1z01'];
$spots->saveSpot($seriesSpot, 'free.pt');
$metaApi->searchResults = [TvdbFixture::candidate('series')];
$episodeResult = $metadata->lookup($seriesSpot);
check($episodeResult['state'] === 'matched' && $episodeResult['metadata']['type'] === 'episode'
    && $episodeResult['metadata']['season'] === 3 && $episodeResult['metadata']['episode'] === 2
    && $episodeResult['metadata']['episode_title'] === 'Fixture Episode' && $episodeResult['metadata']['overview'] === 'Episode summary',
    'Exacte serie plus officieel seizoen/aflevering levert juiste aflevering en synopsis');
$episodePreview = \NnSpotty\UI\SpotDetailPageRenderer::summary($seriesSpot, true, metadata: $episodeResult['metadata']);
check(str_contains($episodePreview, '<h2>Voorbeeldtitel</h2>') && str_contains($episodePreview, '<h3 class="h4">Fixture Episode</h3>')
    && str_contains($episodePreview, 'episode-hero') && str_contains($episodePreview, 'episodes/still.jpg')
    && str_contains($episodePreview, 'https://thetvdb.com/dereferrer/episodes/100') && !str_contains($episodePreview, '<h1>'),
    'Afleveringpreview gebruikt compacte headingstructuur, afleveringstill en juiste bronvermelding');
$seriesSpot['title'] = 'Fixture.Show.2017.S03E02E03.1080p';
$pack = $metadata->lookup($seriesSpot);
check($pack['metadata']['type'] === 'series' && $pack['metadata']['episode'] === null, 'Multi-episodepakket wordt niet als losse aflevering gepresenteerd');
check($pack['metadata']['runtime'] === 42 && $pack['metadata']['date'] === '2017-01-01', 'Serie gebruikt eerste uitzenddatum en gemiddelde speelduur');
$episodeCacheKey = '/series/10/episodes/official?' . http_build_query(['page' => 0, 'season' => 3, 'episodeNumber' => 2]);
$metaRepo->cache($episodeCacheKey, ['episodes' => []], 86400);
$seriesSpot['title'] = 'Fixture.Show.2017.S03E02.1080p';
$missingResult = $metadata->lookup($seriesSpot);
check($missingResult['metadata']['episode_missing'] && $missingResult['metadata']['type'] === 'series',
    'Ontbrekende automatische aflevering toont eerlijk alleen seriemetadata');
$oldChoice = $metaRepo->match($seriesSpot['id'])['choice_json'];
rejects(static fn () => $metadata->choose($seriesSpot, ['id' => 10, 'type' => 'series', 'season' => 3, 'episode' => 2]),
    'Onvindbare handmatige aflevering wordt geweigerd voor opslaan');
check($metaRepo->match($seriesSpot['id'])['choice_json'] === $oldChoice, 'Ongeldige handmatige aflevering overschrijft geen eerdere keuze');
$seriesSpot['title'] = 'De.Bondgenoten.S04E01.DUTCH.1080p.WEB.h264-KIJKDOOS';
$metaApi->searchResults = [['tvdb_id' => '10', 'type' => 'series', 'name' => 'De Bondgenoten', 'year' => '2023']];
$fourthSeasonKey = '/series/10/episodes/official?' . http_build_query(['page' => 0, 'season' => 4, 'episodeNumber' => 1]);
$metaRepo->cache($fourthSeasonKey, ['episodes' => [
    ['id' => 100, 'seriesId' => 10, 'seasonNumber' => 3, 'number' => 1],
    ['id' => 101, 'seriesId' => 11, 'seasonNumber' => 4, 'number' => 1],
    ['id' => 102, 'seriesId' => 10, 'seasonNumber' => 4, 'number' => 2],
]], 86400);
$bondgenoten = $metadata->lookup($seriesSpot);
check($bondgenoten['metadata']['type'] === 'series' && $bondgenoten['metadata']['episode_missing']
    && $bondgenoten['metadata']['requested_episode'] === 1 && $bondgenoten['metadata']['season'] === 4,
    'Bondgenoten S04E01 wordt correct geparsed maar nooit vervangen door ander seizoen, nummer of andere serie');
$missingHtml = MetadataView::hero($seriesSpot, $bondgenoten['metadata'], '', false);
check(str_contains($missingHtml, 'seizoen 4, aflevering 1') && str_contains($missingHtml, 'niet door TheTVDB bevestigd')
    && !str_contains($missingHtml, '<p>Seizoen 4'), 'Ontbrekend officieel seizoen/aflevering wordt niet als bevestigde TVDB-data gepresenteerd');
$metaRepo->cache($fourthSeasonKey, ['episodes' => [['id' => 100, 'seriesId' => 10, 'seasonNumber' => 4, 'number' => 1]]], 86400);
$metaRepo->cache('/episodes/100/extended?', ['id' => 100, 'seriesId' => 10, 'seasonNumber' => 4, 'number' => 1,
    'name' => 'Fixture S04E01', 'overview' => 'Exact episode fixture'], 86400);
$metadata->choose($seriesSpot, null);
$available = $metadata->lookup($seriesSpot);
check($available['metadata']['type'] === 'episode' && $available['metadata']['season'] === 4
    && $available['metadata']['episode'] === 1 && $available['metadata']['episode_title'] === 'Fixture S04E01',
    'Dezelfde S04E01-release koppelt exact zodra de provider die officiële aflevering aanbiedt');
$metaRepo->cache($fourthSeasonKey, ['episodes' => [
    ['id' => 100, 'seriesId' => 10, 'seasonNumber' => 4, 'number' => 1],
    ['id' => 102, 'seriesId' => 10, 'seasonNumber' => 4, 'number' => 1],
]], 86400);
$metadata->choose($seriesSpot, null);
check($metadata->lookup($seriesSpot)['metadata']['episode_missing'], 'Dubbele officiële afleveringsnummers blijven ambigu en leveren geen willekeurige keuze');
$metaRepo->cache('expired-fixture', ['expired' => true], -1);
$metaRepo->cache('fresh-fixture', ['fresh' => true], 3600);
$cleanupMetadata = (new \NnSpotty\Database\Migrator($pdo))->cleanup(90);
check($cleanupMetadata['tvdb_cache_deleted'] >= 1 && $metaRepo->cached('expired-fixture') === null
    && $metaRepo->cached('fresh-fixture')['fresh'], 'Databasecleanup ruimt alleen verlopen TVDB-providercache op');
$metaRepo->cache('request-budget:' . gmdate('YmdHi'), ['count' => 89], 120);
$metaRepo->claimRequest();
$limitRejected = false;
try {
    $metaRepo->claimRequest();
} catch (RuntimeException $exception) {
    $limitRejected = str_contains($exception->getMessage(), '90 per minuut');
}
check($limitRejected, 'Installatiebreed TVDB-budget laat request 90 toe en weigert request 91');
$metaRepo->cache('request-budget:' . gmdate('YmdHi'), ['count' => 0], 120);
$metaApi->overview = str_repeat('a', 11999) . "\xC3\xA9";
$metaRepo->cache('/movies/1/translations/nld?', [], -1);
$metadata->choose($copy, ['id' => 1, 'type' => 'movie']);
check($metadata->lookup($copy)['metadata']['overview'] === $metaApi->overview, 'Lange meertalige synopsis blijft geldige UTF-8 bij lengtegrens');
$metaRepo->savePreference('metadata-reader', false);
check(substr_count(MetadataView::controls($metaSpot, true, '<input name="_csrf" value="fixture-token">'), 'method="post" action="/metadata"') === 3,
    'Metadatacontroles sturen ook zonder JavaScript nooit CSRF via GET-URLs');
check(!$metaRepo->allowed('metadata-reader') && str_contains(MetadataView::controls($metaSpot, false, ''), 'data-tvdb')
    && !str_contains(MetadataView::controls($metaSpot, false, ''), 'data-tvdb-choice'), 'Persoonlijke uitschakeling en admin-only matchcontrols');
$failing = $metaSpot;
$failing['title'] = 'Provider Failure 2024';
$metaApi->fail = true;
$failedResult = $metadata->lookup($failing);
$before = count($metaApi->calls);
check($failedResult['state'] === 'error' && $metadata->lookup($failing)['state'] === 'error'
    && count($metaApi->calls) === $before, 'Providerfout is expliciet en vijf minuten gecachet zonder herhaalde verzoeken');
$metaRepo->saveSettings(true, 'changed-fixture-key', '', 'nld');
check(!$metaRepo->match($metaSpot['id'])['fresh'], 'Nieuwe toegangsgegevens maken oude fout-/matchstatus opnieuw controleerbaar');
$metaRepo->token('changed-fixture-key', '', 'fixture-token');
check($metaRepo->settings()['token'] === 'fixture-token', 'TVDB-token persistent opgeslagen');
$metaRepo->saveSettings(false, '', '', 'nld');
check($metaRepo->settings()['token'] === null, 'Instellingen opslaan invalideert eerder token');
$metadataUser->delete('metadata-reader');
check(!$metaRepo->preference('metadata-reader'), 'Userverwijdering verwijdert TVDB-voorkeur');
foreach ([$metaSpot['id'], $copy['id'], $seriesSpot['id']] as $id) $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$id]);
check($metaRepo->match($metaSpot['id']) === null, 'Spotverwijdering verwijdert gedeelde match via foreign key');
