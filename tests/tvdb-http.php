<?php

declare(strict_types=1);

$tvdbRepo = new \NnSpotty\Metadata\Repository($pdo);
$tvdbPost = static function (array $form, bool $reader = false) use ($httpPort, &$cookie, &$readerCookie, $token, $readerToken): array {
    if ($reader) return httpFixture($httpPort, '/metadata', $readerCookie, $form + ['_csrf' => $readerToken[1]]);
    return httpFixture($httpPort, '/metadata', $cookie, $form + ['_csrf' => $token[1]]);
};
$anonymousCookie = '';
check(httpFixture($httpPort, '/metadata', $anonymousCookie, ['action' => 'load'])['status'] === 401
    && httpFixture($httpPort, '/metadata', $cookie, ['action' => 'preference'])['status'] === 403,
    'TVDB-routes vereisen login en POST-CSRF');
foreach (['settings', 'test', 'search', 'choose', 'reset', 'block'] as $action) {
    check($tvdbPost(['action' => $action], true)['status'] === 403, 'TVDB-adminactie afgeschermd: ' . $action);
}
$tvdbPost(['action' => 'settings', 'enabled' => '1', 'api_key' => 'fixture-http-tvdb-key', 'language' => 'nld']);
check($tvdbRepo->settings()['enabled'] && !$tvdbRepo->preference('http-tester')
    && $tvdbPost(['action' => 'load', 'id' => $spotId])['status'] === 403, 'Globale toegang geeft niet automatisch persoonlijke toestemming');
$tvdbPost(['action' => 'preference', 'enabled' => '1', 'username' => 'http-reader']);
check($tvdbRepo->allowed('http-tester') && !$tvdbRepo->preference('http-reader'), 'TVDB-persoonlijke toestemming gebruikt sessie, niet vervalste username');
$tvdbPost(['action' => 'preference', 'enabled' => '1'], true);
check($tvdbRepo->allowed('http-reader'), 'Gebruiker kan eigen metadatatoestemming opslaan');
$tvdbPost(['action' => 'preference', 'enabled' => ['1']], true);
check($tvdbRepo->allowed('http-reader'), 'Array-invoer wijzigt opgeslagen metadatavoorkeur niet');
$httpMetaSpot = \NnSpotty\Usenet\SpotnetParser::spot('http-meta@example.invalid', Fixtures::headers('http-meta@example.invalid'));
$httpMetaSpot['title'] = 'Fixture.Movie.2020.1080p';
$httpMetaSpot['subcategories'] = ['1z00'];
$spots->saveSpot($httpMetaSpot, 'free.pt');
(new \NnSpotty\Favorites\Repository($pdo))->set('http-reader', $httpMetaSpot['id'], true);
$httpMetaApi = new TvdbFixture();
$httpMetaApi->searchResults = [TvdbFixture::candidate()];
$httpMetadata = new \NnSpotty\Metadata\Service($tvdbRepo, $httpMetaApi);
check(!str_contains(httpFixture($httpPort, '/spots/preview?key=' . $httpMetaSpot['id'], $readerCookie)['body'], 'metadata-hero')
    && $tvdbRepo->match($httpMetaSpot['id']) === null, 'Preview zonder match blijft oorspronkelijk en start geen TVDB-lookup');
check($httpMetadata->lookup($httpMetaSpot)['state'] === 'matched', 'HTTP-TVDB-fixture vooraf gecachet zonder extern netwerk');
$previewPath = '/spots/preview?key=' . $httpMetaSpot['id'];
$previewBefore = $tvdbRepo->match($httpMetaSpot['id']);
$previewCacheBefore = $pdo->query('SELECT * FROM tvdb_cache ORDER BY cache_key')->fetchAll();
$previewResponse = httpFixture($httpPort, $previewPath, $readerCookie);
$previewHtml = json_decode($previewResponse['body'], true, 64, JSON_THROW_ON_ERROR)['html'];
check($previewResponse['status'] === 200 && str_contains($previewHtml, 'metadata-hero')
    && str_contains($previewHtml, 'Voorbeeldtitel') && str_contains($previewHtml, 'Cast &amp; Crew')
    && str_contains($previewHtml, 'https://artworks.thetvdb.com') && str_contains($previewHtml, 'metadata-attribution')
    && str_contains($previewHtml, 'Oorspronkelijke spottitel:') && str_contains($previewHtml, 'Volledige spot en reacties')
    && str_contains($previewHtml, '<h2>Voorbeeldtitel</h2>') && !str_contains($previewHtml, '<h1>')
    && !str_contains($previewHtml, 'data-tvdb-open') && !str_contains($previewHtml, 'data-tvdb-load')
    && in_array('Cache-Control: private, no-store', $previewResponse['headers'], true),
    'Preview hergebruikt gedeelde verse metadata en artwork met attributie, originele acties en zonder TVDB-ophaalcontrols');
