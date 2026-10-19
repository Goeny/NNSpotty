<?php

declare(strict_types=1);

$musicHttpRepo = new \NnSpotty\Music\Repository($pdo);
$musicPost = static function (array $form, bool $reader = false) use ($httpPort, &$cookie, &$readerCookie, $token, $readerToken): array {
    return $reader ? httpFixture($httpPort, '/music-metadata', $readerCookie, $form + ['_csrf' => $readerToken[1]])
        : httpFixture($httpPort, '/music-metadata', $cookie, $form + ['_csrf' => $token[1]]);
};
$anonymousMusicCookie = '';
check(httpFixture($httpPort, '/music-metadata', $anonymousMusicCookie, ['action' => 'load'])['status'] === 401
    && httpFixture($httpPort, '/music-metadata', $cookie, ['action' => 'load'])['status'] === 403,
    'Muziekmetadata vereist login en CSRF');
foreach (['settings', 'fanart-settings', 'search', 'choose', 'reset', 'block'] as $musicAction) {
    check($musicPost(['action' => $musicAction], true)['status'] === 403, 'Muziek-adminactie afgeschermd: ' . $musicAction);
}
check(str_contains(httpFixture($httpPort, '/admin', $cookie)['body'], 'data-admin-panel="music-metadata"')
    && str_contains(httpFixture($httpPort, '/preferences', $readerCookie)['body'], 'Muziekmetadata voor mij'),
    'Admin en voorkeuren bieden aparte muziekmetadatafunctie');
$musicPost(['action' => 'settings', 'enabled' => '1']);
$musicPost(['action' => 'preference', 'enabled' => '1', 'username' => 'http-reader']);
check($musicHttpRepo->allowed('http-tester') && !$musicHttpRepo->preference('http-reader'), 'Muziekvoorkeur is sessiegebonden, geen vervalste username');
$musicPost(['action' => 'preference', 'enabled' => '1'], true);
$musicPost(['action' => 'settings', 'enabled' => ['1']]);
check($musicHttpRepo->enabled(), 'Ongeldige muziekschakelaar wijzigt opgeslagen instelling niet');
$httpMusicSpot = \NnSpotty\Usenet\SpotnetParser::spot('music-http@example.invalid', Fixtures::headers('music-http@example.invalid'));
$httpMusicSpot['category'] = 'Geluid';
$httpMusicSpot['subcategories'] = ['2z00'];
$httpMusicSpot['title'] = 'Fixture Artist - Fixture Album (2020) FLAC';
$spots->saveSpot($httpMusicSpot, 'free.pt');
(new \NnSpotty\Favorites\Repository($pdo))->set('http-reader', $httpMusicSpot['id'], true);
$httpMusicService = new \NnSpotty\Music\Service($musicHttpRepo, new MusicFixture());
$musicPreviewPath = '/spots/preview?key=' . $httpMusicSpot['id'];
check(!str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], 'music-hero')
    && $musicHttpRepo->match($httpMusicSpot['id']) === null, 'Muziekpreview cachemiss doet geen discovery');
$httpMusicService->lookup($httpMusicSpot);
$musicDetail = httpFixture($httpPort, '/spots/view?key=' . $httpMusicSpot['id'], $cookie);
check(str_contains($musicDetail['body'], 'MusicBrainz link') && str_contains($musicDetail['body'], 'data-choice-id="mbid"')
    && str_contains($musicDetail['body'], 'data-tvdb-choice') && !str_contains($musicDetail['body'], 'TVDB link'),
    'Muziekdetail biedt gedeeld toegankelijke drawer met eigen provider/endpoint/MBID en adminbeheer');
$musicLoaded = $musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true);
$musicData = json_decode($musicLoaded['body'], true, 64, JSON_THROW_ON_ERROR);
check(str_contains($musicData['html'], 'data-favorite="' . $httpMusicSpot['id'] . '" aria-pressed="true"'),
    'Asynchrone muziekmetadata bewaart persoonlijke geselecteerde favorietknop');
check($musicLoaded['status'] === 200 && $musicData['state'] === 'matched' && str_contains($musicData['html'], 'music-hero')
    && str_contains($musicData['html'], 'Metadata van MusicBrainz') && str_contains($musicData['html'], 'MusicBrainz link')
    && in_array('Cache-Control: private, no-store', $musicLoaded['headers'], true), 'HTTP-muziekdetail verrijkt veilig met attributie en heropenbare link');
