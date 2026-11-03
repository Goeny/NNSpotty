<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NnSpotty\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/TmdbFixture.php';
if (PHP_SAPI === 'cli-server' && str_starts_with($_SERVER['REQUEST_URI'], '/assets/')) return false;

use NnSpotty\Metadata\EnrichmentRepository;
use NnSpotty\Metadata\Repository;
use NnSpotty\Metadata\Service;
use NnSpotty\Metadata\Tmdb;
use NnSpotty\Metadata\View;
use NnSpotty\UI\SpotDetailPageRenderer;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class DiscoveryPdo extends PDO
{
    public array $cache = [];
    public array $matches = [];
    public array $writes = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DiscoveryStatement($this, $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->prepare($query);
    }
}

final class DiscoveryStatement extends PDOStatement
{
    private array $parameters = [];
    public function __construct(private readonly DiscoveryPdo $pdo, private readonly string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];
        if (str_starts_with($this->sql, 'INSERT INTO tvdb_cache')) {
            $this->pdo->cache[$params[0]] = $params[1];
        } elseif (str_starts_with($this->sql, 'INSERT INTO tvdb_matches')) {
            $this->pdo->writes[] = $params;
            $this->pdo->matches[$params[0]] = ['input_hash' => $params[1], 'result_json' => $params[2],
                'choice_json' => $this->pdo->matches[$params[0]]['choice_json'] ?? null, 'fresh' => true];
        } elseif (!str_starts_with($this->sql, 'SELECT')) {
            throw new RuntimeException('Unexpected fixture SQL: ' . $this->sql);
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'RELEASE_LOCK')) return 1;
        if (str_contains($this->sql, 'FROM tvdb_cache')) return $this->pdo->cache[$this->parameters[0]] ?? false;
        throw new RuntimeException('Unexpected fixture scalar query: ' . $this->sql);
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM tmdb_settings')) return ['enabled' => true, 'token' => 'fixture', 'language' => 'nl-NL'];
        if (str_contains($this->sql, 'FROM tvdb_settings')) return ['enabled' => false, 'api_key' => '', 'language' => 'nld'];
        if (str_contains($this->sql, 'FROM tvdb_matches')) return $this->pdo->matches[$this->parameters[0]] ?? false;
        throw new RuntimeException('Unexpected fixture row query: ' . $this->sql);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if (str_contains($this->sql, 'FROM metadata_providers') || str_contains($this->sql, 'FROM metadata_match_rules')) return [];
        throw new RuntimeException('Unexpected fixture list query: ' . $this->sql);
    }
}

