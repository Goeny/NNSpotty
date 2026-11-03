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

function expectMusicOverview(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS: ' . $message . PHP_EOL;
}

$spot = [
    'id' => str_repeat('a', 64), 'title' => 'Original music release S01 FLAC',
    'category' => 'Geluid', 'subcategories' => [], 'poster' => 'Fixture',
    'posted_at' => '2026-01-01 00:00:00', 'size' => 1000,
    'image_segments' => [], 'image_url' => '',
];
$summary = ['media_kind' => 'music', 'provider' => 'MUSICBRAINZ', 'genres' => []];
foreach ([
    ['Pink Floyd', 'Dark Side of the Moon', 1973, 'Pink Floyd - Dark Side of the Moon (1973)'],
    ['Coldplay', 'Viva la Vida', 2008, 'Coldplay - Viva la Vida (2008)'],
    ['', 'Artist name', null, 'Artist name'],
    ['Example', 'Album', null, 'Example - Album'],
    ['Example', 'Album (2008)', 2008, 'Example - Album (2008)'],
    ['A & B', '<Album>', 2008, 'A &amp; B - &lt;Album&gt; (2008)'],
] as [$artist, $title, $year, $expected]) {
    $spot['metadata_summary'] = $summary + ['artist' => $artist, 'title' => $title, 'year' => $year];
    $html = NnSpotty\UI\SpotsPageRenderer::rows([$spot], ['title']);
    expectMusicOverview(str_contains($html, '>' . $expected . '</a>'), 'Music title renders ' . $expected);
    expectMusicOverview(!str_contains($html, 'Original music release') && !str_contains($html, ' · S01'),
        'Music does not retain the release title or acquire a video season suffix');
}
$spot['category'] = 'Beeld';
$spot['title'] = 'Original S02E03';
$spot['metadata_summary'] = ['media_kind' => 'video', 'title' => 'Series', 'artist' => 'Ignore', 'year' => 2020];
expectMusicOverview(str_contains(NnSpotty\UI\SpotsPageRenderer::rows([$spot], ['title']), '>Series (2020) · S02E03</a>'),
    'Video titles and season/episode suffixes remain unchanged');

$fromResult = new ReflectionMethod(NnSpotty\Metadata\EnrichmentRepository::class, 'summaryFromResult');
$cachedSummary = $fromResult->invoke(null, 'music', ['metadata' => [
    'title' => 'Dark Side of the Moon', 'artist' => 'Pink Floyd', 'date' => '1973-03-01',
]]);
expectMusicOverview($cachedSummary['artist'] === 'Pink Floyd' && $cachedSummary['year'] === 1973,
    'Cache hydration includes the artist when the search index is missing');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
    $pdo->exec('CREATE TABLE metadata_search_index (spot_id TEXT, media_kind TEXT, provider TEXT, title TEXT, subtitle TEXT, year INTEGER, genres TEXT)');
    foreach (['tvdb_matches', 'music_matches'] as $table) {
        $pdo->exec("CREATE TABLE $table (spot_id TEXT, result_json TEXT, expires_at TEXT)");
    }
    $pdo->prepare("INSERT INTO metadata_search_index VALUES (?, 'music', 'musicbrainz', 'Viva la Vida', 'Coldplay', 2008, '')")
        ->execute([$spot['id']]);
    $repo = new NnSpotty\Metadata\EnrichmentRepository($pdo);
    unset($spot['metadata_summary']);
    $spot['category'] = 'Geluid';
    $enriched = $repo->attachSummaries([$spot], true, true);
    expectMusicOverview($enriched[0]['metadata_summary']['artist'] === 'Coldplay'
        && str_contains(NnSpotty\UI\SpotsPageRenderer::rows($enriched, ['title']), '>Coldplay - Viva la Vida (2008)</a>'),
        'Existing indexed artist reaches the overview even without a fresh match cache');
    expectMusicOverview(!isset($repo->attachSummaries([$spot], true, false)[0]['metadata_summary']),
        'Music metadata preference still gates overview enrichment');
    $cover = 'https://coverartarchive.org/release-group/fixture/front-500';
    $spot['image_url'] = 'https://example.invalid/original.jpg';
    $spot['image_segments'] = ['original@example.invalid'];
    $cachedInsert = $pdo->prepare('INSERT INTO music_matches VALUES (?, ?, ?)');
    foreach ([$cover, '', 'javascript:unsafe()'] as $image) {
        $pdo->exec('DELETE FROM music_matches');
        $cachedInsert->execute([$spot['id'], json_encode(['state' => 'matched', 'metadata' => [
            'title' => 'Viva la Vida', 'artist' => 'Coldplay', 'cover' => $image,
        ]], JSON_THROW_ON_ERROR), '2099-01-01']);
        $enriched = $repo->attachSummaries([$spot], true, true);
        $html = NnSpotty\UI\SpotsPageRenderer::rows($enriched, ['cover', 'title']);
        if ($image === $cover) {
            expectMusicOverview(($enriched[0]['metadata_poster'] ?? '') === $cover
                && str_contains($html, 'src="' . $cover . '"') && !str_contains($html, '/spots/image?'),
                'Matched album cover replaces the original NNTP image in overview HTML');
        } else {
            expectMusicOverview(!isset($enriched[0]['metadata_poster']) && str_contains($html, '/spots/image?'),
                'Missing or unsafe album cover preserves the original spot image');
        }
    }
    expectMusicOverview(!isset($repo->attachSummaries([$spot], true, false)[0]['metadata_poster']),
        'Disabled music enrichment does not expose the album cover');
}
