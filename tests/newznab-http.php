<?php

declare(strict_types=1);

use NnSpotty\Newznab\ApiKeys;
use NnSpotty\Usenet\SpotNzbService;
use NnSpotty\Usenet\SpotnetParser;

$apiCookie = '';
$disabledApi = httpFixture($httpPort, '/api?t=caps', $apiCookie);
check(!$state->apiEnabled() && $disabledApi['status'] === 503 && str_contains($disabledApi['body'], 'code="203"'), 'Newznab staat standaard uit met expliciete protocolfout');
check(httpFixture($httpPort, '/admin/newznab', $readerCookie, ['_csrf' => $readerToken[1], 'enabled' => '1'])['status'] === 403
    && httpFixture($httpPort, '/admin/newznab', $cookie, ['enabled' => '1'])['status'] === 403
    && !$state->apiEnabled(), 'Alleen admin met CSRF mag API inschakelen');
httpFixture($httpPort, '/admin/newznab', $cookie, ['_csrf' => $token[1], 'enabled' => ['1']]);
check(!$state->apiEnabled(), 'Array-invoer kan API niet inschakelen');
httpFixture($httpPort, '/admin/newznab', $cookie, ['_csrf' => $token[1], 'enabled' => '1']);
check($state->apiEnabled(), 'Admin kan API globaal inschakelen');
$invalidSetting = $pdo->prepare('UPDATE app_settings SET `value` = ? WHERE `key` = ?');
$invalidSetting->execute(['{"enabled":"false"}', 'admin.newznab']);
$corruptApi = httpFixture($httpPort, '/api?t=caps', $apiCookie);
check($corruptApi['status'] === 500 && str_contains($corruptApi['body'], 'code="900"'), 'Beschadigde opgeslagen API-boolean geeft expliciete fout, geen truthy inschakeling');
$state->setApiEnabled(true);
$publicCaps = httpFixture($httpPort, '/api?t=caps', $apiCookie);
$capsXml = simplexml_load_string($publicCaps['body']);
check($publicCaps['status'] === 200 && $capsXml !== false && $capsXml->getName() === 'caps'
    && (string) $capsXml->searching->{'tv-search'}['supportedParams'] === 'q,season,ep'
    && (string) $capsXml->limits['max'] === '100'
    && !str_contains($publicCaps['body'], 'id="6000"') && !str_contains($publicCaps['body'], 'tvdbid'),
    'Publieke caps adverteert standaardcategorieen en uitsluitend werkelijk ondersteunde titelzoekparameters');
check($apiCookie === '' && count(array_filter($publicCaps['headers'], static fn (string $h): bool => stripos($h, 'Set-Cookie:') === 0)) === 0,
    'API start geen browsersessie en vereist geen cookies');
check(httpFixture($httpPort, '/api?t=search', $apiCookie)['status'] === 200
    && str_contains(httpFixture($httpPort, '/api?t=search', $apiCookie)['body'], 'code="100"'),
    'Zoeken zonder key geeft Newznab authenticatiefout, geen HTML-loginredirect');
check(httpFixture($httpPort, '/preferences/api-key', $cookie, ['action' => 'rotate'])['status'] === 403, 'API-key maken vereist CSRF');
$createdKey = httpFixture($httpPort, '/preferences/api-key', $cookie, ['_csrf' => $token[1], 'action' => 'rotate']);
$preferencesWithKey = httpFixture($httpPort, '/preferences', $cookie);
preg_match('/id="new-api-key" value="([a-f0-9]{64})"/', $preferencesWithKey['body'], $keyMatch);
check($createdKey['status'] === 302 && isset($keyMatch[1])
    && !str_contains(implode("\n", $createdKey['headers']), 'apikey=')
    && in_array('Cache-Control: private, no-store', $preferencesWithKey['headers'], true),
    'Persoonlijke key verschijnt eenmalig in niet-cachebare voorkeuren, nooit in redirect-URL');
$apiKey = $keyMatch[1];
check(!str_contains(httpFixture($httpPort, '/preferences', $cookie)['body'], $apiKey), 'Herhaalde voorkeurpagina toont geheime key niet opnieuw');
$keyRepository = new ApiKeys($pdo);
$storedHash = $pdo->query("SELECT key_hash FROM user_api_keys WHERE username = 'http-tester'")->fetchColumn();
check($storedHash === hash('sha256', $apiKey) && $keyRepository->authenticate($apiKey) === 'http-tester'
    && $keyRepository->authenticate(str_repeat('0', 64)) === null, 'Alleen SHA256 van willekeurige persoonlijke key staat in database');
