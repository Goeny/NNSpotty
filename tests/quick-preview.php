<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NnSpotty\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
require __DIR__ . '/../src/helpers.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotReleaseQuality;

$spot = [
    'id' => str_repeat('a', 64), 'title' => 'Fixture.2025.2160p.BluRay.H265.TrueHD.Atmos.7.1',
    'category' => 'Beeld', 'subcategories' => ['1z00'], 'website' => '', 'nzb_segments' => ['fixture'],
    'image_segments' => [], 'image_url' => '', 'poster' => 'Fixture spotter',
    'posted_at' => '2026-11-03 12:00:00', 'size' => 1000, 'description' => 'Full spot description',
    'tag' => '', 'message_id' => 'fixture@example.invalid', 'is_favorite' => false,
];
$metadata = [
    'id' => 123, 'provider' => 'tmdb', 'type' => 'movie', 'title' => 'Example Movie',
    'poster' => 'http://127.0.0.1:18765/assets/images/placeholder.png',
    'still' => '', 'fanart' => 'http://127.0.0.1:18765/assets/images/logo.png',
    'episode_title' => '', 'season' => null, 'episode' => null, 'episode_missing' => false,
    'date' => '2025-10-02', 'runtime' => 94, 'genres' => ['Horror', 'Mysterie', 'Thriller'],
    'overview' => str_repeat('Een lange synopsis die in de quick details beperkt moet blijven tot drie regels. ', 30),
    'score' => ['value' => 5.9, 'votes' => 287],
    'certification' => ['country' => 'US', 'value' => 'R'],
    'people' => [['name' => 'Fixture Actor', 'role' => 'Example', 'type' => 'Actor', 'image' => '']],
    'companies' => [['name' => 'Fixture Studio']], 'trailers' => [],
    'links' => ['tmdb' => 'movies/123', 'tvdb' => 'movies/456'],
];
if (PHP_SAPI === 'cli-server') {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/assets/')) return false;
    \NnSpotty\UI\SpotLayout::downloads(static fn (): array => ['default' => 'personal:sabnzbd', 'targets' => [
        ['id' => 'personal:sabnzbd', 'label' => 'Mijn SABnzbd', 'client' => 'sabnzbd', 'enabled' => true],
        ['id' => 'personal:nzbget', 'label' => 'Mijn NZBGet', 'client' => 'nzbget', 'enabled' => true],
    ]]);
    $metadata['people'] = array_fill(0, 8, $metadata['people'][0]);
    $preview = SpotDetailPageRenderer::summary($spot, true, metadata: $metadata);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="nl" data-bs-theme="dark"><head><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Compact preview validation</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="/assets/spotty.css"></head><body class="spotty">'
        . '<aside class="preview-drawer is-open"><div class="preview-toolbar">Spotdetails</div><div class="preview">' . $preview . '</div></aside></body></html>';
    return;
}

$preview = SpotDetailPageRenderer::summary($spot, true, metadata: $metadata);
$checks = 0;
function expectPreview(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

foreach ([null, $metadata] as $data) {
    $html = SpotDetailPageRenderer::summary($spot, true, metadata: $data);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expectPreview($xpath->query('//section[contains(@class,"quick-hero")]')->length === 1
        && $xpath->query('//section[contains(@class,"quick-hero")]//div[@role="group"]')->length === 0
        && $xpath->query('//div[@class="quick-actions"]/following-sibling::section[contains(@class,"quick-hero")]')->length === 1,
        'Original and matched previews place their action toolbar before, not inside, the hero');
    foreach (['metadata-strip', 'metadata-release', 'Technische details', 'Full spot description', 'Fixture Studio'] as $excluded) {
        expectPreview(!str_contains($html, $excluded), 'Quick details omit ' . $excluded);
    }
    expectPreview(str_contains($html, SpotReleaseQuality::compact($spot['title']))
        && substr_count($html, 'quality-badge-compact') === 2 && !str_contains($html, 'release-quality'),
        'Quick details reuse exactly the overview video/audio chips without expanded quality lists');
    expectPreview(str_contains($html, '/spots/view?key=') && str_contains($html, '/spots/nzb?key='),
        'Full detail and NZB actions stay available');
}
expectPreview(str_contains($preview, 'hero-backdrop') && str_contains($preview, $metadata['poster'])
    && str_contains($preview, $metadata['fanart']) && str_contains($preview, 'metadata-overview'),
    'Matched hero retains fanart, poster and synopsis');
expectPreview(str_contains($preview, 'TMDB') && !str_contains($preview, 'not endorsed')
    && !str_contains($preview, 'Horror, Mysterie, Thriller'), 'Provider attribution remains without disclaimer; genres are limited');
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $preview, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);
expectPreview($xpath->query('//section[contains(@class,"quick-hero")]//div[@class="quick-sources"]')->length === 0
    && $xpath->query('//div[@class="quick-sources"]/preceding-sibling::section[contains(@class,"quick-hero")]')->length === 1
    && $xpath->query('//div[@class="quick-sources"]//a')->length === 2,
    'Both providers appear below the hero, never on the fanart');