function expectDiscovery(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    if (PHP_SAPI !== 'cli-server') echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new DiscoveryPdo();
$repo = new Repository($pdo);
$api = new TmdbFixture();
$api->movieExtras = [
    'status' => 'Released', 'original_language' => 'en', 'budget' => 130000000, 'revenue' => 218366336,
    'keywords' => ['keywords' => [['id' => 1, 'name' => 'epic battle'], ['id' => 1, 'name' => 'epic battle'],
        ['name' => '<script>alert(1)</script>'], ['name' => '']]],
    'recommendations' => ['results' => [
        ['id' => 603, 'title' => 'Same movie'],
        ['id' => 604, 'title' => 'Another Film & More', 'release_date' => '2005-04-10', 'backdrop_path' => '/film.jpg', 'vote_average' => 7.1, 'vote_count' => 123],
        ['id' => 604, 'title' => 'Another Film & More', 'release_date' => '2005-04-10', 'backdrop_path' => '/film.jpg', 'vote_average' => 7.1, 'vote_count' => 123],
        ['id' => 605, 'title' => '<unsafe>', 'backdrop_path' => 'https://evil.invalid/x.jpg'],
        ['id' => 606, 'title' => 'Adult suggestion', 'adult' => true],
        ['id' => -1, 'title' => 'Invalid'],
    ]],
];
$tmdb = new Tmdb($repo, $api);
$metadata = $tmdb->details('movie', 603, [], 'nl-NL');
expectDiscovery($metadata['budget'] === 130000000 && $metadata['revenue'] === 218366336
    && $metadata['status'] === 'Released' && $metadata['original_language'] === 'en', 'TMDB financial and title fields are normalized');
expectDiscovery($metadata['keywords'] === ['epic battle', '<script>alert(1)</script>'], 'Keywords are bounded, deduplicated and omit empty names');
expectDiscovery(count($metadata['recommendations']) === 2 && $metadata['recommendations'][0]['year'] === '2005'
    && $metadata['recommendations'][0]['image'] === 'https://image.tmdb.org/t/p/w780/film.jpg'
    && $metadata['recommendations'][1]['image'] === '', 'Suggestions omit current/adult/invalid titles and unsafe images, deduplicate IDs');
$count = count($api->calls);
$tmdb->details('movie', 603, [], 'nl-NL');
expectDiscovery(count($api->calls) === $count && str_contains($api->calls[0][1]['append_to_response'], 'keywords,recommendations'),
    'Discovery data uses the existing appended request and shared cache, without a request per suggestion');
$series = $tmdb->details('series', 700, [], 'nl-NL');
expectDiscovery($series['budget'] === null && $series['revenue'] === null && $series['keywords'] === []
    && $series['recommendations'] === [], 'Unavailable fields and series budgets are omitted rather than invented');
$seriesApi = new TmdbFixture();
$seriesApi->seriesExtras = ['status' => 'Returning Series', 'original_language' => 'nl',
    'keywords' => ['results' => [['name' => 'kingdom']]],
    'recommendations' => ['results' => [['id' => 701, 'name' => 'Another Show', 'first_air_date' => '2024-01-01']]]];
$episode = (new Tmdb(new Repository(new DiscoveryPdo()), $seriesApi))->details('series', 700, ['season' => 3, 'episode' => 2], 'nl-NL');
expectDiscovery($episode['type'] === 'episode' && $episode['keywords'] === ['kingdom']
    && $episode['recommendations'][0]['title'] === 'Another Show' && $episode['recommendations'][0]['year'] === '2024',
    'Series/episodes use TMDB keyword results and show-name/first-air-date recommendation fields');

$largeApi = new TmdbFixture();
$cast = [];
for ($i = 1; $i <= 40; $i++) $cast[] = ['id' => $i, 'name' => 'Actor ' . $i, 'character' => 'Role'];
$largeApi->movieExtras = ['budget' => 0, 'revenue' => -1, 'credits' => ['cast' => $cast, 'crew' => [
    ['id' => 99, 'name' => 'Writer', 'job' => 'Writer'], ['id' => 1, 'name' => 'Actor 1', 'job' => 'Director'],
]]];
$large = (new Tmdb(new Repository(new DiscoveryPdo()), $largeApi))->details('movie', 603, [], 'nl-NL');
expectDiscovery(count($large['people']) <= 30 && count(array_filter($large['people'], static fn (array $p): bool => $p['type'] === 'Director')) === 1,
    'A large cast cannot displace the director, even when the director also acts');
expectDiscovery($large['budget'] === null && $large['revenue'] === null, 'Zero and negative financial amounts are unknown, not displayed as zero');
$boundedApi = new TmdbFixture();
$boundedApi->movieExtras = ['keywords' => ['keywords' => []], 'recommendations' => ['results' => []]];
for ($i = 1; $i <= 50; $i++) {
    $boundedApi->movieExtras['keywords']['keywords'][] = ['name' => 'Keyword ' . $i];
    $boundedApi->movieExtras['recommendations']['results'][] = ['id' => 1000 + $i, 'title' => 'Film ' . $i];
}
$bounded = (new Tmdb(new Repository(new DiscoveryPdo()), $boundedApi))->details('movie', 603, [], 'nl-NL');
expectDiscovery(count($bounded['keywords']) === 30 && count($bounded['recommendations']) === 12, 'Provider lists are capped at 30 keywords and 12 suggestions');

$spot = ['id' => str_repeat('a', 64), 'title' => 'Fixture.Movie.2020.1080p.BluRay.x264.DTS',
    'category' => 'Beeld', 'subcategories' => ['1z00'], 'website' => '', 'nzb_segments' => [],
    'image_segments' => [], 'image_url' => '', 'poster' => 'Fixture spotter', 'spotter_id' => '',
    'posted_at' => '2026-11-03 12:00:00', 'size' => 1000, 'description' => 'Spot description',
    'tag' => '', 'message_id' => 'fixture@example.invalid'];
$hash = Service::matchSignature($spot, $repo->profile());
$choice = json_encode(['input' => Service::signature($spot, ''), 'provider' => 'tmdb', 'type' => 'movie', 'id' => 603], JSON_THROW_ON_ERROR);
$pdo->matches[$spot['id']] = ['input_hash' => $hash, 'choice_json' => $choice, 'fresh' => true,
    'result_json' => json_encode(['state' => 'matched', 'manual' => true, 'metadata' => $metadata], JSON_THROW_ON_ERROR)];
$api->calls = [];
$service = new Service($repo, $api, $api);
$upgraded = $service->lookup($spot);
expectDiscovery($upgraded['manual'] && $upgraded['metadata']['id'] === 603 && $upgraded['metadata']['detail_version'] === 1
    && $pdo->matches[$spot['id']]['choice_json'] === $choice && $pdo->matches[$spot['id']]['input_hash'] === $hash
    && $api->calls === [], 'Old positive caches upgrade via the same cached identity without searches or changing manual choices/signatures');
$writes = count($pdo->writes);
$service->lookup($spot);
expectDiscovery(count($pdo->writes) === $writes, 'The positive-cache upgrade runs once, not on every detail open');
$failedPdo = new DiscoveryPdo();
$failedPdo->matches[$spot['id']] = ['input_hash' => $hash, 'choice_json' => $choice, 'fresh' => true,
    'result_json' => json_encode(['state' => 'matched', 'manual' => true, 'metadata' => $metadata], JSON_THROW_ON_ERROR)];
$failedApi = new TmdbFixture();
$failedApi->fail = true;
$failed = (new Service(new Repository($failedPdo), $failedApi, $failedApi))->lookup($spot);
expectDiscovery($failed['state'] === 'matched' && $failed['metadata']['id'] === 603 && $failed['manual']
    && !empty($failed['warnings']) && $failedPdo->writes[0][3] === 300 && count($failedApi->calls) === 1,
    'Provider failure preserves the positive/manual match, reports a warning and backs off for five minutes');
$blockedPdo = new DiscoveryPdo();
$blockedPdo->matches[$spot['id']] = ['input_hash' => $hash, 'choice_json' => null, 'fresh' => true,
    'result_json' => '{"state":"blocked"}'];
$blocked = (new Service(new Repository($blockedPdo), $failedApi, $failedApi))->lookup($spot);
expectDiscovery($blocked['state'] === 'blocked' && $blockedPdo->writes === [] && count($failedApi->calls) === 1,
    'Blocked matches do not trigger discovery requests or lose their block');

$merge = new ReflectionMethod(Service::class, 'merge');
$primary = $metadata;
$primary['provider'] = 'tvdb';
$primary['people'] = [['name' => 'TVDB Actor', 'type' => 'Actor']];
foreach (['keywords', 'recommendations', 'status', 'original_language', 'budget', 'revenue'] as $key) unset($primary[$key]);
$merged = $merge->invoke(null, $primary, $metadata);
expectDiscovery($merged['provider'] === 'tvdb' && $merged['budget'] === 130000000 && $merged['keywords'] === $metadata['keywords']
    && in_array('TMDB Director', array_column($merged['people'], 'name'), true)
    && count(array_filter($merged['people'], static fn (array $p): bool => $p['type'] === 'Actor')) === 1,
    'TVDB-primary matches gain TMDB discovery fields and missing directors without replacing their cast');

$flatten = new ReflectionMethod(EnrichmentRepository::class, 'flattenVideo');
$row = $flatten->invoke(new EnrichmentRepository($pdo), $spot, $metadata);
foreach (['director' => 'TMDB Director', 'keyword' => 'epic battle', 'language' => 'en', 'status' => 'Released'] as $type => $name) {
    expectDiscovery(in_array(['type' => $type, 'key' => sha1(mb_strtolower($name, 'UTF-8')), 'name' => $name], $row['entities'], true),
        'Search index stores an exact ' . $type . ' entity');
}
expectDiscovery(str_contains($row['search_text'], 'epic battle') && !str_contains($row['search_text'], 'Another Film'),
    'Keywords are searchable, but recommended titles never pollute this spot search index');
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE spot_metadata_entities (spot_id TEXT, entity_type TEXT, entity_key TEXT, entity_name TEXT)');
    $db->exec("CREATE TABLE spot_browse (spot_id TEXT); INSERT INTO spot_browse VALUES ('matched'), ('other')");
    foreach ($row['entities'] as $entity) {
        $db->prepare('INSERT INTO spot_metadata_entities VALUES (?, ?, ?, ?)')->execute(['matched', $entity['type'], $entity['key'], $entity['name']]);
    }
    $filter = new ReflectionMethod(NnSpotty\Usenet\SpotBrowseQuery::class, 'entityFilter');
    foreach (['director' => 'TMDB Director', 'keyword' => 'epic battle', 'language' => 'en', 'status' => 'Released'] as $type => $name) {
        $where = $parameters = [];
        $query = new NnSpotty\Usenet\SpotBrowseQuery($db);
        $property = new ReflectionProperty($query, 'metadataEntities');
        $property->setValue($query, true);
        $filter->invokeArgs($query, ['b', ['type' => $type, 'key' => sha1(mb_strtolower($name, 'UTF-8'))], &$where, &$parameters]);
        $select = $db->prepare('SELECT b.spot_id FROM spot_browse b WHERE ' . implode(' AND ', $where));
        $select->execute($parameters);
        expectDiscovery($select->fetchAll(PDO::FETCH_COLUMN) === ['matched'], 'Actual entity-filter SQL returns only matching spots for ' . $type);
    }
}