$musicMatchBefore = $musicHttpRepo->match($httpMusicSpot['id']);
$musicCacheBefore = $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll();
check(str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], 'music-hero')
    && $musicMatchBefore === $musicHttpRepo->match($httpMusicSpot['id'])
    && $musicCacheBefore === $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll(),
    'Muziekpreview hergebruikt cache zonder requestbudget/providerdata of vervaldatum te wijzigen');
$musicSearch = json_decode($musicPost(['action' => 'search', 'id' => $httpMusicSpot['id'], 'query' => 'Fixture Artist - Fixture Album', 'type' => 'release-group'])['body'], true);
check(count($musicSearch['candidates']) === 1 && $musicSearch['candidates'][0]['id'] === MusicFixture::GROUP
    && !isset($musicSearch['candidates'][0]['names']), 'HTTP-muziekzoekactie geeft publieke kandidaatvelden met MBID');
$httpMusicService->choose($httpMusicSpot, ['type' => 'release', 'id' => MusicFixture::RELEASE]);
$musicChosen = $musicPost(['action' => 'choose', 'id' => $httpMusicSpot['id'], 'type' => 'release', 'mbid' => MusicFixture::RELEASE]);
check($musicChosen['status'] === 200 && str_contains(json_decode($musicChosen['body'], true)['html'], 'Tracklist (2)'),
    'Admin koppelt via HTTP specifieke muziekuitgave met tracklist');
$musicPost(['action' => 'block', 'id' => $httpMusicSpot['id']]);
check(!str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], 'music-hero')
    && json_decode($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true)['body'], true)['state'] === 'blocked',
    'Gedeelde muziekblokkering geldt voor detail en preview van andere gebruikers');
check($musicPost(['action' => 'reset', 'id' => $httpMusicSpot['id']])['status'] === 200, 'Admin herstelt automatische muziekmatch via HTTP');
check($musicPost(['action' => 'load', 'id' => ['bad']])['status'] === 400
    && $musicPost(['action' => 'choose', 'id' => $httpMusicSpot['id'], 'type' => 'release', 'mbid' => '../invalid'])['status'] === 400
    && $musicPost(['action' => 'load', 'id' => str_repeat('0', 64)])['status'] === 404
    && $musicPost(['action' => 'nonsense', 'id' => $httpMusicSpot['id']])['status'] === 400, 'Ongeldige muziekacties/IDs expliciet geweigerd');
$musicContentBefore = $state->spotContentSettings();
$state->saveSpotContentSettings(['images_enabled' => false, 'show_comments' => true, 'retrieve_comments' => true]);
$musicNoArt = httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'];
check(str_contains($musicNoArt, 'music-hero') && !str_contains($musicNoArt, 'https://coverartarchive.org'),
    'HTTP-muziekpreview respecteert afbeeldingsblokkade');
$state->saveSpotContentSettings($musicContentBefore);
$httpFanartKey = str_repeat('httpfixturefanart', 2);
check($musicPost(['action' => 'fanart-settings', 'enabled' => '1', 'api_key' => $httpFanartKey])['status'] === 302,
    'Admin slaat fanartkey en globale toestemming op via bestaande CSRF-route');
$musicPost(['action' => 'fanart-preference', 'enabled' => '1', 'username' => 'http-reader']);
check($musicHttpRepo->fanartPreference('http-tester') && !$musicHttpRepo->fanartPreference('http-reader'),
    'Fanartvoorkeur gebruikt sessieusername, niet vervalst formulierveld');
