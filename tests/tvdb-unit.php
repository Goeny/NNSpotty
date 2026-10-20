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
    '03. The Dark Knight Rises (2012) IMAX 2160p AI-Enhanced, Rife 60fps Dolby True-HD' => ['movie', 'The Dark Knight Rises', 2012, null, null, false],
    'The Lord of the Rings Theatrical Version 4K Ultra HD Blu-ray TrueHD / ATMOS 7.1 (3 x BD100) - NL-RETAILSUB' => ['movie', 'The Lord of the Rings', null, null, null, true],
    'De.Bondgenoten.S04E01.DUTCH.1080p.WEB.h264-KIJKDOOS' => ['series', 'De Bondgenoten', null, 4, 1, false],
    '12.Monkeys.1995.1080p' => ['movie', '12 Monkeys', 1995, null, null, false],
    '03. 1917 (2019) 4K' => ['movie', '1917', 2019, null, null, false],
    'Fixture Movie Theatrical Version 4K Ultra HD Blu-ray' => ['movie', 'Fixture Movie', null, null, null, false],
    'Fixture Movie (2020) Collection 3 x BD100' => ['movie', 'Fixture Movie', 2020, null, null, true],
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
$release['multi'] = true;
check(Release::select($release, $candidates) === null, 'Filmcollecties worden ook met jaartal niet automatisch als een enkele film gekoppeld');
unset($release['multi']);
$filmSpot['subcategories'] = ['1z00'];
$filmSpot['title'] = '03. The Dark Knight Rises (2012) IMAX 2160p AI-Enhanced';
$darkKnight = Release::parse($filmSpot);
check(Release::select($darkKnight, [['id' => 155, 'type' => 'movie', 'names' => ['The Dark Knight Rises'], 'year' => '2012']])['id'] === 155,
    'Nummerprefix van gebruikersvoorbeeld verhindert exacte TVDB-match niet meer');
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
$quality = \NnSpotty\UI\SpotReleaseQuality::parse('03. The Dark Knight Rises (2012) IMAX 2160p AI-Enhanced, Rife 60fps Dolby True-HD');
check($quality === ['Video' => ['4K (2160p)', 'IMAX', 'AI-enhanced', 'RIFE', '60 fps'], 'Audio' => ['TrueHD']],
    'Dark Knight-release toont alleen expliciete resolutie, bewerking en audioformaat');
$quality = \NnSpotty\UI\SpotReleaseQuality::parse('The Lord of the Rings Theatrical Version 4K Ultra HD Blu-ray TrueHD / ATMOS 7.1 (3 x BD100) - NL-RETAILSUB');
check($quality === ['Video' => ['4K (2160p)', 'Blu-ray'], 'Audio' => ['TrueHD', 'Atmos', '7.1'], 'Ondertiteling' => ['Nederlands (retail)']],
    'LOTR-pakket toont 4K Blu-ray, Atmos 7.1 en NL-retailondertiteling zonder verzonnen taal of codec');
$quality = \NnSpotty\UI\SpotReleaseQuality::parse('De.Bondgenoten.S04E01.DUTCH.1080p.WEB.h264-KIJKDOOS');
check($quality === ['Video' => ['1080p', 'H.264', 'WEB'], 'Taal' => ['Nederlands']],
    'Bondgenoten-releasekwaliteit is los van TVDB-afleveringsbeschikbaarheid beschikbaar');
$quality = \NnSpotty\UI\SpotReleaseQuality::parse('Snowfall.2017.S03.1080p.DSNP.WEB-DL.DD5.1.H.264-playWEB.mkv');
check($quality['Audio'] === ['Dolby Digital', '5.1'] && $quality['Video'] === ['1080p', 'H.264', 'WEB-DL'],
    'Aaneengeschreven DD5.1 en H.264 worden herkend zonder dubbele WEB-tag');
$quality = \NnSpotty\UI\SpotReleaseQuality::parse('Fixture (2020) 2160p HDR10+ HEVC DDP5.1 MULTISUBS');
check($quality === ['Video' => ['4K (2160p)', 'HDR10+', 'HEVC'], 'Audio' => ['DD+', '5.1'], 'Ondertiteling' => ['Meerdere talen']],
    'HDR10+ en DD+ blijven onderscheidbaar en meertalige subs krijgen geen verzonnen SRT-formaat');
foreach (['Dutch (1991)', 'Atmos (2016) 1080p', 'English Patient (1996) 720p'] as $title) {
    $quality = \NnSpotty\UI\SpotReleaseQuality::parse($title);
    check(!isset($quality['Audio']) && !isset($quality['Taal']), 'Titelwoorden worden niet als taal/audio beschouwd: ' . $title);
}
check(\NnSpotty\UI\SpotReleaseQuality::render('Gewone titel') === ''
    && !str_contains(\NnSpotty\UI\SpotReleaseQuality::render('Fixture (2020) 1080p <script>alert(1)</script>'), '<script>'),
    'Onbekende kwaliteit blijft weg en ongecontroleerde titelmarkup wordt niet weergegeven');