expectPreview($xpath->query('//div[@class="quick-cast"]/preceding-sibling::section[contains(@class,"quick-hero")]')->length === 1
    && str_contains($preview, 'Fixture Actor'), 'Actor list uses the space below the hero');
$largeCast = $metadata;
$largeCast['people'] = array_fill(0, 20, $metadata['people'][0]);
$largeCast['people'][] = ['name' => 'Director excluded', 'type' => 'Director', 'role' => '', 'image' => ''];
$castHtml = SpotDetailPageRenderer::summary($spot, true, metadata: $largeCast);
expectPreview(substr_count($castHtml, 'class="person-card"') === 8 && !str_contains($castHtml, 'Director excluded'),
    'Compact cast is bounded to eight actors, without extended crew or studios');
$detail = SpotDetailPageRenderer::summary($spot, false, metadata: $metadata);
foreach (['metadata-people', 'metadata-release', 'Technische details', 'Full spot description', 'Fixture Studio', 'release-quality'] as $included) {
    expectPreview(str_contains($detail, $included), 'Full detail still contains ' . $included);
}
expectPreview(str_contains($detail, $metadata['overview']) && !str_contains($detail, 'quick-hero'), 'Full detail keeps the complete synopsis');
expectPreview(!str_contains($detail, 'not endorsed') && str_contains($detail, 'tmdb-logo'),
    'Full detail retains TMDB logo and source without the disclaimer');
$episode = $metadata;
$episode['episode_missing'] = true;
$episode['season'] = 2;
$episode['requested_episode'] = 3;
expectPreview(str_contains(SpotDetailPageRenderer::summary($spot, true, metadata: $episode), 'aflevering niet bevestigd'),
    'Unconfirmed episode remains explicitly identified in compact mode');
$hidden = SpotDetailPageRenderer::summary($spot, true, false, metadata: $metadata);
expectPreview(!str_contains($hidden, $metadata['poster']) && !str_contains($hidden, $metadata['fanart'])
    && !str_contains($hidden, 'hero-backdrop'), 'Disabled artwork remains disabled (provider logos remain)');
$unsafe = $metadata;
$unsafe['overview'] = '<script>unsafe()</script>';
expectPreview(!str_contains(SpotDetailPageRenderer::summary($spot, true, metadata: $unsafe), '<script>unsafe'),
    'Compact synopsis still escapes provider text');
$music = [
    'provider' => 'musicbrainz', 'type' => 'release', 'id' => 'fixture', 'title' => 'Fixture Album', 'artist' => 'Fixture Artist',
    'cover' => '', 'kind' => 'Album', 'date' => '2008', 'country' => '', 'genres' => [], 'end' => '', 'note' => 'Edition notes',
    'labels' => ['Fixture Label'], 'tracks' => [['disc' => 1, 'number' => '1', 'title' => 'Track One', 'artist' => '', 'length' => 60000]],
    'track_count' => 1, 'duration' => 60000,
];
$musicPreview = SpotDetailPageRenderer::summary($spot, true, metadata: $music);
$musicDetail = SpotDetailPageRenderer::summary($spot, false, metadata: $music);
expectPreview(str_contains($musicPreview, 'Fixture Artist') && !str_contains($musicPreview, 'music-tracks')
    && !str_contains($musicPreview, 'Edition notes') && str_contains($musicDetail, 'music-tracks'),
    'Music preview stays compact while full detail preserves tracklists and edition notes');
echo "Quick preview: $checks checks passed.\n";