$html = SpotDetailPageRenderer::render('fixture', false, $spot, [], '', metadata: $metadata);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);
expectDiscovery($xpath->query('//div[@class="metadata-hero-details"]//div[@class="metadata-directors"]/a[contains(@href,"meta_type=director")]')->length === 1,
    'Director appears next to release quality in the hero with an entity search link');
expectDiscovery($xpath->query('//div[@class="metadata-hero-details"]//dl/div')->length === 2, 'Video and audio retain separate adjacent quality groups');
expectDiscovery($xpath->query('//div[@class="metadata-info-grid"]/*')->length === 2 && str_contains($html, 'US$ 130.000.000')
    && str_contains($html, 'Uitgebracht') && str_contains($html, 'Engels'), 'Compact spot facts share a row with localized title facts and explicit USD amounts');
expectDiscovery($xpath->query('//div[contains(@class,"metadata-taxonomy")]/section')->length === 2
    && str_contains($html, 'meta_type=keyword') && !str_contains($html, '<script>alert(1)</script>'), 'Studios and escaped clickable keywords share a row');
expectDiscovery($xpath->query('//section[@aria-labelledby="recommendations-title"]/following::section[@id="comments"]')->length === 1
    && str_contains($html, 'q=Another+Film+%26+More') && str_contains($html, '71%'), 'Suggestions appear above comments with local title search links and scores');
