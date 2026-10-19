<?php

declare(strict_types=1);

require_once __DIR__ . '/MusicFixture.php';
$musicRepo = new \NnSpotty\Music\Repository($pdo);
$musicUsers = new \NnSpotty\Users\UserRepository($pdo);
$musicUsers->create('music-reader', 'synthetic-password', false);
check(!$musicRepo->enabled() && !$musicRepo->preference('music-reader'), 'Muziekmetadata standaard globaal en persoonlijk uit');
$musicRepo->savePreference('music-reader', true);
check(!$musicRepo->allowed('music-reader'), 'Persoonlijke muziekkeuze omzeilt globale blokkade niet');
$musicRepo->enable(true);
check($musicRepo->allowed('music-reader'), 'Muziekmetadata vereist globale en persoonlijke toestemming');
$musicDbSpot = \NnSpotty\Usenet\SpotnetParser::spot('music-db@example.invalid', Fixtures::headers('music-db@example.invalid'));
$musicDbSpot['category'] = 'Geluid';
$musicDbSpot['subcategories'] = ['2z00'];
$musicDbSpot['title'] = 'Fixture Artist - Fixture Album (2020) FLAC';
$legacyMusicInput = hash('sha256', json_encode([$musicDbSpot['title'], $musicDbSpot['category'], $musicDbSpot['subcategories'], 'music-v1'], JSON_THROW_ON_ERROR));
$spots->saveSpot($musicDbSpot, 'free.pt');
$musicApi = new MusicFixture();
$musicService = new \NnSpotty\Music\Service($musicRepo, $musicApi);
check($musicRepo->previewMetadata($musicDbSpot, 'music-reader') === null, 'Muziekpreview zonder cache haalt niets op');
$musicResult = $musicService->lookup($musicDbSpot);
check($musicResult['state'] === 'matched' && $musicResult['metadata']['type'] === 'release-group'
    && $musicResult['metadata']['artist'] === 'Fixture Artist' && $musicResult['metadata']['tracks'] === [],
    'Uniek album wordt albumgroep, niet willekeurige editie/tracklist');
$musicCalls = count($musicApi->calls);
check($musicCalls === 2 && $musicApi->calls[0] === ['release-group', null, [
    'query' => 'artist:"Fixture Artist" AND releasegroup:"Fixture Album"', 'limit' => 25,
]], 'Albumdiscovery gebruikt exact een gerichte artiest-EN-albumquery en een detailrequest');
check($musicService->lookup($musicDbSpot) === $musicResult && count($musicApi->calls) === $musicCalls,
    'Muziekmatch blijft in database zonder herhaalde providerrequests');
check($musicRepo->previewMetadata($musicDbSpot, 'music-reader') === $musicResult['metadata'],
    'Muziekpreview hergebruikt verse gedeelde databasecache');
$pdo->prepare('UPDATE music_matches SET input_hash = ?, result_json = ? WHERE spot_id = ?')->execute([
    $legacyMusicInput, '{"state":"unmatched"}', $musicDbSpot['id'],
]);
check($musicRepo->previewMetadata($musicDbSpot, 'music-reader') === null
    && $musicService->lookup($musicDbSpot)['state'] === 'matched'
    && $musicRepo->match($musicDbSpot['id'])['input_hash'] === \NnSpotty\Music\Release::matchSignature($musicDbSpot),
    'Oude negatieve parsercache wordt bij detail opnieuw beoordeeld zonder previewfetch of databaseflush');
