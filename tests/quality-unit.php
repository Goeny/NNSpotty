<?php

declare(strict_types=1);

use NnSpotty\UI\SpotReleaseQuality;

foreach ([
    'x264' => 'x264', 'x265' => 'x265', 'AV1' => 'AV1', 'H.264' => 'H.264', 'H.265' => 'H.265',
    'HEVC' => 'HEVC', 'VP9' => 'VP9', '4K' => '4K (2160p)', 'UHD' => '4K (2160p)',
    'HDR' => 'HDR', 'HDR10' => 'HDR10', 'HDR10+' => 'HDR10+', 'HDR10Plus' => 'HDR10+',
    'Dolby Vision' => 'Dolby Vision', 'SDR' => 'SDR', '8K' => '8K (4320p)',
    'Dolby Atmos' => 'Atmos', 'Dolby Digital' => 'Dolby Digital', 'DD 5.1' => 'Dolby Digital',
    'DD+' => 'DD+', 'Dolby Digital Plus' => 'DD+', 'TrueHD' => 'TrueHD', 'DTS' => 'DTS',
    'DTS-HD MA' => 'DTS-HD MA', 'DTS:X' => 'DTS:X', 'AAC' => 'AAC', 'FLAC' => 'FLAC', 'MP3' => 'MP3',
    'Blu-ray' => 'Blu-ray', 'BDRemux' => 'BDRemux', 'WEB-DL' => 'WEB-DL', 'WEBRip' => 'WEBRip',
    'BluRay' => 'Blu-ray', 'DVD' => 'DVD', 'Remux' => 'REMUX', 'DTS-HD HRA' => 'DTS-HD HRA',
] as $token => $expected) {
    $parsed = SpotReleaseQuality::parse('Fixture (2026) ' . $token);
    check(in_array($expected, array_merge(...array_values($parsed)), true), 'Kwaliteitsbadge herkent expliciete tag ' . $token);
}
foreach (['HDR10+', 'HDR10Plus', 'Dolby Digital Plus', 'DTS:X', 'DTS-HD MA', 'BD-Remux', 'x265', 'x264'] as $token) {
    $parsed = SpotReleaseQuality::parse('Fixture (2026) ' . $token);
    check(count(array_merge(...array_values($parsed))) === 1, 'Specifieke kwaliteit geeft geen onterecht generiek/dubbel kenmerk: ' . $token);
}
check(SpotReleaseQuality::parse('MP3 (2026) 1080p')['Video'] === ['1080p']
    && !isset(SpotReleaseQuality::parse('MP3 (2026) 1080p')['Audio']),
    'Filmnaam wordt niet gebruikt om een audioformaat te verzinnen');
$badges = SpotReleaseQuality::render('Fixture (2026) 2160p x265 HDR10+ TrueHD Atmos 7.1 WEB-DL');
check(substr_count($badges, 'quality-badge-format') === 7 && str_contains($badges, '>x265</span>')
    && str_contains($badges, 'Uit de releasetitel;') && !str_contains($badges, '<img') && !str_contains($badges, 'http'),
    'Eigen typografische badges zijn toegankelijk als tekst, zonder externe requests of onterecht officiele logos');
check(!str_contains(SpotReleaseQuality::render('Fixture (2026) 1080p <script>unsafe()</script>'), '<script>'),
    'Kwaliteitsbadges publiceren alleen vaste geescapete labels, geen titelmarkup');
$qualitySpot = $spot;
$qualitySpot['title'] = 'Fixture (2026) 2160p x265 HDR10+ TrueHD Atmos 7.1 WEB-DL';
$qualitySpot['category'] = 'Beeld';
$qualitySpot['subcategories'] = ['1z00'];
$qualityMetadata = [
    'id' => 123, 'type' => 'movie', 'title' => 'Fixture', 'poster' => '', 'still' => '', 'fanart' => '',
    'episode_title' => '', 'season' => null, 'episode' => null, 'episode_missing' => false,
    'date' => '2026', 'runtime' => 120, 'genres' => [], 'overview' => '',
];
foreach ([false, true] as $qualityPreview) {
    $original = \NnSpotty\UI\SpotDetailPageRenderer::summary($qualitySpot, $qualityPreview, false, false);
    $enriched = \NnSpotty\Metadata\View::hero($qualitySpot, $qualityMetadata, '', false, $qualityPreview);
    check(str_contains($original, $badges) && str_contains($enriched, $badges),
        'Originele en verrijkte ' . ($qualityPreview ? 'preview' : 'detail') . ' delen exact dezelfde kwaliteitsbadges');
}