expectDiscovery($xpath->query('//a[contains(@class,"recommendation-card")]/img[@src="https://image.tmdb.org/t/p/w780/film.jpg"]')->length === 1
    && $xpath->query('//a[contains(@class,"recommendation-card")]/img[@src="/assets/images/placeholder.png"]')->length === 1,
    'Recommendation HTML uses the actual TMDB fanart, with a placeholder only for the item without artwork');
foreach (['javascript:alert(1)', 'https://image.tmdb.org.evil.invalid/t/p/w780/film.jpg',
    'https://user@image.tmdb.org/t/p/w780/film.jpg', 'https://image.tmdb.org:8443/t/p/w780/film.jpg',
    'https://evil.invalid/film.jpg', 'https://image.tmdb.org/t/p/w780/film.jpg" onload="alert(1)'] as $unsafe) {
    $unsafeMetadata = $metadata;
    $unsafeMetadata['recommendations'] = [array_replace($metadata['recommendations'][0], ['image' => $unsafe])];
    $unsafeHtml = View::recommendations($unsafeMetadata, true);
    expectDiscovery(str_contains($unsafeHtml, 'src="/assets/images/placeholder.png"') && !str_contains($unsafeHtml, 'src="https:'),
        'Recommendation artwork rejects an unsafe URL: ' . $unsafe);
}
expectDiscovery(str_contains($html, 'class="metadata-title-link" href="/spots?q=TMDB+Fixture+Film"'), 'The main title also starts a local title search');
$preview = SpotDetailPageRenderer::summary($spot, true, metadata: $metadata);
expectDiscovery(!str_contains($preview, 'Trefwoorden') && !str_contains($preview, 'Meer zoals dit')
    && !str_contains($preview, 'metadata-directors') && !str_contains($preview, 'US$'), 'New detail features never expand quick previews');
expectDiscovery(!str_contains(View::recommendations($metadata, false), '<img')
    && !str_contains(View::facts($large), 'US$') && View::recommendations([], true) === '', 'Image preference and unavailable information are respected');

if (PHP_SAPI === 'cli-server') {
    $metadata['poster'] = '/assets/images/placeholder.png';
    $metadata['fanart'] = '/assets/images/logo.png';
    $metadata['posters'] = $metadata['backdrops'] = [];
    $metadata['overview'] = 'Een voorbeeldfilm met een compacte rij video- en audiobadges naast de regisseur. Beschikbare titelinformatie, trefwoorden en suggesties geven nieuwe manieren om lokale spots te vinden.';
    $metadata['keywords'] = ['epic battle', 'crusade', 'jerusalem', 'knight', 'sword', 'historical drama'];
    $metadata['recommendations'][1]['title'] = 'A Second Film';
    for ($i = 3; $i <= 12; $i++) {
        $metadata['recommendations'][] = ['title' => 'Suggested Film ' . $i, 'year' => '2025', 'image' => '', 'score' => null];
    }
    for ($i = 1; $i <= 14; $i++) {
        $metadata['people'][] = ['name' => 'Example Actor ' . $i, 'type' => 'Actor', 'role' => 'Character', 'image' => ''];
    }
    foreach ($metadata['people'] as &$person) $person['image'] = '/assets/images/placeholder-small.png';
    unset($person);
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('<script src="/assets/retrieve.js" defer></script>', '',
        SpotDetailPageRenderer::render('fixture', false, $spot, [], '', metadata: $metadata));
}
