<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Service
{
    public function __construct(private readonly Repository $repo, private readonly Gateway $api, private readonly ?Gateway $tmdbApi = null)
    {
    }

    public static function signature(array $spot, string $language): string
    {
        return hash('sha256', json_encode([$spot['title'], $spot['category'], $spot['subcategories'], $language], JSON_THROW_ON_ERROR));
    }

    public static function matchSignature(array $spot, string $profile): string
    {
        return self::signature($spot, $profile . ':release-v4');
    }

    public function search(string $query, string $type, ?string $provider = null): array
    {
        $query = trim($query);
        if (strlen($query) < 2 || strlen($query) > 200 || !in_array($type, ['movie', 'series'], true)) {
            throw new InvalidArgumentException('Gebruik een titel van 2 tot 200 tekens en kies film of serie.');
        }
        $provider ??= $this->repo->effectiveProvider($type) ?? 'tvdb';
        if (!in_array($provider, ['tvdb', 'tmdb'], true)) throw new InvalidArgumentException('Kies TMDB of TVDB.');
        if (!$this->repo->providerEnabled($provider)) throw new InvalidArgumentException(strtoupper($provider) . ' is niet ingeschakeld.');
        if ($provider === 'tmdb') return $this->tmdb()->search($query, $type);
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
            $results[] = ['id' => (int) $id, 'type' => $type, 'provider' => 'tvdb', 'name' => self::text($row['name']),
                'names' => array_slice($names, 0, 100), 'year' => self::text($row['year'] ?? '', 4)];
        }
        return $results;
    }

    public function lookup(array $spot): array
    {
        $release = Release::parse($spot);
        if ($release === null) return ['state' => 'unsupported'];
        $hash = self::matchSignature($spot, $this->repo->profile());
        $old = $this->repo->match($spot['id']);
        if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
        if (!$this->repo->lock($spot['id'])) return ['state' => 'busy'];
        try {
            $old = $this->repo->match($spot['id']);
            if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
            // A manual choice survives language changes, but not changed spot input.
            $choice = $old !== null ? $old['choice_json'] : null;
            if ($choice !== null && ($choice['input'] ?? '') !== self::signature($spot, '')) $choice = null;
            // A manual choice for a provider that is no longer enabled falls back to automatic matching.
            if ($choice !== null && !($choice['blocked'] ?? false) && !$this->repo->providerEnabled($choice['provider'] ?? 'tvdb')) $choice = null;
            if (($choice['blocked'] ?? false) === true) {
                $result = ['state' => 'blocked'];
            } else {
                $provider = $choice !== null ? ($choice['provider'] ?? 'tvdb') : $this->repo->effectiveProvider($release['type']);
                if ($provider === null) throw new RuntimeException('Geen film-/seriemetadataprovider ingeschakeld.');
                $providers = [$provider];
                $secondary = $provider === 'tvdb' ? 'tmdb' : 'tvdb';
                if ($choice === null && $this->repo->providerEnabled($secondary)) $providers[] = $secondary;
                $metadata = null;
                $found = false;
                $warnings = [];
                $failed = [];
                foreach ($providers as $attempt) {
                    try {
                        $selected = $choice;
                        if ($selected === null) {
                            $candidates = $this->search($release['query'], $release['type'], $attempt);
                            $found = $found || $candidates !== [];
                            $selected = Release::select($release, $candidates);
                        }
                        if ($selected === null) continue;
                        $details = $this->details($attempt, $selected['type'], $selected['id'], $choice ?? $release);
                        // Keep the preferred series if neither provider knows the requested episode.
                        if ($metadata === null || !($details['episode_missing'] ?? false)) $metadata = $details;
                        if ($choice !== null || !($details['episode_missing'] ?? false)) break;
                    } catch (\PDOException $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        error_log('Metadata ophalen via ' . strtoupper($attempt) . ': ' . $exception->getMessage());
                        $warnings[] = strtoupper($attempt) . ' kon niet worden opgehaald. Controleer de providertoegang en serverlog; probeer over vijf minuten opnieuw.';
                        $failed[] = $attempt;
                    }
                }
                if ($metadata === null) {
                    $result = ['state' => $warnings !== [] ? 'error' : ($found ? 'ambiguous' : 'unmatched'),
                        'query' => $release['query'], 'provider' => $provider, 'providers' => $providers];
                } else {
                    $matchedProvider = $metadata['provider'];
                    $other = $matchedProvider === 'tvdb' ? 'tmdb' : 'tvdb';
                    $result = ['state' => 'matched', 'manual' => $choice !== null,
                        'metadata' => in_array($other, $failed, true) ? $metadata : $this->complement($matchedProvider, $metadata, $warnings)];
                    if ($matchedProvider !== $provider) $result['fallback_from'] = $provider;
                }
                if ($warnings !== []) $result['warnings'] = $warnings;
            }
            $this->repo->result($spot['id'], $hash, $result, !empty($result['warnings']) ? 300 : ($result['state'] === 'matched' ? 604800 : 86400));
            return $result;
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log('Film-/seriemetadata: ' . $exception->getMessage());
            $result = ['state' => 'error', 'message' => 'Metadata kon niet worden opgehaald. De oorspronkelijke spot blijft beschikbaar. Probeer over vijf minuten opnieuw.'];
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
            $choice['provider'] ??= 'tvdb';
            if (!in_array($choice['type'] ?? '', ['movie', 'series'], true) || !is_int($choice['id'] ?? null) || $choice['id'] < 1
                || !in_array($choice['provider'], ['tvdb', 'tmdb'], true)) {
                throw new InvalidArgumentException('Ongeldige metadatakeuze.');
            }
            if (!$this->repo->providerEnabled($choice['provider'])) throw new InvalidArgumentException(strtoupper($choice['provider']) . ' is niet ingeschakeld.');
            // Validate the selected record (and episode, if supplied) before replacing a shared match.
            $choice['strict_episode'] = true;
            $this->details($choice['provider'], $choice['type'], $choice['id'], $choice);
        }
        if ($choice !== null) $choice['input'] = self::signature($spot, '');
        if (!$this->repo->lock($spot['id'])) throw new RuntimeException('De metadatamatch wordt al verwerkt. Probeer opnieuw.');
        try {
            $this->repo->choose($spot['id'], self::matchSignature($spot, $this->repo->profile()), $choice);
        } finally {
            $this->repo->unlock($spot['id']);
        }
    }

    private function tmdb(): Tmdb
    {
        return new Tmdb($this->repo, $this->tmdbApi ?? new TmdbClient($this->repo));
    }

    private function details(string $provider, string $type, int $id, array $release): array
    {
        return $provider === 'tmdb'
            ? $this->tmdb()->details($type, $id, $release, $this->repo->tmdbSettings()['language'], ($release['strict_episode'] ?? false) === true)
            : $this->tvdbDetails($type, $id, $this->repo->settings()['language'], $release);
    }

    /** Fill gaps from the other enabled provider, linked via external IDs only (never by title). */
    private function complement(string $primary, array $metadata, array &$warnings): array
    {
        $secondary = $primary === 'tvdb' ? 'tmdb' : 'tvdb';
        if (!$this->repo->providerEnabled($secondary)) return $metadata;
        try {
            $kind = $metadata['type'] === 'movie' ? 'movie' : 'series';
            $context = $metadata['type'] === 'episode' ? ['season' => $metadata['season'], 'episode' => $metadata['episode']] : [];
            $imdb = $metadata['imdb_id'] ?? '';
            $id = null;
            if ($secondary === 'tmdb') {
                if ($kind === 'series') $id = $this->tmdb()->find('tvdb_id', (string) $metadata['parent_id'], 'series');
                if ($id === null && $imdb !== '') $id = $this->tmdb()->find('imdb_id', $imdb, $kind);
                if ($id === null) return $metadata;
                $other = $this->tmdb()->details($kind, $id, $context, $this->repo->tmdbSettings()['language']);
            } else {
                $id = $kind === 'series' ? ($metadata['tvdb_id'] ?? null) : null;
                if ($id === null && $imdb !== '') $id = $this->tvdbRemote($imdb, $kind);
                if ($id === null) return $metadata;
                $other = $this->tvdbDetails($kind, $id, $this->repo->settings()['language'], $context);
            }
            return self::merge($metadata, $other);
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log('Metadata aanvullen via ' . strtoupper($secondary) . ': ' . $exception->getMessage());
            $warnings[] = 'Aanvullen via ' . strtoupper($secondary) . ' is mislukt. De gevonden metadata blijft beschikbaar; probeer over vijf minuten opnieuw.';
            return $metadata;
        }
    }

    private static function merge(array $primary, array $other): array
    {
        $used = false;
        $fields = ['poster', 'fanart', 'genres', 'companies', 'score', 'certification', 'trailers'];
        // Episode-level data only complements the very same episode.
        if ($primary['type'] === $other['type']) array_push($fields, 'overview', 'date', 'runtime', 'people', 'still', 'episode_title', 'episode_score');
        foreach ($fields as $field) {
            if (empty($primary[$field]) && !empty($other[$field])) {
                $primary[$field] = $other[$field];
                $used = true;
            }
        }
        if (($primary['imdb_id'] ?? '') === '') $primary['imdb_id'] = $other['imdb_id'] ?? '';
        if ($used) $primary['links'] = ($primary['links'] ?? []) + ($other['links'] ?? []);
        return $primary;
    }

    private function tvdbRemote(string $imdb, string $type): ?int
    {
        if (!preg_match('/^tt\d{5,10}$/D', $imdb)) return null;
        $found = [];
        foreach ($this->cached('/search/remoteid/' . $imdb, optional: true) as $row) {
            $id = is_array($row) ? ($row[$type]['id'] ?? null) : null;
            if (is_int($id) && $id > 0) $found[$id] = true;
        }
        return count($found) === 1 ? array_key_first($found) : null;
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

    private function tvdbDetails(string $type, int $id, string $language, array $release): array
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
        $imdb = '';
        foreach ($base['remoteIds'] ?? [] as $remote) {
            if (is_array($remote) && is_string($remote['id'] ?? null) && preg_match('/^tt\d{5,10}$/D', $remote['id'])
                && stripos((string) ($remote['sourceName'] ?? ''), 'imdb') !== false) {
                $imdb = $remote['id'];
                break;
            }
        }
        return [
            'provider' => 'tvdb',
            'type' => $episode === null ? $type : 'episode', 'id' => $data['id'], 'parent_id' => $id,
            'title' => self::text($base['_translated_name'] ?? $base['name']),
            'episode_title' => $episode === null ? '' : self::text($data['_translated_name'] ?? $data['name']),
            'season' => $seasonNumber, 'episode' => $episode === null ? null : $episodeNumber, 'episode_missing' => $episodeMissing,
            'requested_episode' => $episodeNumber,
            'overview' => self::text($data['_translated_overview'] ?? $data['overview'] ?? '', 12000),
            'date' => self::text($date, 20),
            'runtime' => is_numeric($runtime) ? (int) $runtime : null,
            'genres' => array_slice(array_values(array_filter(array_map(static fn ($g): string => is_array($g) ? self::text($g['name'] ?? '') : '', $base['genres'] ?? []))), 0, 15),
            'poster' => $art['poster'], 'fanart' => $art['fanart'], 'still' => $episode === null ? '' : self::imageUrl($episode['image'] ?? ''),
            'people' => array_values($cast), 'companies' => array_slice($companies, 0, 20),
            'remote_ids' => $base['remoteIds'] ?? [],
            'imdb_id' => $imdb, 'tvdb_id' => $type === 'series' ? $id : null,
            'score' => null, 'episode_score' => null, 'certification' => [], 'trailers' => [],
            'links' => ['tvdb' => ($episode !== null ? 'episodes/' : ($type === 'movie' ? 'movies/' : 'series/')) . (int) $data['id']],
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