check($tvdbRepo->match($httpMetaSpot['id']) === $previewBefore
    && $pdo->query('SELECT * FROM tvdb_cache ORDER BY cache_key')->fetchAll() === $previewCacheBefore,
    'Previewcachehit schrijft geen match/providerdata, aanvraagbudget of vervaldatum');
$previewMatch = $tvdbRepo->match($httpMetaSpot['id']);
$pdo->prepare('UPDATE tvdb_matches SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE spot_id = ?')->execute([$httpMetaSpot['id']]);
check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero')
    && !$tvdbRepo->match($httpMetaSpot['id'])['fresh'], 'Verlopen previewcache valt terug zonder netwerkvernieuwing');
foreach (['unmatched', 'ambiguous', 'blocked', 'error', 'busy'] as $previewState) {
    $tvdbRepo->result($httpMetaSpot['id'], $previewMatch['input_hash'], ['state' => $previewState], 300);
    check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero'),
        'Preview toont geen metadata voor cachestatus ' . $previewState);
}
$tvdbRepo->result($httpMetaSpot['id'], 'outdated-input', $previewMatch['result_json'], 604800);
check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero'),
    'Preview toont geen match voor gewijzigde spotinvoer of parser');
$tvdbRepo->result($httpMetaSpot['id'], $previewMatch['input_hash'], $previewMatch['result_json'], 604800);
$tvdbRepo->saveSettings(true, '', '', 'eng');
check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero'),
    'Preview toont geen metadata na taalwijziging');
$tvdbRepo->saveSettings(true, '', '', 'nld');
$tvdbRepo->result($httpMetaSpot['id'], $previewMatch['input_hash'], $previewMatch['result_json'], 604800);
$page = httpFixture($httpPort, '/spots/view?key=' . $httpMetaSpot['id'], $cookie);
$readerPage = httpFixture($httpPort, '/spots/view?key=' . $httpMetaSpot['id'], $readerCookie);
check(str_contains($page['body'], 'data-tvdb-choice') && str_contains($readerPage['body'], 'data-tvdb')
    && !str_contains($readerPage['body'], 'data-tvdb-choice'), 'Detail laadt on-demand metadata; alleen admin ziet matchbeheer');
check(str_contains($page['body'], 'data-tvdb-open') && str_contains($readerPage['body'], 'data-tvdb-open')
    && str_contains($page['body'], '<dialog id="metadata-drawer"'), 'Admin en gebruiker openen TVDB-status vanuit compacte hero-link');
$loaded = $tvdbPost(['action' => 'load', 'id' => $httpMetaSpot['id']], true);
$data = json_decode($loaded['body'], true, 64, JSON_THROW_ON_ERROR);
check(str_contains($data['html'], 'data-favorite="' . $httpMetaSpot['id'] . '" aria-pressed="true"'),
    'Asynchrone TVDB-metadata bewaart persoonlijke geselecteerde favorietknop');
check($loaded['status'] === 200 && $data['state'] === 'matched' && str_contains($data['html'], 'metadata-hero')
    && str_contains($data['html'], 'Cast &amp; Crew') && in_array('Cache-Control: private, no-store', $loaded['headers'], true),
    'HTTP-metadata levert veilige verrijkte hero en cast uit gedeelde databasecache');
check(str_contains($data['html'], 'data-tvdb-open') && str_contains($data['html'], 'metadata-attribution')
    && !str_contains($data['html'], '<dialog'), 'Asynchrone vervanging behoudt heropenbare link en attributie zonder paneel te dupliceren');
check(str_contains($data['html'], 'class="release-quality"') && str_contains($data['html'], '1080p')
    && str_contains($page['body'], 'class="release-quality"') && str_contains($data['html'], 'Uit de releasetitel;'),
    'HTTP-detail toont dezelfde bron-gelabelde releasekwaliteit voor en na asynchrone TVDB-verrijking');
check(!str_contains(httpFixture($httpPort, '/admin', $cookie)['body'], 'fixture-http-tvdb-key')
    && !str_contains(httpFixture($httpPort, '/preferences', $readerCookie)['body'], 'fixture-http-tvdb-key')
    && !str_contains($loaded['body'], 'fixture-http-tvdb-key'), 'TVDB-key lekt niet via beheer, voorkeuren of metadata-JSON');
$searched = json_decode($tvdbPost(['action' => 'search', 'id' => $httpMetaSpot['id'], 'query' => 'Fixture Movie', 'type' => 'movie'])['body'], true);
check(count($searched['candidates']) === 1 && array_keys($searched['candidates'][0]) === ['id', 'type', 'name', 'year'],
    'Handmatige zoekresultaten bevatten alleen benodigde publieke keuzevelden');
