<?php

declare(strict_types=1);

$fanartKey = str_repeat('fixturefanartkey0', 2);
$fanartSettingsBefore = $musicRepo->fanartSettings();
check(!$fanartSettingsBefore['enabled'] && $fanartSettingsBefore['api_key'] === ''
    && !$musicRepo->fanartPreference('music-reader'), 'Fanart is standaard globaal en persoonlijk uit zonder key');
rejects(static fn () => $musicRepo->saveFanartSettings(true, ''), 'Fanart inschakelen zonder key geweigerd');
rejects(static fn () => $musicRepo->saveFanartSettings(true, "bad\r\nkey"), 'Fanart-key met controletekens geweigerd');
$musicRepo->enable(true);
$musicRepo->saveFanartSettings(true, $fanartKey);
$musicRepo->saveFanartSettings(true, '');
check($musicRepo->fanartSettings()['api_key'] === $fanartKey, 'Leeg fanart-keyveld behoudt bestaand geheim');
check(!$musicRepo->fanartAllowed('music-reader'), 'Globale fanarttoestemming omzeilt persoonlijke keuze niet');
$musicRepo->saveFanartPreference('music-reader', true);
check($musicRepo->fanartAllowed('music-reader'), 'Fanart vereist globale en persoonlijke muziek- en fanarttoestemming');
$fanartFixture = new class implements \NnSpotty\Music\FanartGateway {
    public int $calls = 0;
    public bool $fail = false;
    public ?array $data = [
        'mbid_id' => MusicFixture::ARTIST,
        'artistbackground' => [
            ['url' => 'http://assets.fanart.tv/fanart/music/fixture/artistbackground/low.jpg', 'likes' => '1'],
            ['url' => 'https://assets.fanart.tv/fanart/music/fixture/artistbackground/high.jpg', 'likes' => '9'],
        ],
    ];
    public function get(string $artistId): ?array
    {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('Synthetic fanart outage');
        return $this->data;
    }
};
$fanartService = new \NnSpotty\Music\Fanart($musicRepo, $fanartFixture);
$fanartMusicApi = new MusicFixture();
$fanartMusicService = new \NnSpotty\Music\Service($musicRepo, $fanartMusicApi);
$fanartMusicSpot = $musicDbSpot;
foreach (['multipleArtists', 'variousArtists'] as $artistCase) {
    $pdo->exec('DELETE FROM music_cache');
    $fanartMusicApi->$artistCase = true;
    $fanartMusicService->choose($fanartMusicSpot, ['type' => 'release-group', 'id' => MusicFixture::GROUP]);
    check($fanartMusicService->lookup($fanartMusicSpot)['metadata']['artist_id'] === '',
        'MusicBrainz-credit met meerdere/Various Artists krijgt geen willekeurige fanartidentiteit');
    $fanartMusicApi->$artistCase = false;
}
$fanartMetadata = $musicResult['metadata'];
$fanartCacheKey = \NnSpotty\Music\Fanart::cacheKey(MusicFixture::ARTIST, $fanartKey);
$fanartSpot = $musicDbSpot;
$fanartSpot['title'] = 'Fixture Artist - Fixture Album (2020) FLAC';
$musicRepo->result($fanartSpot, $musicResult, 604800);
$fanartMatchBefore = $musicRepo->match($fanartSpot['id']);
$fanartCacheBefore = $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll();
check(!isset($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart'])
    && $fanartFixture->calls === 0 && $fanartCacheBefore === $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll(),
    'Fanart-previewcachemiss schrijft niets en vraagt niets extern op');
$fanartEnriched = $fanartService->enrich($fanartMetadata);
check($fanartEnriched['fanart'] === 'https://assets.fanart.tv/fanart/music/fixture/artistbackground/high.jpg'
    && $fanartFixture->calls === 1 && $musicRepo->match($fanartSpot['id']) === $fanartMatchBefore,
    'Fanart verrijkt via exacte artiest-ID, bewaart albumcover en wijzigt muziekmatch niet');
check($fanartService->enrich($fanartMetadata) === $fanartEnriched && $fanartFixture->calls === 1,
    'Herhaalde fanartdiscovery gebruikt gedeelde artiestcache zonder providerrequest');
$fanartExpiry = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) FROM music_cache WHERE cache_key = ?');
$fanartExpiry->execute([hash('sha256', $fanartCacheKey)]);
$fanartRemaining = (int) $fanartExpiry->fetchColumn();
check($fanartRemaining > 604700 && $fanartRemaining <= 604800,
    'Positieve fanartcache blijft zeven dagen geldig');
$fanartArtistMetadata = $musicArtist['metadata'];
check($fanartArtistMetadata['artist_id'] === MusicFixture::ARTIST
    && $fanartService->enrich($fanartArtistMetadata)['fanart'] === $fanartEnriched['fanart']
    && $fanartFixture->calls === 1, 'Artiest/discografie gebruikt dezelfde artiestachtergrondcache als album');
$fanartCacheBefore = $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll();
check($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart'] === $fanartEnriched['fanart']
    && $fanartCacheBefore === $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll()
    && !isset($musicRepo->previewMetadata($fanartSpot, 'music-reader', false)['fanart']),
    'Preview gebruikt uitsluitend verse fanartcache en respecteert afbeeldingen uit');
$fanartHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($fanartSpot, true, metadata: $fanartEnriched);
check(str_contains($fanartHtml, 'class="hero-backdrop"') && str_contains($fanartHtml, 'Artiest-fanart: fanart.tv')
    && str_contains($fanartHtml, $fanartEnriched['fanart']) && str_contains($fanartHtml, 'Cover Art Archive'),
    'Muziekpreview deelt artiestachtergrond en fanartattributie met detail, naast albumhoes');