httpFixture($httpPort, '/preferences/api-key', $readerCookie, ['_csrf' => $readerToken[1], 'action' => 'rotate', 'username' => 'http-tester']);
$readerPreferences = httpFixture($httpPort, '/preferences', $readerCookie);
preg_match('/id="new-api-key" value="([a-f0-9]{64})"/', $readerPreferences['body'], $readerKeyMatch);
check(isset($readerKeyMatch[1]) && $keyRepository->authenticate($readerKeyMatch[1]) === 'http-reader'
    && $keyRepository->authenticate($apiKey) === 'http-tester', 'Gewone gebruiker kan eigen key beheren maar niet via username een ander account wijzigen');

$apiIds = [];
$createApiSpot = static function (string $name, string $title, string $category, string $codes, string $nzbSegment = 'nzb@example.invalid') use ($spots, &$apiIds): array {
    $xml = str_replace('<Sub>1a01</Sub>', $codes, Fixtures::xml($title, $category));
    $xml = str_replace('nzb@example.invalid', $nzbSegment, $xml);
    $id = $name . '@api-fixture.invalid';
    $spot = SpotnetParser::spot($id, Fixtures::headers($id, $xml));
    $spots->saveSpot($spot, 'free.pt');
    $apiIds[] = $spot['id'];
    return $spot;
};
$apiFilm = $createApiSpot('film', 'ApiFixture.Movie.2026.1080p.WEB-DL', '1', '<Sub>1a09</Sub><Sub>1z00</Sub>');
$apiTv = $createApiSpot('tv', 'ApiFixture.Show.S01E02.1080p.WEB-DL', '1', '<Sub>1a09</Sub><Sub>1z01</Sub>');
$apiTvTen = $createApiSpot('tv-ten', 'ApiFixture.Show.S01E10.1080p.WEB-DL', '1', '<Sub>1a09</Sub><Sub>1z01</Sub>');
$apiPack = $createApiSpot('pack', 'ApiFixture.Show.S01.Complete', '1', '<Sub>1a09</Sub><Sub>1z01</Sub>');
$apiAudio = $createApiSpot('audio', 'ApiFixture Album MP3', '2', '<Sub>2a00</Sub>');
$apiAdult = $createApiSpot('adult', 'ApiFixture Adult', '1', '<Sub>1a09</Sub><Sub>1d75</Sub>');
$apiEmpty = $createApiSpot('empty', 'ApiFixture Empty', '1', '<Sub>1a09</Sub><Sub>1z01</Sub>');
$pdo->prepare("UPDATE spotnet_spots SET nzb_segments = '[]' WHERE id = ?")->execute([$apiEmpty['id']]);
$apiExpired = $createApiSpot('expired', 'Unavailable NZB', '1', '<Sub>1a09</Sub>', 'missing-nzb@example.invalid');
$apiBroken = $createApiSpot('broken', 'Broken NZB', '1', '<Sub>1a09</Sub>', 'broken-nzb@example.invalid');
$pdo->exec('DELETE FROM spotnet_newznab_categories');
$pdo->exec("DELETE FROM migrations WHERE name = '20261018_0011_newznab_nzb'");
$migrator->migrate();
$migrator->migrate();
check((int) $pdo->query('SELECT COUNT(*) FROM spotnet_newznab_categories')->fetchColumn() > 0
    && $keyRepository->authenticate($apiKey) === 'http-tester', 'Newznab-migratie backfillt bestaande spots herhaalbaar zonder persoonlijke keys te wissen');
$apiCall = static function (array $parameters) use ($httpPort, &$apiCookie, &$apiKey): array {
    return httpFixture($httpPort, '/api?' . http_build_query($parameters + ['apikey' => $apiKey]), $apiCookie);
};
$searchResponse = $apiCall(['t' => 'search', 'q' => 'ApiFixture']);
$feed = simplexml_load_string($searchResponse['body']);
$ns = 'http://www.newznab.com/DTD/2010/feeds/attributes/';
check($feed !== false && count($feed->channel->item) === 5
    && (int) $feed->channel->children($ns)->response->attributes()['total'] === 5, 'RSS bevat uitsluitend zichtbare downloadbare spots en correcte total');
$item = $feed->channel->item[0];
check((string) $item->guid['isPermaLink'] === 'false'
    && preg_match('/^[a-f0-9]{64}$/D', (string) $item->guid) === 1
    && (string) $item->enclosure['type'] === 'application/x-nzb'
    && str_contains((string) $item->enclosure['url'], '/api?t=get')
    && str_contains((string) $item->enclosure['url'], $apiKey)
    && (string) $item->link === (string) $item->enclosure['url'], 'RSS gebruikt stabiele GUIDs, geauthenticeerde downloadlinks en NZB-enclosures');
