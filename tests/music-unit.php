<?php

declare(strict_types=1);

use NnSpotty\Music\Release as MusicRelease;

$musicSpot = \NnSpotty\Usenet\SpotnetParser::spot('music-unit@example.invalid', Fixtures::headers('music-unit@example.invalid'));
$musicSpot['category'] = 'Geluid';
$musicSpot['subcategories'] = ['2z00'];
foreach (['Fixture Artist - Fixture Album (2020) FLAC', 'Fixture_Artist-Fixture_Album-2020-FLAC-GROUP',
    '01. Fixture.Artist - Fixture.Album 2020 MP3 320kbps', 'Fixture Artist - Fixture Album (2020) [24bit 96kHz] FLAC'] as $title) {
    $musicSpot['title'] = $title;
    $parsedMusic = MusicRelease::parse($musicSpot);
    check($parsedMusic !== null && $parsedMusic['artist'] === 'Fixture Artist' && $parsedMusic['title'] === 'Fixture Album'
        && $parsedMusic['year'] === '2020', 'Muziekparser herkent artiest/album/jaar en verwijdert technische suffix: ' . $title);
}
foreach (['Fixture Artist Discography', 'Fixture Artist - Discografie', 'Artiest: Fixture Artist'] as $title) {
    $musicSpot['title'] = $title;
    check(MusicRelease::parse($musicSpot)['type'] === 'artist'
        && MusicRelease::parse($musicSpot)['artist'] === 'Fixture Artist', 'Discografie/expliciete artiest wordt geen enkel album: ' . $title);
}
$musicSpot['title'] = 'Unknown title without artist';
check(!MusicRelease::parse($musicSpot)['auto'], 'Onduidelijke muziektitel blijft beschikbaar voor beheer maar wordt niet automatisch gegokt');
$musicSpot['title'] = 'Fixture Artist - Fixture Album 2020 FLAC';
$parsedMusic = MusicRelease::parse($musicSpot);
$musicCandidate = ['type' => 'release-group', 'id' => '22222222-2222-4222-8222-222222222222', 'artist' => 'Fixture Artist',
    'names' => ['Fixture Album'], 'year' => '2020'];
