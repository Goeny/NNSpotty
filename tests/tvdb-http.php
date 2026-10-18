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
$httpMetaApi = new TvdbFixture();
$httpMetaApi->searchResults = [TvdbFixture::candidate()];
$httpMetadata = new \NnSpotty\Metadata\Service($tvdbRepo, $httpMetaApi);
check($httpMetadata->lookup($httpMetaSpot)['state'] === 'matched', 'HTTP-TVDB-fixture vooraf gecachet zonder extern netwerk');
$page = httpFixture($httpPort, '/spots/view?key=' . $httpMetaSpot['id'], $cookie);
$readerPage = httpFixture($httpPort, '/spots/view?key=' . $httpMetaSpot['id'], $readerCookie);
check(str_contains($page['body'], 'data-tvdb-choice') && str_contains($readerPage['body'], 'data-tvdb')
    && !str_contains($readerPage['body'], 'data-tvdb-choice'), 'Detail laadt on-demand metadata; alleen admin ziet matchbeheer');
$loaded = $tvdbPost(['action' => 'load', 'id' => $httpMetaSpot['id']], true);
$data = json_decode($loaded['body'], true, 64, JSON_THROW_ON_ERROR);
check($loaded['status'] === 200 && $data['state'] === 'matched' && str_contains($data['html'], 'metadata-hero')
    && str_contains($data['html'], 'Cast &amp; Crew') && in_array('Cache-Control: private, no-store', $loaded['headers'], true),
    'HTTP-metadata levert veilige verrijkte hero en cast uit gedeelde databasecache');
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
$contentBeforeMetadata = $state->spotContentSettings();
$state->saveSpotContentSettings(['images_enabled' => false, 'show_comments' => true, 'retrieve_comments' => true]);
$normalMetadataId = hash('sha256', 'http-meta@example.invalid');
$disabledArt = json_decode($tvdbPost(['action' => 'load', 'id' => $normalMetadataId], true)['body'], true);
check(!str_contains($disabledArt['html'], 'https://artworks.thetvdb.com') && !str_contains($disabledArt['html'], 'hero-backdrop'),
    'HTTP-metadata omzeilt globale afbeeldingsblokkade niet');
$state->saveSpotContentSettings($contentBeforeMetadata);
$tvdbPost(['action' => 'preference'], true);
check($tvdbPost(['action' => 'load', 'id' => $normalMetadataId], true)['status'] === 403
    && !str_contains(httpFixture($httpPort, '/spots/view?key=' . $normalMetadataId, $readerCookie)['body'], 'data-tvdb data-spot'),
    'Persoonlijke opt-out blokkeert endpoint en automatische detailverrijking ook bij cachehits');
$tvdbPost(['action' => 'settings', 'language' => 'nld']);
check($tvdbPost(['action' => 'load', 'id' => $normalMetadataId])['status'] === 403, 'Globale uitschakeling blokkeert ook admin en gecachte metadata');
$audit = $pdo->query("SELECT details_json FROM admin_audit_logs WHERE action LIKE 'tvdb.%'")->fetchAll();
check(count($audit) >= 6 && !str_contains(json_encode($audit, JSON_THROW_ON_ERROR), 'fixture-http-tvdb-key'), 'TVDB-beheer en matchcorrecties geaudit zonder geheimen');
$pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (?, ?)')->execute([$normalMetadataId, $httpMetaSpot['id']]);
$tvdbRepo->savePreference('http-tester', false);
