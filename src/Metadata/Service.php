<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Repository $repo, private readonly Gateway $api)
    {
    }

    public static function signature(array $spot, string $language): string
    {
        return hash('sha256', json_encode([$spot['title'], $spot['category'], $spot['subcategories'], $language], JSON_THROW_ON_ERROR));
    }

    public function search(string $query, string $type): array
    {
        $query = trim($query);
        if (strlen($query) < 2 || strlen($query) > 200 || !in_array($type, ['movie', 'series'], true)) {
            throw new InvalidArgumentException('Gebruik een titel van 2 tot 200 tekens en kies film of serie.');
        }
        $rows = $this->cached('/search', ['query' => $query, 'type' => $type, 'limit' => 20]);
        if (!array_is_list($rows)) throw new RuntimeException('TVDB gaf geen geldige zoekresultaten.');
        $results = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            if (!is_array($row)) throw new RuntimeException('Ongeldig TVDB-zoekresultaat.');
            $id = $row['tvdb_id'] ?? null;
            if ((!is_string($id) && !is_int($id)) || !ctype_digit((string) $id) || (int) $id < 1
                || ($row['type'] ?? null) !== $type || !is_string($row['name'] ?? null)) {
                throw new RuntimeException('TVDB-zoekresultaat mist een geldig ID, type of titel.');
            }
            $names = [$row['name']];
            foreach ([$row['aliases'] ?? [], $row['translations'] ?? []] as $translations) {
                if (is_array($translations)) {
                    foreach ($translations as $name) if (is_string($name)) $names[] = $name;
                }
            }
            $results[] = ['id' => (int) $id, 'type' => $type, 'name' => self::text($row['name']),
                'names' => array_slice($names, 0, 100), 'year' => self::text($row['year'] ?? '', 4)];
        }
        return $results;
    }

    public function lookup(array $spot): array
    {
        $release = Release::parse($spot);
        if ($release === null) return ['state' => 'unsupported'];
        $language = $this->repo->settings()['language'];
        $hash = self::signature($spot, $language);
        $old = $this->repo->match($spot['id']);
        if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
        if (!$this->repo->lock($spot['id'])) return ['state' => 'busy'];
        try {
            $old = $this->repo->match($spot['id']);
            if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
            // A manual choice survives language changes, but not changed spot input.
            $choice = $old !== null ? $old['choice_json'] : null;
            if ($choice !== null && ($choice['input'] ?? '') !== self::signature($spot, '')) $choice = null;
            if (($choice['blocked'] ?? false) === true) {
                $result = ['state' => 'blocked'];
            } else {
                $selected = $choice;
                if ($selected === null) {
                    $candidates = $this->search($release['query'], $release['type']);
                    $selected = Release::select($release, $candidates);
                }
                if ($selected === null) {
                    $result = ['state' => empty($candidates) ? 'unmatched' : 'ambiguous', 'query' => $release['query']];
                } else {
                    $result = ['state' => 'matched', 'manual' => $choice !== null,
                        'metadata' => $this->details($selected['type'], $selected['id'], $language, $choice ?? $release)];
                }
            }
            $this->repo->result($spot['id'], $hash, $result, $result['state'] === 'matched' ? 604800 : 86400);
            return $result;
        } catch (Throwable $exception) {
            error_log('TVDB metadata: ' . $exception->getMessage());
            $result = ['state' => 'error', 'message' => 'TVDB kon niet worden opgehaald. De oorspronkelijke spot blijft beschikbaar. Probeer over vijf minuten opnieuw.'];
            $this->repo->result($spot['id'], $hash, $result, 300);
            return $result;
        } finally {
            $this->repo->unlock($spot['id']);
        }
    }

    public function choose(array $spot, ?array $choice): void
    {
        if (Release::parse($spot) === null) throw new InvalidArgumentException('Alleen films en series kunnen worden gekoppeld.');
        if ($choice !== null && !($choice['blocked'] ?? false)) {
            if (!in_array($choice['type'] ?? '', ['movie', 'series'], true) || !is_int($choice['id'] ?? null) || $choice['id'] < 1) {
                throw new InvalidArgumentException('Ongeldige TVDB-keuze.');
            }
            // Validate the selected record (and episode, if supplied) before replacing a shared match.
            $choice['strict_episode'] = true;
            $this->details($choice['type'], $choice['id'], $this->repo->settings()['language'], $choice);
        }
        if ($choice !== null) $choice['input'] = self::signature($spot, '');
        if (!$this->repo->lock($spot['id'])) throw new RuntimeException('De TVDB-match wordt al verwerkt. Probeer opnieuw.');
        try {
            $this->repo->choose($spot['id'], self::signature($spot, $this->repo->settings()['language']), $choice);
        } finally {
            $this->repo->unlock($spot['id']);
        }
    }

    private function cached(string $path, array $query = [], bool $optional = false): array
    {
        $key = $path . '?' . http_build_query($query);
        $cached = $this->repo->cached($key);
        if ($cached !== null) return $cached;
        if (!$this->repo->lock('provider:' . $key)) throw new RuntimeException('TVDB-data wordt al opgehaald. Probeer later opnieuw.');
        try {
            $data = $this->repo->cached($key);
            if ($data !== null) return $data;
            $data = $this->api->get($path, $query);
            if ($data === null && !$optional) throw new RuntimeException('TVDB-record niet beschikbaar.');
            if ($data === null) $data = [];
            $this->repo->cache($key, $data, $path === '/search' ? 86400 : 604800);
            return $data;
        } finally {
            $this->repo->unlock('provider:' . $key);
        }
    }

    private function record(string $type, int $id, string $language): array
    {
        $path = '/' . ($type === 'movie' ? 'movies' : ($type === 'series' ? 'series' : 'episodes')) . '/' . $id;
        $data = $this->cached($path . '/extended');
        if (($data['id'] ?? null) !== $id || !is_string($data['name'] ?? null)) throw new RuntimeException('TVDB-record komt niet overeen met de gekozen titel.');
        foreach (array_unique([$language, 'eng']) as $lang) {
            $needsName = !isset($data['_translated_name']);
            $needsOverview = empty($data['_translated_overview']);
            if (!$needsName && !$needsOverview) break;
            $available = array_merge($data['nameTranslations'] ?? [], $data['overviewTranslations'] ?? []);
            if (!in_array($lang, $available, true)) continue;
            $translation = $this->cached($path . '/translations/' . $lang, optional: true);
            if ($needsName && !empty($translation['name']) && is_string($translation['name'])) $data['_translated_name'] = $translation['name'];
            if ($needsOverview && !empty($translation['overview']) && is_string($translation['overview'])) $data['_translated_overview'] = $translation['overview'];
        }
        return $data;
    }

    private function details(string $type, int $id, string $language, array $release): array
    {
        $base = $this->record($type, $id, $language);
        $data = $base;
        $episode = null;
        $episodeMissing = false;
        $seasonNumber = $release['season'] ?? null;
        $episodeNumber = $release['episode'] ?? null;
        if ($type === 'series' && $seasonNumber !== null && $episodeNumber !== null) {
            $episodes = $this->cached('/series/' . $id . '/episodes/official',
                ['page' => 0, 'season' => $seasonNumber, 'episodeNumber' => $episodeNumber]);
            if (!is_array($episodes['episodes'] ?? null)) throw new RuntimeException('TVDB gaf geen geldige afleveringslijst.');
            $found = array_values(array_filter($episodes['episodes'], static fn ($e): bool => is_array($e)
                && ($e['seriesId'] ?? null) === $id && ($e['seasonNumber'] ?? null) === $seasonNumber
                && ($e['number'] ?? null) === $episodeNumber && is_int($e['id'] ?? null)));
            if (count($found) === 1) {
                $episode = $this->record('episode', $found[0]['id'], $language);
                if (($episode['seriesId'] ?? null) !== $id || ($episode['seasonNumber'] ?? null) !== $seasonNumber
                    || ($episode['number'] ?? null) !== $episodeNumber) throw new RuntimeException('TVDB-aflevering komt niet overeen met de spot.');
                $data = $episode;
            } else {
                if (isset($release['input']) || ($release['strict_episode'] ?? false)) throw new InvalidArgumentException('Geen unieke aflevering met dit seizoen/nummer gevonden.');
                $episodeMissing = true;
            }
        }
        $types = $this->cached('/artwork/types');
        $art = ['poster' => '', 'fanart' => ''];
        foreach ($types as $artType) {
            if (!is_array($artType) || !isset($artType['id'], $artType['name'])) continue;
            $name = strtolower($artType['name']);
            $target = str_contains($name, 'poster') ? 'poster' : (str_contains($name, 'background') || str_contains($name, 'fanart') ? 'fanart' : null);
            if ($target === null) continue;
            foreach ($base['artworks'] ?? [] as $image) {
                if (!is_array($image)) throw new RuntimeException('TVDB gaf ongeldige artworkgegevens.');
                if (($image['type'] ?? null) !== $artType['id'] || $art[$target] !== '') continue;
                $art[$target] = self::imageUrl($image['image'] ?? '');
            }
        }
        if ($art['poster'] === '') $art['poster'] = self::imageUrl($base['image'] ?? '');
        $cast = [];
        foreach (array_merge($data['characters'] ?? [], $base === $data ? [] : ($base['characters'] ?? [])) as $person) {
            if (!is_array($person)) throw new RuntimeException('TVDB gaf ongeldige castgegevens.');
            $personId = $person['peopleId'] ?? null;
            if (!is_int($personId) || isset($cast[$personId]) || empty($person['personName'])) continue;
            $cast[$personId] = ['id' => $personId, 'name' => self::text($person['personName']),
                'role' => self::text($person['name'] ?? ''), 'type' => self::text($person['peopleType'] ?? ''),
                'image' => self::imageUrl($person['personImgURL'] ?? '')];
            if (count($cast) === 30) break;
        }
        $companies = [];
        foreach (['studios', 'production', 'distributor', 'networks'] as $kind) {
            foreach ($base['companies'][$kind] ?? [] as $company) {
                if (is_array($company) && isset($company['id'], $company['name'])) $companies[] = ['id' => $company['id'], 'name' => self::text($company['name']), 'type' => $kind];
            }
        }
        foreach (array_merge($base['studios'] ?? [], isset($base['originalNetwork']) ? [$base['originalNetwork']] : [],
            array_is_list($base['companies'] ?? []) ? ($base['companies'] ?? []) : []) as $company) {
            if (is_array($company) && isset($company['id'], $company['name'])) $companies[] = ['id' => $company['id'], 'name' => self::text($company['name']), 'type' => self::text($company['companyType']['companyTypeName'] ?? 'Studio / netwerk')];
        }
        $companies = array_values(array_column($companies, null, 'id'));
        $date = $episode !== null ? ($data['aired'] ?? '') : ($type === 'movie' ? ($base['first_release']['date'] ?? '') : ($base['firstAired'] ?? ''));
        $runtime = $episode !== null || $type === 'movie' ? ($data['runtime'] ?? null) : ($base['averageRuntime'] ?? null);
        return [
            'type' => $episode === null ? $type : 'episode', 'id' => $data['id'], 'parent_id' => $id,
            'title' => self::text($base['_translated_name'] ?? $base['name']),
            'episode_title' => $episode === null ? '' : self::text($data['_translated_name'] ?? $data['name']),
            'season' => $seasonNumber, 'episode' => $episode === null ? null : $episodeNumber, 'episode_missing' => $episodeMissing,
            'overview' => self::text($data['_translated_overview'] ?? $data['overview'] ?? '', 12000),
            'date' => self::text($date, 20),
            'runtime' => is_numeric($runtime) ? (int) $runtime : null,
            'genres' => array_slice(array_values(array_filter(array_map(static fn ($g): string => is_array($g) ? self::text($g['name'] ?? '') : '', $base['genres'] ?? []))), 0, 15),
            'poster' => $art['poster'], 'fanart' => $art['fanart'], 'still' => $episode === null ? '' : self::imageUrl($episode['image'] ?? ''),
            'people' => array_values($cast), 'companies' => array_slice($companies, 0, 20),
            'remote_ids' => $base['remoteIds'] ?? [],
        ];
    }

    public static function imageUrl(mixed $url): string
    {
        if (!is_string($url) || strlen($url) > 2000) return '';
        if (str_starts_with($url, '/banners/')) $url = 'https://artworks.thetvdb.com' . $url;
        $parts = parse_url($url);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['artworks.thetvdb.com', 'www.thetvdb.com', 'thetvdb.com'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            ? $url : '';
    }

    private static function text(mixed $value, int $length = 500): string
    {
        if (!is_string($value)) return '';
        if (preg_match('/\A.{0,' . $length . '}/us', $value, $match) !== 1) throw new RuntimeException('TVDB gaf ongeldige tekst.');
        return $match[0];
    }
}