foreach ([
    ['Paul McCartney - The Boys of Dungeon Lane DeLuxe in DTS-HD-*HRA*. ( OV )', 'artist:"Paul McCartney" AND releasegroup:"The Boys of Dungeon Lane"'],
    ['Simply Red - Holding Back The Years (Live In Santiago 2026)', 'artist:"Simply Red" AND releasegroup:"Holding Back The Years"'],
    ['Spandau Ballet-True-LP-32BIT-WAVPACK-1983-REETKEVER', 'artist:"Spandau Ballet" AND releasegroup:"True"'],
    ['1973 - Alice Cooper - Killer [USA Vinyl LP 24-192 kHz]', 'artist:"Alice Cooper" AND releasegroup:"Killer"'],
    ['AC-DC - Test "One": Love & War?', 'artist:"AC\\-DC" AND releasegroup:"Test \\"One\\"\\: Love \\& War\\?"'],
    ['Fixture + Artist - Back\\Slash / Album', 'artist:"Fixture \\+ Artist" AND releasegroup:"Back\\\\Slash \\/ Album"'],
] as [$sceneTitle, $expectedQuery]) {
    $beforeSearch = count($musicApi->calls);
    $musicService->search($sceneTitle, 'release-group');
    check(count($musicApi->calls) === $beforeSearch + 1 && $musicApi->calls[$beforeSearch] === [
        'release-group', null, ['query' => $expectedQuery, 'limit' => 25],
    ], 'Zoekquery is gericht, letterlijk ontsnapt en begrensd tot een request: ' . $sceneTitle);
    $musicService->search($sceneTitle, 'release-group');
    check(count($musicApi->calls) === $beforeSearch + 1, 'Identieke scenezoekactie hergebruikt providercache');
}
$beforeEditionSearch = count($musicApi->calls);
$musicService->search('Fixture Artist - Fixture Album (Deluxe Edition)', 'release');
check($musicApi->calls[$beforeEditionSearch] === ['release', null, [
    'query' => 'Fixture Artist - Fixture Album (Deluxe Edition)', 'dismax' => 'true', 'limit' => 25,
]], 'Handmatig zoeken naar een specifieke uitgave behoudt editiezoektermen');
$musicHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($musicDbSpot, true, metadata: $musicResult['metadata']);
check(str_contains($musicHtml, 'music-hero') && str_contains($musicHtml, 'Metadata van MusicBrainz')
    && str_contains($musicHtml, 'Hoezen: Cover Art Archive') && str_contains($musicHtml, '<h2>Fixture Album</h2>')
    && str_contains($musicHtml, '&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($musicHtml, '<script>unsafe'),
    'Albumpreview heeft eigen styling, vierkante hoesbron, veilige tekst en attributie');
$musicService->choose($musicDbSpot, ['type' => 'release', 'id' => MusicFixture::RELEASE]);
$musicEdition = $musicService->lookup($musicDbSpot);
check($musicEdition['manual'] && $musicEdition['metadata']['type'] === 'release'
    && count($musicEdition['metadata']['tracks']) === 2 && $musicEdition['metadata']['duration'] === 305000
    && $musicEdition['metadata']['labels'] === ['Fixture Label'], 'Handmatig gevalideerde uitgave toont juiste tracklist, speelduur en label');
$pdo->prepare('UPDATE music_matches SET input_hash = ?, result_json = ?, choice_json = ? WHERE spot_id = ?')->execute([
    $legacyMusicInput, '{"state":"unmatched"}',
    json_encode(['type' => 'release', 'id' => MusicFixture::RELEASE, 'input' => $legacyMusicInput], JSON_THROW_ON_ERROR), $musicDbSpot['id'],
]);
$upgradedMusicEdition = $musicService->lookup($musicDbSpot);
check($upgradedMusicEdition['manual'] && $upgradedMusicEdition['metadata']['id'] === MusicFixture::RELEASE,
    'Nieuwe discoveryversie behoudt bestaande music-v1-handmatige editie, niet opnieuw automatisch matchen');
$musicEditionHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($musicDbSpot, false, metadata: $musicEdition['metadata'], metadataLink: true);
check(str_contains($musicEditionHtml, 'Tracklist (2)') && str_contains($musicEditionHtml, '5:05')
    && str_contains($musicEditionHtml, 'Track &lt;Two&gt;') && str_contains($musicEditionHtml, 'MusicBrainz link')
    && !str_contains($musicEditionHtml, 'TVDB link'), 'Muziekdetail behoudt beheerpaneellink en toont veilige tracklist');
check(!str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($musicDbSpot, false, false, metadata: $musicEdition['metadata']),
    'https://coverartarchive.org'), 'Afbeeldingen uit onderdrukt muziekhoezen en bijbehorende requests');