$attributes = [];
foreach ($item->children($ns)->attr as $attribute) {
    $attributes[(string) $attribute->attributes()['name']][] = (string) $attribute->attributes()['value'];
}
check(isset($attributes['category'], $attributes['size']) && (int) $attributes['size'][0] === 4294967296
    && strtotime((string) $item->pubDate) !== false, 'RSS bevat echte releasegrootte, categoriecodes en publicatiedatum');
check(in_array('Cache-Control: private, no-store', $searchResponse['headers'], true)
    && in_array('Referrer-Policy: no-referrer', $searchResponse['headers'], true), 'Feeds met keys worden niet gedeeld gecachet of als referrer gebruikt');
$firstPage = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'ApiFixture', 'limit' => '2'])['body']);
$secondPage = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'ApiFixture', 'limit' => '2', 'offset' => '2'])['body']);
check(count($firstPage->channel->item) === 2 && count($secondPage->channel->item) === 2
    && (int) $secondPage->channel->children($ns)->response->attributes()['offset'] === 2
    && (string) $firstPage->channel->item[0]->guid !== (string) $secondPage->channel->item[0]->guid, 'Offset/limit en stabiele datum/ID-sortering leveren afzonderlijke paginaas');
foreach (['2000' => 1, '2040' => 1, '5000' => 3, '5040' => 3, '3000' => 1, '3010' => 1, '2000,5000' => 4, '6000' => 0] as $cats => $expected) {
    $filtered = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'ApiFixture', 'cat' => (string) $cats])['body']);
    check(count($filtered->channel->item) === $expected, 'Newznab categorie-OR en hoofdgroep/subgroep werken: ' . $cats);
}
$episode = simplexml_load_string($apiCall(['t' => 'tvsearch', 'q' => 'ApiFixture Show', 'season' => '1', 'ep' => '2'])['body']);
check(count($episode->channel->item) === 1 && (string) $episode->channel->item->guid === $apiTv['id'], 'Titelzoektocht matcht dotted releases en S01E02 zonder E10 of seizoenspakket te verwarren');
$season = simplexml_load_string($apiCall(['t' => 'tvsearch', 'q' => 'ApiFixture Show', 'season' => '1'])['body']);
check(count($season->channel->item) === 3, 'Seizoenszoektocht bevat afleveringen en seizoenspakket');
$movie = simplexml_load_string($apiCall(['t' => 'movie', 'q' => 'ApiFixture Movie', 'year' => '2026'])['body']);
check(count($movie->channel->item) === 1 && (string) $movie->channel->item->guid === $apiFilm['id'], 'Movie-zoektocht gebruikt titel en jaar, niet onbewezen metadata-IDs');
$empty = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'ThisWillNeverMatch'])['body']);
check(count($empty->channel->item) === 0 && (int) $empty->channel->children($ns)->response->attributes()['total'] === 0, 'Geen zoekresultaten is geldige lege RSS, geen fout of dummyresultaat');
foreach ([
    ['t' => 'tvsearch', 'tvdbid' => '123'],
    ['t' => 'movie', 'imdbid' => '123'],
    ['t' => 'register'],
] as $unsupportedQuery) {
    check(str_contains($apiCall($unsupportedQuery)['body'], 'code="203"'), 'Niet beschikbare functie of ID-zoektocht faalt expliciet');
}
foreach ([
    ['q' => ['array']], ['cat' => '105000'], ['limit' => '101'], ['offset' => '-1'],
    ['o' => 'json'], ['q' => str_repeat('a', 201)], ['q' => '%'], ['season' => '1'], ['t' => 'tvsearch', 'ep' => '2'],
] as $invalidQuery) {
    check(str_contains($apiCall($invalidQuery + ['t' => 'search'])['body'], 'code="201"'), 'Ongeldige Newznab-parameter wordt geweigerd');
}
$download = $apiCall(['t' => 'get', 'id' => $apiTv['id']]);
check($download['status'] === 200 && $download['body'] === Fixtures::nzb()
    && in_array('Content-Type: application/x-nzb', $download['headers'], true), 'API haalt echte deflate-NZB via fake NNTP op met correcte MIME');