check(MusicRelease::select($parsedMusic, [$musicCandidate]) === $musicCandidate, 'Exacte unieke artiest/album/jaar match wordt gekozen');
foreach (['artist' => 'Other artist', 'year' => '2021', 'names' => ['Fixture Album Deluxe']] as $field => $value) {
    $wrongMusic = $musicCandidate;
    $wrongMusic[$field] = $value;
    check(MusicRelease::select($parsedMusic, [$wrongMusic]) === null, 'Afwijkend muziekveld wordt niet gegokt: ' . $field);
}
$otherMusic = $musicCandidate;
$otherMusic['id'] = '44444444-4444-4444-8444-444444444444';
check(MusicRelease::select($parsedMusic, [$musicCandidate, $otherMusic]) === null, 'Ambigue muziekidentiteit wordt niet automatisch gekozen');
$musicSpot['subcategories'] = ['2z03'];
check(MusicRelease::parse($musicSpot) === null, 'Luisterboeken worden niet als muziekalbum gekoppeld');
$musicSpot['category'] = 'Beeld';
check(MusicRelease::parse($musicSpot) === null, 'Filmspot wordt niet als muziek gekoppeld');
check(MusicRelease::mbid($musicCandidate['id']) && !MusicRelease::mbid('../etc/passwd'), 'MusicBrainz-identiteiten zijn begrensde UUIDs');
$musicSpot['category'] = 'Geluid';
$musicSpot['subcategories'] = ['2z00'];
foreach ([
    'Paul McCartney - The Boys of Dungeon Lane DeLuxe in DTS-HD-*HRA*. ( OV )' => ['Paul McCartney', 'The Boys of Dungeon Lane', null],
    'Simply Red - Holding Back The Years (Live In Santiago 2026)' => ['Simply Red', 'Holding Back The Years (Live In Santiago 2026)', null],
    'Spandau Ballet-True-LP-32BIT-WAVPACK-1983-REETKEVER' => ['Spandau Ballet', 'True', null],
    '1973 - Alice Cooper - Killer [USA Vinyl LP 24-192 kHz]' => ['Alice Cooper', 'Killer', null],
    'AC-DC - Back In Black (1980) FLAC' => ['AC-DC', 'Back In Black', '1980'],
    'Various Artists - Dance Hits (2020) MP3' => ['Various Artists', 'Dance Hits', '2020'],
    'Fixture Artist - Live (2020) FLAC' => ['Fixture Artist', 'Live', '2020'],
    'Taylor Swift - 1989 FLAC' => ['Taylor Swift', '1989', null],
    'Prince - 1999 FLAC' => ['Prince', '1999', null],
    'Prince-1999' => ['Prince', '1999', null],
    '1983 - Numeric Artist Album FLAC' => ['1983', 'Numeric Artist Album', null],
    'The 1975 - Being Funny In A Foreign Language FLAC' => ['The 1975', 'Being Funny In A Foreign Language', null],
    'Kansas - Vinyl Confessions (1982) FLAC' => ['Kansas', 'Vinyl Confessions', '1982'],
    'Fixture Artist - MP3' => ['Fixture Artist', 'MP3', null],
    'Fixture Artist - Album (DTS Live In London) FLAC' => ['Fixture Artist', 'Album (DTS Live In London)', null],
    'Beck - 2026 - Ride Lonesome (16-bit Flac MP3 Covers Lyrics )...' => ['Beck', 'Ride Lonesome', '2026'],
    'Fixture Artist - 1999 - Fixture Album FLAC' => ['Fixture Artist', 'Fixture Album', '1999'],
] as $musicTitle => [$expectedArtist, $expectedAlbum, $expectedYear]) {
    $musicSpot['title'] = $musicTitle;
    $musicParsed = MusicRelease::parse($musicSpot);
    check($musicParsed !== null && $musicParsed['artist'] === $expectedArtist && $musicParsed['title'] === $expectedAlbum
        && $musicParsed['year'] === $expectedYear && $musicParsed['auto'],
        'Uitgebreide muziektitel behoudt juiste identiteit: ' . $musicTitle);
}
$musicSpot['title'] = 'Spandau Ballet-True-LP-32BIT-WAVPACK-1983-REETKEVER';
check(MusicRelease::parse($musicSpot)['source_year'] === '1983', 'Scenejaar na technische suffix blijft als bronhint beschikbaar');
$musicSpot['title'] = 'Simply Red - Holding Back The Years (Live In Santiago 2026)';
$liveMusic = MusicRelease::parse($musicSpot);
$studioCandidate = $musicCandidate;
$studioCandidate['artist'] = 'Simply Red';
$studioCandidate['names'] = ['Holding Back The Years'];
check(MusicRelease::select($liveMusic, [$studioCandidate]) === null, 'Live-/locatie-/concertjaarclaim koppelt niet aan studioalbum of single');
$musicSpot['title'] = '1973 - Alice Cooper - Killer [USA Vinyl LP 24-192 kHz]';
$vinylCandidate = $musicCandidate;
$vinylCandidate['artist'] = 'Alice Cooper';
$vinylCandidate['names'] = ['Killer'];
$vinylCandidate['year'] = '1971';
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$vinylCandidate]) === $vinylCandidate,
    'Vinyl-/prefixjaar blokkeert unieke albumgroep met ander oorspronkelijk jaar niet');
foreach ([
    ['Paul McCartney - The Boys of Dungeon Lane DeLuxe in DTS-HD-*HRA*. ( OV )', 'Paul McCartney', 'The Boys of Dungeon Lane', '2026'],
    ['Spandau Ballet-True-LP-32BIT-WAVPACK-1983-REETKEVER', 'Spandau Ballet', 'True', '1983'],
    ['Prince-1999', 'Prince', '1999', '1982'],
] as [$spotTitle, $artist, $album, $year]) {
    $musicSpot['title'] = $spotTitle;
    $expectedMusic = array_replace($musicCandidate, ['artist' => $artist, 'names' => [$album], 'year' => $year]);
    check(MusicRelease::select(MusicRelease::parse($musicSpot), [$expectedMusic]) === $expectedMusic,
        'Opgeschoonde titel selecteert de unieke juiste albumgroep: ' . $spotTitle);
}
$liveCandidate = array_replace($studioCandidate, ['names' => [$liveMusic['title']], 'year' => '2026']);
check(MusicRelease::select($liveMusic, [$studioCandidate, $liveCandidate]) === $liveCandidate,
    'Volledige concertidentiteit kiest een exacte livekandidaat boven studiosuggestie');
$duplicateLive = array_replace($liveCandidate, ['id' => $otherMusic['id']]);
check(MusicRelease::select($liveMusic, [$liveCandidate, $duplicateLive]) === null,
    'Ook livekandidaten vereisen unieke identiteit');
$musicSpot['title'] = 'Beck - 2026 - Ride Lonesome (16-bit Flac MP3 Covers Lyrics )...';
$beckAlbum = ['type' => 'release-group', 'id' => 'cae645e3-675a-4a07-82e3-9336d70fd57d', 'artist' => 'Beck',
    'names' => ['Ride Lonesome'], 'year' => '2026', 'kind' => 'Album'];