$musicPost(['action' => 'fanart-preference', 'enabled' => '1'], true);
$httpFanartCacheKey = \NnSpotty\Music\Fanart::cacheKey(MusicFixture::ARTIST, $httpFanartKey);
$httpFanartUrl = 'https://assets.fanart.tv/fanart/music/fixture/artistbackground/http-fixture.jpg';
$musicHttpRepo->cache($httpFanartCacheKey, ['url' => $httpFanartUrl, 'error' => false], 604800);
$httpFanartMatch = $musicHttpRepo->match($httpMusicSpot['id']);
$httpFanartCache = $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll();
$httpFanartLoad = json_decode($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true)['body'], true);
check(str_contains($httpFanartLoad['html'], $httpFanartUrl) && str_contains($httpFanartLoad['html'], 'Artiest-fanart: fanart.tv')
    && str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], $httpFanartUrl)
    && $httpFanartMatch === $musicHttpRepo->match($httpMusicSpot['id'])
    && $httpFanartCache === $pdo->query('SELECT * FROM music_cache ORDER BY cache_key')->fetchAll(),
    'Echte HTTP-detail en preview gebruiken dezelfde fanartcache zonder muziekcache of expiry te muteren');
$httpAdminFanart = httpFixture($httpPort, '/admin', $cookie)['body'];
check(str_contains($httpAdminFanart, 'name="api_key"') && !str_contains($httpAdminFanart, $httpFanartKey)
    && !str_contains(httpFixture($httpPort, '/preferences', $readerCookie)['body'], $httpFanartKey)
    && !str_contains($httpFanartLoad['html'], $httpFanartKey), 'Fanartkey lekt niet naar admin, gebruiker of detail-HTML');
$musicPost(['action' => 'fanart-settings', 'enabled' => ['1'], 'api_key' => 'invalid']);
check($musicHttpRepo->fanartSettings()['api_key'] === $httpFanartKey, 'Ongeldige fanartinstelling wijzigt bestaand geheim niet');
$musicPost(['action' => 'fanart-preference'], true);
check(!str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], $httpFanartUrl)
    && str_contains(httpFixture($httpPort, $musicPreviewPath, $cookie)['body'], $httpFanartUrl),
    'Persoonlijke fanart-opt-out verbergt artwork alleen voor die gebruiker');
$musicPost(['action' => 'fanart-preference', 'enabled' => '1'], true);
$state->saveSpotContentSettings(['images_enabled' => false, 'show_comments' => true, 'retrieve_comments' => true]);
$httpNoFanart = json_decode($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true)['body'], true);
check(!str_contains($httpNoFanart['html'], $httpFanartUrl)
    && !str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], $httpFanartUrl),
    'Globale afbeeldingen uit onderdrukt fanart in echte HTTP-detail en preview');
$state->saveSpotContentSettings($musicContentBefore);
$musicHttpRepo->cache($httpFanartCacheKey, ['url' => '', 'error' => true], 300);
$httpFanartError = json_decode($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true)['body'], true);
check($httpFanartError['state'] === 'matched' && str_contains($httpFanartError['html'], 'alert alert-warning')
    && str_contains($httpFanartError['message'], 'vijf minuten'), 'HTTP-fanartfout blijft expliciete waarschuwing bij bruikbare muziekmetadata');
$musicPost(['action' => 'fanart-settings', 'clear_key' => '1']);
check($musicHttpRepo->fanartSettings() === ['enabled' => false, 'api_key' => '']
    && !str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], 'Artiest-fanart: fanart.tv'),
    'Admin kan fanart uitschakelen en key wissen zonder muziekmetadata te verliezen');
$musicHttpRepo->saveFanartPreference('http-tester', false);
$musicHttpRepo->saveFanartPreference('http-reader', false);
$musicPost(['action' => 'preference'], true);
check($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']], true)['status'] === 403
    && !str_contains(httpFixture($httpPort, $musicPreviewPath, $readerCookie)['body'], 'music-hero')
    && str_contains(httpFixture($httpPort, $musicPreviewPath, $cookie)['body'], 'music-hero'), 'Persoonlijke muziekopt-out blokkeert ook cachehits, niet andere gebruikers');
$musicPost(['action' => 'settings']);
check($musicPost(['action' => 'load', 'id' => $httpMusicSpot['id']])['status'] === 403
    && !str_contains(httpFixture($httpPort, $musicPreviewPath, $cookie)['body'], 'music-hero'), 'Globale muziekblokkade geldt ook voor admins en previewcache');
check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action LIKE 'music.%'")->fetchColumn() >= 6,
    'Muziekinstellingen en matchbeheer geaudit');
$pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$httpMusicSpot['id']]);
$musicHttpRepo->savePreference('http-tester', false);
$musicHttpRepo->savePreference('http-reader', false);