check(httpFixture($httpPort, '/spots/nzb?key=' . $apiTv['id'], $apiCookie)['status'] === 401
    && httpFixture($httpPort, '/spots/nzb?key=' . $apiTv['id'], $cookie)['body'] === Fixtures::nzb(),
    'Browserdownload vereist sessie, deelt dezelfde NZB-service en werkt onafhankelijk van API-key');
$nzbSelect = $pdo->prepare('SELECT nzb_data FROM spotnet_nzbs WHERE spot_id = ?');
$nzbSelect->execute([$apiTv['id']]);
check($nzbSelect->fetchColumn() === Fixtures::nzb(), 'Geldige NZB wordt in database gecachet');
$settingsBefore = $state->getUsenetSettings();
$invalidSettings = $settingsBefore;
$invalidSettings['server'] = '';
$state->saveUsenetSettings($invalidSettings);
check($apiCall(['t' => 'get', 'id' => $apiTv['id']])['body'] === Fixtures::nzb(), 'NZB-cache wordt zonder nieuwe providerverbinding hergebruikt');
$state->saveUsenetSettings($settingsBefore);
$multipartSpot = $apiTv;
$multipartSpot['nzb_segments'] = ['nzb-first@example.invalid', 'nzb-second@example.invalid'];
$multipartSpot['xml'] = str_replace('<Segment>nzb@example.invalid</Segment>',
    '<Segment>nzb-first@example.invalid</Segment><Segment>nzb-second@example.invalid</Segment>', $multipartSpot['xml']);
$spots->saveSpot($multipartSpot, 'free.pt', true);
$nzbSelect->execute([$apiTv['id']]);
check($nzbSelect->fetchColumn() === false, 'Expliciete spotrefresh verwijdert NZB-cache bij gewijzigde segmentverwijzingen');
check($apiCall(['t' => 'get', 'id' => $apiTv['id']])['body'] === Fixtures::nzb(), 'NZB-service combineert meerdere NNTP-bodies voor unescape/inflate, ook bij gesplitste escape-code');
$spots->saveSpot($apiTv, 'free.pt');
$nzbSelect->execute([$apiTv['id']]);
check($nzbSelect->fetchColumn() === Fixtures::nzb()
    && $spots->getSpot($apiTv['id'])['nzb_segments'] === $multipartSpot['nzb_segments'], 'Normale duplicaatindexing bewaart bestaande NZB-verwijzingen en cache');
foreach ([$apiExpired, $apiBroken] as $failedSpot) {
    $failure = $apiCall(['t' => 'get', 'id' => $failedSpot['id']]);
    $nzbSelect->execute([$failedSpot['id']]);
    check($failure['status'] === 502 && str_contains($failure['body'], 'code="300"') && $nzbSelect->fetchColumn() === false,
        'Verlopen of defecte NZB geeft expliciete protocolfout en wordt niet gecachet');
}
check(str_contains($apiCall(['t' => 'get', 'id' => $apiEmpty['id']])['body'], 'code="300"')
    && str_contains($apiCall(['t' => 'get', 'id' => $apiAdult['id']])['body'], 'code="300"')
    && httpFixture($httpPort, '/spots/nzb?key=' . $apiAdult['id'], $cookie)['status'] === 404,
    'Ontbrekende en verborgen NZBs lekken geen data via API of browserroute');
$state->setEroticaAllowed(true);
$prefs = $state->getPreferences('http-tester');
$prefs['show_erotica'] = true;
$state->savePreferences('http-tester', $prefs);
check(str_contains($apiCall(['t' => 'caps'])['body'], 'id="6000"')
    && $apiCall(['t' => 'get', 'id' => $apiAdult['id']])['body'] === Fixtures::nzb(),
    'Globale toestemming plus persoonlijke opt-in ontsluit XXX-caps en NZB');
