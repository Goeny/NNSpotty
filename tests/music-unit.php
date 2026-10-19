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