$beckSingle = array_replace($beckAlbum, ['id' => 'cee132ee-4104-4584-8765-080b60037824', 'kind' => 'Single']);
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$beckSingle, $beckAlbum]) === $beckAlbum,
    'Gelijknamig album wint van single met dezelfde artiest/titel/jaar');
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$beckAlbum, array_replace($beckAlbum, ['id' => $otherMusic['id']])]) === null,
    'Twee gelijke albums blijven ambigu');
foreach ([
    'Hilary Duff-Holiday Things-EP-24BIT-WEB-FLAC-2026-ENRiCH' => ['Hilary Duff', 'Holiday Things', '2026', false, 'EP'],
    'Amy Winehouse-Back To Black-(20th Anniversary Deluxe Edition)-3CD-2026-C4' => ['Amy Winehouse', 'Back To Black', '2026', false, null],
    'Diverse Artiesten - 538 Dance Smash Hits 1997-vol.2 in DTS-HD-*HRA* ( OV )' => ['Various Artists', '538 Dance Smash Hits 1997-vol 2', null, true, null],
    'Miranda Lambert - Crisco (2026) [Hi-Res]' => ['Miranda Lambert', 'Crisco', '2026', true, null],
    'V.A. - Dance Hits (2020) MP3' => ['Various Artists', 'Dance Hits', '2020', true, null],
    'VA-Dance_Hits-2CD-2020-GROUP' => ['Various Artists', 'Dance Hits', '2020', false, null],
    'Verschillende Artiesten - Dance Hits FLAC' => ['Various Artists', 'Dance Hits', null, true, null],
    'Dance Hits - Various Artists (2020) FLAC' => ['Various Artists', 'Dance Hits', '2020', true, null],
    'Fixture Artist - Fixture Album (Remastered 2015) FLAC' => ['Fixture Artist', 'Fixture Album', null, false, null],
    'Fixture Artist - Fixture Album - Single (2020)' => ['Fixture Artist', 'Fixture Album', '2020', true, 'Single'],
    'Artist-Album-2026-GRP' => ['Artist', 'Album', '2026', false, null],
] as $musicTitle => [$expectedArtist, $expectedAlbum, $expectedYear, $expectedExact, $expectedKind]) {
    $musicSpot['title'] = $musicTitle;
    $musicParsed = MusicRelease::parse($musicSpot);
    check($musicParsed !== null && $musicParsed['auto'] && $musicParsed['artist'] === $expectedArtist
        && $musicParsed['title'] === $expectedAlbum && $musicParsed['year'] === $expectedYear
        && $musicParsed['year_exact'] === $expectedExact && $musicParsed['kind'] === $expectedKind,
        'Rommelige muziektitel wordt opgeschoond tot artiest/album/jaarhint/type: ' . $musicTitle);
}
$realMusic = static fn (string $id, string $artist, string $title, string $year, string $kind, array $artists = []): array => [
    'type' => 'release-group', 'id' => $id, 'artist' => $artist, 'artists' => $artists, 'names' => [$title], 'year' => $year, 'kind' => $kind];
$musicSpot['title'] = 'Hilary Duff-Holiday Things-EP-24BIT-WEB-FLAC-2026-ENRiCH';
$duffEp = $realMusic('adc5b48a-8302-4e6b-96b6-934981669203', 'Hilary Duff', 'Holiday Things', '2026', 'EP');
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$duffEp]) === $duffEp, 'Scene-EP koppelt aan MusicBrainz-EP');
$musicSpot['title'] = 'Amy Winehouse-Back To Black-(20th Anniversary Deluxe Edition)-3CD-2026-C4';
$amyAlbum = $realMusic('6eac2e57-ee50-36f8-b0c4-c4c847a2c098', 'Amy Winehouse', 'Back to Black', '2006', 'Album');
$amySingle = $realMusic('395c3c5d-a677-3c1f-ac1f-1beff237f62d', 'Amy Winehouse', 'Back to Black', '2007', 'Single');
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$amySingle, $amyAlbum]) === $amyAlbum,
    'Jubileumeditie uit 2026 koppelt aan oorspronkelijke albumgroep uit 2006, niet aan de single');
$amyFuture = array_replace($amyAlbum, ['year' => '2027']);
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$amyFuture]) === null,
    'Scene-/editiejaar accepteert geen albumgroep die pas later verscheen');
$musicSpot['title'] = 'Diverse Artiesten - 538 Dance Smash Hits 1997-vol.2 in DTS-HD-*HRA* ( OV )';
$smashVol2 = $realMusic('0a174484-7624-3fe3-a089-61fb0a9e4dc0', 'Various Artists', '538 Dance Smash Hits 1997, Volume 2', '1997', 'Album');
$smashVol1 = array_replace($smashVol2, ['id' => $otherMusic['id'], 'names' => ['538 Dance Smash Hits 1997, Volume 1']]);
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$smashVol1, $smashVol2]) === $smashVol2,
    'Verzamelalbum met Diverse Artiesten en vol.2 koppelt aan Various Artists "Volume 2", niet aan volume 1');