check(!str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($fanartSpot, false, false, metadata: $fanartEnriched), 'assets.fanart.tv'),
    'Afbeeldingen uit publiceert ook bij verrijkte metadata geen fanartafbeeldings-URL');
$fanartAdminHtml = \NnSpotty\Music\View::settings($musicRepo, 'fixture-csrf');
$fanartPersonalHtml = \NnSpotty\Music\View::settings($musicRepo, 'fixture-csrf', 'music-reader');
check(!str_contains($fanartAdminHtml, $fanartKey) && !str_contains($fanartPersonalHtml, $fanartKey)
    && str_contains($fanartAdminHtml, 'type="password"') && !str_contains($fanartPersonalHtml, 'name="api_key"'),
    'Fanartkey wordt nooit teruggetoond; alleen admin heeft leeg passwordveld');
$musicRepo->saveFanartPreference('music-reader', false);
check(!isset($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart']), 'Persoonlijke fanart-opt-out verbergt ook cachehit');
$musicRepo->saveFanartPreference('music-reader', true);
$musicRepo->savePreference('music-reader', false);
check(!$musicRepo->fanartAllowed('music-reader'), 'Muziekopt-out blokkeert ook fanart');
$musicRepo->savePreference('music-reader', true);
foreach (['', '89ad4ac3-39f7-470e-963a-56509c546377'] as $ambiguousArtist) {
    check($fanartService->enrich(array_replace($fanartMetadata, ['artist_id' => $ambiguousArtist])) === array_replace($fanartMetadata, ['artist_id' => $ambiguousArtist])
        && $fanartFixture->calls === 1, 'Geen fanart voor onbekende/meerdere artiesten of Various Artists');
}
$musicRepo->cache($fanartCacheKey, ['url' => '', 'error' => false], -1);
check(!isset($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart']), 'Verlopen fanartcache vernieuwt niet vanuit preview');
$musicRepo->lock($fanartCacheKey);
try {
    $lockedFanart = (new \NnSpotty\Music\Fanart(new \NnSpotty\Music\Repository($otherMusicPdo), $fanartFixture))->enrich($fanartMetadata);
    check(isset($lockedFanart['fanart_warning']) && $fanartFixture->calls === 1,
        'Gelijktijdige fanartdiscovery geeft zichtbare bezetmelding en geen dubbel providerrequest');
} finally {
    $musicRepo->unlock($fanartCacheKey);
}
$fanartFixture->data = null;
check(!isset($fanartService->enrich($fanartMetadata)['fanart']) && $fanartFixture->calls === 2
    && $musicRepo->cached($fanartCacheKey) === ['url' => '', 'error' => false], 'Niet-bestaande fanart is negatief gecachet, geen fout');
$fanartExpiry->execute([hash('sha256', $fanartCacheKey)]);
$fanartRemaining = (int) $fanartExpiry->fetchColumn();
check($fanartRemaining > 86300 && $fanartRemaining <= 86400, 'Ontbrekend artwork blijft een dag negatief gecachet');
$fanartService->enrich($fanartMetadata);
check($fanartFixture->calls === 2, 'Negatieve fanartcache voorkomt herhaalde providerrequests');
$musicRepo->cache($fanartCacheKey, ['url' => '', 'error' => false], -1);
$fanartFixture->fail = true;
$failedFanart = $fanartService->enrich($fanartMetadata);
check(isset($failedFanart['fanart_warning']) && $failedFanart['title'] === $fanartMetadata['title']
    && str_contains(\NnSpotty\Music\View::message(['state' => 'matched', 'manual' => false, 'metadata' => $failedFanart]), 'vijf minuten')
    && str_contains(\NnSpotty\Music\View::summary($fanartSpot, $failedFanart, '', true, false), 'alert alert-warning'),
    'Fanartfout bewaart muziekmetadata met expliciete status en Bootstrap-waarschuwing');
$fanartService->enrich($fanartMetadata);
check($fanartFixture->calls === 3 && $musicRepo->cached($fanartCacheKey)['error'], 'Fanartproviderfout is gecachet zonder snelle retries');
$fanartExpiry->execute([hash('sha256', $fanartCacheKey)]);
check((int) $fanartExpiry->fetchColumn() <= 300, 'Fanartfoutcache duurt maximaal vijf minuten');
$musicRepo->saveFanartSettings(true, str_repeat('differentfixture', 2));
check(!isset($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart_warning']), 'Andere API-key hergebruikt geen oude autorisatiefoutcache');
$musicRepo->saveFanartSettings(false, '', true);
check($musicRepo->fanartSettings() === ['enabled' => false, 'api_key' => '']
    && !isset($musicRepo->previewMetadata($fanartSpot, 'music-reader')['fanart']), 'Key wissen en globaal uit blokkeren ook bestaand artwork');
check($fanartService->enrich($fanartEnriched) === $fanartMetadata && $fanartFixture->calls === 3,
    'Globaal fanart uit doet ook bij directe serviceaanroep geen providerrequest');
rejects(static fn () => (new \NnSpotty\Music\FanartClient($musicRepo))->get('../invalid'), 'Fanartclient weigert ongeldige artiest voor netwerkverkeer');
try {
    (new \NnSpotty\Music\FanartClient($musicRepo))->get(MusicFixture::ARTIST);
    throw new LogicException('Uitgeschakelde fanartclient heeft geen fout gegeven.');
} catch (RuntimeException) {
    check(true, 'Uitgeschakelde fanartclient geeft expliciete configuratiefout zonder netwerkverkeer');
}
$musicRepo->saveFanartPreference('music-reader', false);
$musicRepo->enable(false);
