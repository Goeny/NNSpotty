<?php

declare(strict_types=1);

use NnSpotty\Metadata\Release;
use NnSpotty\Metadata\Service;

foreach (['tvdb-dark.png', 'tvdb-light.png'] as $logo) {
    $size = getimagesize(__DIR__ . '/../public/assets/images/' . $logo);
    check($size !== false && $size['mime'] === 'image/png' && $size[0] === 400 && $size[1] === 216,
        'Aangeleverd TVDB-logo ongewijzigd beschikbaar: ' . $logo);
}
$filmSpot = $spot;
$filmSpot['category'] = 'Beeld';
$filmSpot['subcategories'] = ['1z00'];
foreach ([
    'Fixture.Movie.2020.1080p.WEB-DL.x264-GROUP' => ['movie', 'Fixture Movie', 2020, null, null, false],
    'Fixture.Show.2017.S03E02.1080p.WEB-DL' => ['series', 'Fixture Show', 2017, 3, 2, false],
    'Fixture_Show_2017_S03E02_1080p_WEB-DL' => ['series', 'Fixture Show', 2017, 3, 2, false],
    'Fixture.Show.S03E02E03.1080p' => ['series', 'Fixture Show', null, 3, null, true],
    'Fixture.Show.S03E02-04.1080p' => ['series', 'Fixture Show', null, 3, null, true],
    'Fixture.Show.S03E02 & E03.1080p' => ['series', 'Fixture Show', null, 3, null, true],
    'Fixture.Show.S03.Complete.1080p' => ['series', 'Fixture Show', null, 3, null, false],
    'Fixture.Show.2x03.720p' => ['series', 'Fixture Show', null, 2, 3, false],
    '1917.2019.1080p' => ['movie', '1917', 2019, null, null, false],
    'Blade.Runner.2049.2017.1080p' => ['movie', 'Blade Runner 2049', 2017, null, null, false],
    '2001.A.Space.Odyssey.1968.1080p' => ['movie', '2001 A Space Odyssey', 1968, null, null, false],
] as $title => $expected) {
    $filmSpot['title'] = $title;
    $parsed = Release::parse($filmSpot);
    check(array_values($parsed) === $expected, 'TVDB-releaseherkenning: ' . $title);
}
$filmSpot['subcategories'] = ['1z03'];
check(Release::parse($filmSpot) === null, 'Erotica wordt niet naar TVDB gestuurd');
$candidates = [
    ['id' => 1, 'type' => 'movie', 'name' => 'Fixture Movie', 'names' => ['Fixture Movie', 'Voorbeeldfilm'], 'year' => '2020'],
    ['id' => 2, 'type' => 'movie', 'name' => 'Fixture Movie', 'names' => ['Fixture Movie'], 'year' => '1980'],
];
$release = ['type' => 'movie', 'query' => 'Fixture Movie', 'year' => 2020];
check(Release::select($release, $candidates)['id'] === 1, 'TVDB-film vereist exact jaar en genormaliseerde titel');
$release['query'] = 'Voorbeeldfilm';
check(Release::select($release, $candidates)['id'] === 1, 'TVDB-alias/vertaling kan overtuigend overeenkomen');
$release['year'] = null;
check(Release::select($release, $candidates) === null, 'Geen automatische filmkeuze zonder jaar');
$release = ['type' => 'series', 'query' => 'Fixture Show', 'year' => null];
$seriesCandidates = [['id' => 10, 'type' => 'series', 'names' => ['Fixture Show'], 'year' => '2017'],
    ['id' => 11, 'type' => 'series', 'names' => ['Fixture Show'], 'year' => '2020']];
check(Release::select($release, $seriesCandidates) === null, 'Gelijknamige series/remakes blijven ambigu');
$release['year'] = 2017;
check(Release::select($release, $seriesCandidates)['id'] === 10, 'Seriejaar onderscheidt remakes');
$release['query'] = 'Fixture Shows';
check(Release::select($release, $seriesCandidates) === null, 'Geen best-hit fallback bij afwijkende titel');
$nonLatinTitle = "\xE6\x9D\xB1\xE4\xBA\xAC";
check(Release::normalized($nonLatinTitle) !== Release::normalized("\xE5\xA4\xA7\xE9\x98\xAA"),
    'Niet-Latijnse titels vallen niet samen tot dezelfde lege match');
$release['query'] = '...';
check(Release::select($release, [['id' => 10, 'type' => 'series', 'names' => ['---'], 'year' => '2017']]) === null,
    'Lege genormaliseerde titels leveren nooit automatische matches');
foreach (['javascript:alert(1)', 'https://evil.invalid/image.jpg', 'https://artworks.thetvdb.com.evil.invalid/x',
    'https://user@artworks.thetvdb.com/x', 'https://artworks.thetvdb.com:444/x', '//artworks.thetvdb.com/x'] as $url) {
    check(Service::imageUrl($url) === '', 'TVDB-afbeeldingshost begrensd: ' . $url);
}
check(Service::imageUrl('/banners/posters/test.jpg') === 'https://artworks.thetvdb.com/banners/posters/test.jpg',
    'Relatieve TVDB-artworkpaden worden uitsluitend naar vaste HTTPS-host vertaald');