$musicSpot['title'] = 'Miranda Lambert - Crisco (2026) [Hi-Res]';
$criscoAlbum = $realMusic('658150db-909b-4c96-98e8-c10bdee302e0', 'Miranda Lambert', 'Crisco', '2026', 'Album');
$criscoSingle = $realMusic('b32986fd-83dd-43d7-bea9-6aae6b403014', 'Miranda Lambert', 'Crisco', '2026', 'Single');
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$criscoSingle, $criscoAlbum]) === $criscoAlbum,
    'Hi-Res-album wint van gelijknamige single');
$musicSpot['title'] = 'Miranda Lambert - Crisco - Single (2026)';
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$criscoAlbum, $criscoSingle]) === $criscoSingle,
    'Expliciete Single-hint kiest de single boven het gelijknamige album');
$musicSpot['title'] = 'Hillary Duff - Holiday Things (2026)';
$duffAlias = array_replace($duffEp, ['artists' => ['Hilary Duff', 'Hillary Duff']]);
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$duffAlias]) === $duffAlias
    && MusicRelease::select(MusicRelease::parse($musicSpot), [$duffEp]) === null,
    'Artiestalias uit MusicBrainz telt als artiestidentiteit, zonder alias geen gok');
$musicSpot['title'] = 'Various Artists - Top 40 Hits 1997 FLAC';
$top40 = $realMusic('55555555-5555-4555-8555-555555555555', 'Various Artists', 'Top 40 Hits 1997', '1998', 'Album');
check(MusicRelease::select(MusicRelease::parse($musicSpot), [$top40]) === $top40,
    'Jaartal dat deel is van de verzameltitel blokkeert de koppeling niet');
check(MusicRelease::various('V.A.') && MusicRelease::various('Diverse Artiesten') && MusicRelease::various('VA')
    && MusicRelease::various('Various') && !MusicRelease::various('Vanessa Amorosi'), 'Verzamelaliassen worden herkend zonder echte artiesten te raken');
$fanartUrl = 'https://assets.fanart.tv/fanart/music/11111111-1111-4111-8111-111111111111/artistbackground/fixture.jpg';
check(\NnSpotty\Music\Fanart::imageUrl(str_replace('https:', 'http:', $fanartUrl)) === $fanartUrl,
    'Fanart-afbeeldingen worden uitsluitend via HTTPS gepubliceerd');
foreach (['https://assets.fanart.tv.evil.invalid/fanart/a.jpg', 'https://user:secret@assets.fanart.tv/fanart/a.jpg',
    'https://assets.fanart.tv/fanart/../secret.jpg', 'javascript:alert(1)', $fanartUrl . '?api_key=secret', 'https://example.invalid/a.jpg', null] as $invalidArt) {
    check(\NnSpotty\Music\Fanart::imageUrl($invalidArt) === '', 'Fanart-afbeeldingsbron weigert externe hosts, geheimen en onveilige URLs');
}
$fanartArtist = '11111111-1111-4111-8111-111111111111';
check(\NnSpotty\Music\Fanart::select(['mbid_id' => $fanartArtist, 'artistbackground' => [
    ['url' => str_replace('fixture.jpg', 'low.jpg', $fanartUrl), 'likes' => '2'],
    ['url' => $fanartUrl, 'likes' => '12'],
]], $fanartArtist) === $fanartUrl, 'Artiestachtergrond met de meeste stemmen wordt deterministisch geselecteerd');
check(\NnSpotty\Music\Fanart::select(['mbid_id' => $fanartArtist], $fanartArtist) === '',
    'Geldige artiest zonder achtergronden is geen providerfout');
foreach ([
    ['mbid_id' => '22222222-2222-4222-8222-222222222222'],
    ['mbid_id' => $fanartArtist, 'artistbackground' => 'invalid'],
    ['mbid_id' => $fanartArtist, 'artistbackground' => [['url' => 'https://evil.invalid/a.jpg']]],
    ['mbid_id' => $fanartArtist, 'artistbackground' => [['url' => $fanartUrl, 'likes' => '-1']]],
] as $invalidFanart) {
    try {
        \NnSpotty\Music\Fanart::select($invalidFanart, $fanartArtist);
        throw new LogicException('Ongeldig fanart-providerrecord ten onrechte geaccepteerd.');
    } catch (RuntimeException) {
        check(true, 'Afwijkende artiestidentiteit of ongeldige fanartdata geeft expliciete fout');
    }
}