$pdo->exec('DELETE FROM music_cache');
$musicApi->unknownLength = true;
$musicService->choose($musicDbSpot, ['type' => 'release', 'id' => MusicFixture::RELEASE]);
check($musicService->lookup($musicDbSpot)['metadata']['duration'] === null, 'Ontbrekende tracklengte levert geen fictieve totale speelduur');
$pdo->exec('DELETE FROM music_cache');
$musicApi->unknownLength = false;
$musicApi->trackCount = 205;
$musicService->choose($musicDbSpot, ['type' => 'release', 'id' => MusicFixture::RELEASE]);
$largeMusic = $musicService->lookup($musicDbSpot);
check(count($largeMusic['metadata']['tracks']) === 200 && $largeMusic['metadata']['track_count'] === 205
    && $largeMusic['metadata']['duration'] === null
    && str_contains(\NnSpotty\Music\View::summary($musicDbSpot, $largeMusic['metadata'], '', true, false), 'Maximaal 200 tracks'),
    'Grote muziekuitgave toont exact maximaal 200 tracks met expliciete afkapmelding en geen onvolledige totaaltijd');
$pdo->exec('DELETE FROM music_cache');
$musicApi->trackCount = 2;
$musicService->choose($musicDbSpot, ['type' => 'release', 'id' => MusicFixture::RELEASE]);
$musicService->lookup($musicDbSpot);
$oldMusicMatch = $musicRepo->match($musicDbSpot['id']);
rejects(static fn () => $musicService->choose($musicDbSpot, ['type' => 'release', 'id' => '../invalid']), 'Ongeldige MBID-keuze geweigerd');
try {
    $musicService->choose($musicDbSpot, ['type' => 'release', 'id' => '55555555-5555-4555-8555-555555555555']);
    throw new LogicException('Niet-bestaande muziekkeuze ten onrechte opgeslagen.');
} catch (RuntimeException) {
    check(true, 'Niet-bestaande handmatige muziekkeuze geweigerd');
}
check($musicRepo->match($musicDbSpot['id']) === $oldMusicMatch, 'Ongeldige keuze behoudt vorige muziekkoppeling');
$musicService->choose($musicDbSpot, ['blocked' => true]);
$pdo->prepare('UPDATE music_matches SET input_hash = ?, result_json = ?, choice_json = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE spot_id = ?')->execute([
    $legacyMusicInput, json_encode($musicResult, JSON_THROW_ON_ERROR),
    json_encode(['blocked' => true, 'input' => $legacyMusicInput], JSON_THROW_ON_ERROR), $musicDbSpot['id'],
]);
check($musicService->lookup($musicDbSpot)['state'] === 'blocked' && $musicRepo->previewMetadata($musicDbSpot, 'music-reader') === null,
    'Bestaande music-v1-muziekblokkering blijft bij nieuwe discovery gelden voor detail en preview');
$musicDbSpot['title'] = 'Fixture Artist - Fixture Album 2020 MP3';
check($musicService->lookup($musicDbSpot)['state'] === 'matched'
    && $musicRepo->previewMetadata($musicDbSpot, 'music-reader') !== null, 'Gewijzigde broninput invalideert oude muziekblokkering ook in preview');
$pdo->prepare('UPDATE music_matches SET expires_at = NULL WHERE spot_id = ?')->execute([$musicDbSpot['id']]);
check($musicService->lookup($musicDbSpot)['state'] === 'matched', 'Oude blokkering herleeft niet na cachevernieuwing met gewijzigde input');
$musicService->choose($musicDbSpot, null);
check($musicService->lookup($musicDbSpot)['state'] === 'matched', 'Muziekreset herstelt automatische koppeling');
$pdo->prepare('UPDATE music_matches SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE spot_id = ?')->execute([$musicDbSpot['id']]);
check($musicRepo->previewMetadata($musicDbSpot, 'music-reader') === null, 'Verlopen muziekcache vernieuwt niet vanuit preview');
$musicService->lookup($musicDbSpot);
$musicDbSpot['title'] = 'Fixture Artist Discography';
$spots->saveSpot($musicDbSpot, 'free.pt', true);
$musicArtist = $musicService->lookup($musicDbSpot);
$musicArtistHtml = \NnSpotty\UI\SpotDetailPageRenderer::summary($musicDbSpot, false, metadata: $musicArtist['metadata']);
check($musicArtist['metadata']['type'] === 'artist' && $musicArtist['metadata']['cover'] === ''
    && str_contains($musicArtistHtml, 'music-artist-hero') && !str_contains($musicArtistHtml, 'https://coverartarchive.org')
    && str_contains($musicArtistHtml, 'geen volledige discografie'), 'Discografie krijgt artiestweergave zonder fictief album, foto of volledige discografieclaim');