$chosen = $tvdbPost(['action' => 'choose', 'id' => $httpMetaSpot['id'], 'tvdb_id' => '1', 'type' => 'movie']);
check($chosen['status'] === 200 && $tvdbRepo->match($httpMetaSpot['id'])['result_json']['manual'], 'Admin kan gedeelde match via CSRF-route opslaan');
$tvdbPost(['action' => 'block', 'id' => $httpMetaSpot['id']]);
$blocked = json_decode($tvdbPost(['action' => 'load', 'id' => $httpMetaSpot['id']], true)['body'], true);
check($blocked['state'] === 'blocked' && !str_contains($blocked['html'], 'metadata-hero'), 'Gedeelde blokkering trekt metadata voor andere gebruikers in');
check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero'),
    'Gedeelde blokkering trekt ook previewmetadata in');
check(str_contains($blocked['html'], 'data-tvdb-open') && !str_contains($blocked['html'], 'metadata-attribution'),
    'Ook na blokkering blijft matchpaneel bereikbaar zonder onterechte attributie');
check($tvdbPost(['action' => 'reset', 'id' => $httpMetaSpot['id']])['status'] === 200
    && !$tvdbRepo->match($httpMetaSpot['id'])['result_json']['manual'], 'Reset herstelt automatische match via HTTP');
check($tvdbPost(['action' => 'load', 'id' => ['bad']])['status'] === 400
    && $tvdbPost(['action' => 'load', 'id' => str_repeat('0', 64)])['status'] === 404
    && $tvdbPost(['action' => 'unknown', 'id' => $httpMetaSpot['id']])['status'] === 400,
    'Ongeldig/onbekend metadata-ID en onbekende actie falen expliciet');
$httpMetaSpot['subcategories'] = ['1z03'];
$httpMetaSpot['id'] = hash('sha256', 'http-meta-adult');
$httpMetaSpot['message_id'] = 'http-meta-adult@example.invalid';
$spots->saveSpot($httpMetaSpot, 'free.pt');
check($tvdbPost(['action' => 'load', 'id' => $httpMetaSpot['id']], true)['status'] === 404, 'TVDB-route respecteert verborgen spots en erotica-beleid');
check(httpFixture($httpPort, '/spots/preview?key=' . $httpMetaSpot['id'], $readerCookie)['status'] === 404,
    'Previewmetadata omzeilt erotica-zichtbaarheid niet');
$contentBeforeMetadata = $state->spotContentSettings();
$state->saveSpotContentSettings(['images_enabled' => false, 'show_comments' => true, 'retrieve_comments' => true]);
$normalMetadataId = hash('sha256', 'http-meta@example.invalid');
$disabledArt = json_decode($tvdbPost(['action' => 'load', 'id' => $normalMetadataId], true)['body'], true);
check(!str_contains($disabledArt['html'], 'https://artworks.thetvdb.com') && !str_contains($disabledArt['html'], 'hero-backdrop'),
    'HTTP-metadata omzeilt globale afbeeldingsblokkade niet');
$previewWithoutArt = httpFixture($httpPort, $previewPath, $readerCookie)['body'];
check(str_contains($previewWithoutArt, 'metadata-hero') && !str_contains($previewWithoutArt, 'https://artworks.thetvdb.com')
    && !str_contains($previewWithoutArt, 'hero-backdrop'), 'Preview toont gecachte metadata zonder artwork bij afbeeldingsblokkade');
$state->saveSpotContentSettings($contentBeforeMetadata);
$tvdbPost(['action' => 'preference'], true);
check($tvdbPost(['action' => 'load', 'id' => $normalMetadataId], true)['status'] === 403
    && !str_contains(httpFixture($httpPort, '/spots/view?key=' . $normalMetadataId, $readerCookie)['body'], 'data-tvdb data-spot'),
    'Persoonlijke opt-out blokkeert endpoint en automatische detailverrijking ook bij cachehits');
check(!str_contains(httpFixture($httpPort, $previewPath, $readerCookie)['body'], 'metadata-hero')
    && str_contains(httpFixture($httpPort, $previewPath, $cookie)['body'], 'metadata-hero'),
    'Preview respecteert persoonlijke opt-out terwijl andere toegestane gebruiker dezelfde cache mag zien');
$tvdbPost(['action' => 'settings', 'language' => 'nld']);
check($tvdbPost(['action' => 'load', 'id' => $normalMetadataId])['status'] === 403, 'Globale uitschakeling blokkeert ook admin en gecachte metadata');
check(!str_contains(httpFixture($httpPort, $previewPath, $cookie)['body'], 'metadata-hero'),
    'Globale uitschakeling blokkeert ook gecachte previewmetadata voor admin');
$audit = $pdo->query("SELECT details_json FROM admin_audit_logs WHERE action LIKE 'tvdb.%'")->fetchAll();
check(count($audit) >= 6 && !str_contains(json_encode($audit, JSON_THROW_ON_ERROR), 'fixture-http-tvdb-key'), 'TVDB-beheer en matchcorrecties geaudit zonder geheimen');
$pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (?, ?)')->execute([$normalMetadataId, $httpMetaSpot['id']]);
$tvdbRepo->savePreference('http-tester', false);
