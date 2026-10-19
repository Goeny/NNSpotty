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