$musicDbSpot['title'] = 'Unknown artist - Unknown album';
$musicService->choose($musicDbSpot, null);
$musicApi->ambiguous = true;
check($musicService->lookup($musicDbSpot)['state'] !== 'matched', 'Afwijkende/ambigue muziektitels blijven oorspronkelijk');
$musicDbSpot['title'] = 'Provider Failure - No Match';
$musicApi->fail = true;
check($musicService->lookup($musicDbSpot)['state'] === 'error', 'Muziekproviderfout is expliciete gecachte fout, geen lege match');
$musicService->choose($musicDbSpot, null);
$musicRepo->lock('spot:' . $musicDbSpot['id']);
$otherMusicPdo = new PDO($dsn, getenv('NNSPOTTY_TEST_USER') ?: 'root', getenv('NNSPOTTY_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$otherMusicPdo->exec('USE `' . $database . '`');
try {
    check((new \NnSpotty\Music\Service(new \NnSpotty\Music\Repository($otherMusicPdo), new MusicFixture()))->lookup($musicDbSpot)['state'] === 'busy',
        'Gelijktijdige muzieklookup doet geen dubbele providerrequests');
} finally {
    $musicRepo->unlock('spot:' . $musicDbSpot['id']);
}
$musicRepo->claimRequest();
$musicTimestamp = $pdo->query('SELECT last_request_at FROM music_settings WHERE id = 1')->fetchColumn();
(new \NnSpotty\Music\Repository($otherMusicPdo))->claimRequest();
$musicPacing = $pdo->prepare('SELECT TIMESTAMPDIFF(MICROSECOND, ?, last_request_at) FROM music_settings WHERE id = 1');
$musicPacing->execute([$musicTimestamp]);
check((int) $musicPacing->fetchColumn() >= 1100000, 'MusicBrainz-requeststarts liggen ook over databaseverbindingen minstens 1,1 seconde uiteen');
$musicRepo->lock('request-pacing');
try {
    try {
        (new \NnSpotty\Music\Repository($otherMusicPdo))->claimRequest();
        throw new LogicException('Bezet aanvraagbudget ten onrechte toegekend.');
    } catch (RuntimeException) {
        check(true, 'Bezet aanvraagbudget is zichtbare fout, geen parallelle request');
    }
} finally {
    $musicRepo->unlock('request-pacing');
}
foreach (['https://example.invalid/?secret=hidden', "https://example.invalid/\r\nX-Hack: yes"] as $badContact) {
    try {
        (new \NnSpotty\Music\Client($musicRepo, $badContact))->get('artist', MusicFixture::ARTIST);
        throw new LogicException('Ongeldige User-Agent-contact-URL ten onrechte geaccepteerd.');
    } catch (RuntimeException) {
        check(true, 'User-Agent weigert geheimdragende of ongeldige APP_URL voor netwerkverkeer');
    }
}
$musicRepo->enable(false);
$musicRepo->cache('expired-cleanup', ['test' => true], -1);
$musicRepo->cache('fresh-cleanup', ['test' => true], 86400);
$musicLastRequest = $pdo->query('SELECT last_request_at FROM music_settings WHERE id = 1')->fetchColumn();
(new \NnSpotty\Database\Migrator($pdo))->cleanup(90);
$musicExpiredQuery = $pdo->prepare('SELECT COUNT(*) FROM music_cache WHERE cache_key = ?');
$musicExpiredQuery->execute([hash('sha256', 'expired-cleanup')]);
check($musicRepo->cached('fresh-cleanup') === ['test' => true]
    && (int) $musicExpiredQuery->fetchColumn() === 0
    && $pdo->query('SELECT last_request_at FROM music_settings WHERE id = 1')->fetchColumn() === $musicLastRequest,
    'Databasecleanup bewaart verse muziekcache en persistente aanvraaglimiet');
$musicUsers->delete('music-reader');
check(!$musicRepo->preference('music-reader'), 'Userverwijdering verwijdert muziekvoorkeur via foreign key');
$pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$musicDbSpot['id']]);
check($musicRepo->match($musicDbSpot['id']) === null, 'Spotverwijdering verwijdert muziekmatch via foreign key');
$pdo->exec('DELETE FROM music_cache');