$readerApiAdult = httpFixture($httpPort, '/api?' . http_build_query(['t' => 'get', 'id' => $apiAdult['id'], 'apikey' => $readerKeyMatch[1]]), $apiCookie);
check(str_contains($readerApiAdult['body'], 'code="300"'), 'Persoonlijke API-key volgt eigen zichtbaarheid, niet browsersessie of andere gebruiker');
$state->setEroticaAllowed(false);
check(str_contains($apiCall(['t' => 'get', 'id' => $apiAdult['id']])['body'], 'code="300"'), 'Globale blokkade weigert ook reeds gecachte NZB met persoonlijke opt-in');
$escapingSpot = $createApiSpot('xml', 'XmlFixture &amp; &quot;Quote&quot; &lt;tag&gt;', '1', '<Sub>1a09</Sub>');
$xmlResult = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'XmlFixture'])['body']);
check($xmlResult !== false && (string) $xmlResult->channel->item->title === 'XmlFixture & "Quote" <tag>', 'Titels met XML-tekens blijven geldige RSS zonder markupinjectie of gegevensverlies');
$pdo->prepare('UPDATE spotnet_spots SET posted_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE id = ?')->execute([$escapingSpot['id']]);
$recent = simplexml_load_string($apiCall(['t' => 'search', 'q' => 'XmlFixture', 'maxage' => '1'])['body']);
check(count($recent->channel->item) === 0, 'maxage filtert op echte UTC-publicatiedatum');
$headContext = stream_context_create(['http' => ['method' => 'HEAD', 'ignore_errors' => true]]);
$headBody = file_get_contents('http://127.0.0.1:' . $httpPort . '/api?t=caps', false, $headContext);
check($headBody === '' && str_contains(implode("\n", $http_response_header), '200 OK'), 'API HEAD levert headers zonder XML-body');
$postApi = httpFixture($httpPort, '/api?t=caps', $apiCookie, []);
check($postApi['status'] === 405 && str_contains($postApi['body'], 'code="203"')
    && in_array('Allow: GET, HEAD', $postApi['headers'], true), 'POST op read-only API geeft protocolfout, HTTP 405 en toegestane methodes');
$pdo->exec("UPDATE user_api_keys SET request_count = 119, window_start = UTC_TIMESTAMP() WHERE username = 'http-tester'");
check(str_contains($apiCall(['t' => 'search'])['body'], '<rss'), 'Aanvraag 120 binnen het venster is nog toegestaan');
$limited = $apiCall(['t' => 'search']);
check($limited['status'] === 429 && str_contains($limited['body'], 'code="500"') && in_array('Retry-After: 60', $limited['headers'], true), '120 aanvragen per minuut begrenst ook NZB-API met protocolfout en retry-header');
$pdo->exec("UPDATE user_api_keys SET window_start = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE username = 'http-tester'");
check(str_contains($apiCall(['t' => 'search'])['body'], '<rss'), 'Aanvraaglimiet herstelt na het tijdvenster');
$oldKey = $apiKey;
httpFixture($httpPort, '/preferences/api-key', $cookie, ['_csrf' => $token[1], 'action' => 'rotate']);
$rotatedPreferences = httpFixture($httpPort, '/preferences', $cookie);
preg_match('/id="new-api-key" value="([a-f0-9]{64})"/', $rotatedPreferences['body'], $rotatedMatch);
$apiKey = $rotatedMatch[1];
check($apiKey !== $oldKey && $keyRepository->authenticate($oldKey) === null
    && $keyRepository->authenticate($apiKey) === 'http-tester', 'Roteren maakt oude key en downloadlinks direct ongeldig');
$state->setApiEnabled(false);
check($apiCall(['t' => 'get', 'id' => $apiTv['id']])['status'] === 503
    && httpFixture($httpPort, '/spots/nzb?key=' . $apiTv['id'], $cookie)['status'] === 200, 'API uit blokkeert keys maar niet de ingelogde browserdownload');
httpFixture($httpPort, '/preferences/api-key', $cookie, ['_csrf' => $token[1], 'action' => 'revoke']);
check(!$keyRepository->exists('http-tester') && $keyRepository->authenticate($apiKey) === null, 'Intrekken blijft mogelijk als admin API heeft uitgezet');
check(!str_contains(json_encode($pdo->query('SELECT details_json FROM admin_audit_logs')->fetchAll(), JSON_THROW_ON_ERROR), $oldKey)
    && !str_contains(json_encode($pdo->query('SELECT details_json FROM admin_audit_logs')->fetchAll(), JSON_THROW_ON_ERROR), $apiKey),
    'Auditlog bewaart keyacties zonder geheime waarden');
$keyRepository->revoke('http-reader');
$apiUsers = new \NnSpotty\Users\UserRepository($pdo);
$apiUsers->create('temporary-api-user', 'fixture-password', false);
$deletedUserKey = $keyRepository->rotate('temporary-api-user');
$apiUsers->delete('temporary-api-user');
check($keyRepository->authenticate($deletedUserKey) === null, 'Gebruiker verwijderen verwijdert persoonlijke API-key via foreign key');
foreach ($apiIds as $apiId) {
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$apiId]);
}
check((int) $pdo->query('SELECT COUNT(*) FROM spotnet_nzbs')->fetchColumn() === 0, 'Spotverwijdering ruimt gekoppelde NZB-cache automatisch op');
