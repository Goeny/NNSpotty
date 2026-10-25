<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use RuntimeException;

/** TMDB (The Movie Database) lookups, normalized to the shared film/series metadata format. */
final class Tmdb
{
    public function __construct(private readonly Repository $repo, private readonly Gateway $api)
    {
    }

    public function search(string $query, string $type): array
    {
        $data = $this->cached('/search/' . ($type === 'movie' ? 'movie' : 'tv'),
            ['query' => $query, 'language' => 'en-US', 'include_adult' => 'false', 'page' => 1], 86400);
        $rows = $data['results'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('TMDB gaf geen geldige zoekresultaten.');
        $results = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $name = is_array($row) ? ($row[$type === 'movie' ? 'title' : 'name'] ?? null) : null;
            if (!is_array($row) || !is_int($row['id'] ?? null) || $row['id'] < 1 || !is_string($name)) {
                throw new RuntimeException('TMDB-zoekresultaat mist een geldig ID of titel.');
            }
            $names = [self::text($name)];
            $original = $row[$type === 'movie' ? 'original_title' : 'original_name'] ?? null;
            if (is_string($original) && $original !== $name) $names[] = self::text($original);
            $date = $row[$type === 'movie' ? 'release_date' : 'first_air_date'] ?? '';
            $results[] = ['id' => $row['id'], 'type' => $type, 'provider' => 'tmdb', 'name' => $names[0], 'names' => $names,
                'year' => is_string($date) && preg_match('/^\d{4}/', $date) ? substr($date, 0, 4) : ''];
        }
        return $results;
    }

    /** Find a TMDB movie/series ID via an external IMDb or TVDB identifier. */
    public function find(string $source, string $externalId, string $type): ?int
    {
        if (!in_array($source, ['imdb_id', 'tvdb_id'], true)) throw new InvalidArgumentException('Onbekende externe bron.');
        if (!preg_match($source === 'imdb_id' ? '/^tt\d{5,10}$/D' : '/^[1-9]\d{0,9}$/D', $externalId)) return null;
        $data = $this->cached('/find/' . $externalId, ['external_source' => $source], 604800, true);
        $rows = $data[$type === 'movie' ? 'movie_results' : 'tv_results'] ?? [];
        if (!is_array($rows) || count($rows) !== 1 || !is_int($rows[0]['id'] ?? null)) return null;
        return $rows[0]['id'];
    }

    public function details(string $type, int $id, array $release, string $language, bool $strict = false): array
    {
        if (!in_array($type, ['movie', 'series'], true) || $id < 1) throw new InvalidArgumentException('Ongeldige TMDB-keuze.');
        $country = $language === 'nl-NL' ? 'NL' : 'US';
        $videoLanguages = substr($language, 0, 2) . ',en,null';
        $path = ($type === 'movie' ? '/movie/' : '/tv/') . $id;
        $base = $this->cached($path, ['language' => $language, 'include_video_language' => $videoLanguages,
            'append_to_response' => $type === 'movie' ? 'credits,videos,release_dates,external_ids' : 'credits,videos,content_ratings,external_ids']);
        $title = $base[$type === 'movie' ? 'title' : 'name'] ?? null;
        if (($base['id'] ?? null) !== $id || !is_string($title)) throw new RuntimeException('TMDB-record komt niet overeen met de gekozen titel.');
        $overview = self::text($base['overview'] ?? '', 12000);
        if ($overview === '' && $language !== 'en-US') {
            $overview = self::text($this->cached($path, ['language' => 'en-US'])['overview'] ?? '', 12000);
        }
        $season = $release['season'] ?? null;
        $number = $release['episode'] ?? null;
        $episode = null;
        $episodeMissing = false;
        if ($type === 'series' && $season !== null && $number !== null) {
            $episodePath = $path . '/season/' . (int) $season . '/episode/' . (int) $number;
            $found = $this->cached($episodePath, ['language' => $language, 'include_video_language' => $videoLanguages,
                'append_to_response' => 'credits,videos'], optional: true);
            if ($found !== [] && (($found['season_number'] ?? null) !== $season || ($found['episode_number'] ?? null) !== $number || !is_int($found['id'] ?? null))) {
                throw new RuntimeException('TMDB-aflevering komt niet overeen met de spot.');
            }
            if ($found === []) {
                if ($strict || isset($release['input'])) throw new InvalidArgumentException('Geen aflevering met dit seizoen/nummer gevonden bij TMDB.');
                $episodeMissing = true;
            } else {
                $episode = $found;
                if (($episode['overview'] ?? '') === '' && $language !== 'en-US') {
                    $episode['overview'] = $this->cached($episodePath, ['language' => 'en-US'], optional: true)['overview'] ?? '';
                }
            }
        }
        $data = $episode ?? $base;
        $people = [];
        $sources = [$episode['credits']['guest_stars'] ?? [], $base['credits']['cast'] ?? [], $episode['credits']['crew'] ?? [],
            $base['created_by'] ?? [], $base['credits']['crew'] ?? []];
        foreach ($sources as $index => $list) {
            if (!is_array($list)) throw new RuntimeException('TMDB gaf ongeldige castgegevens.');
            foreach ($list as $person) {
                if (!is_array($person) || !is_int($person['id'] ?? null) || !is_string($person['name'] ?? null) || isset($people[$person['id']])) continue;
                $crew = $index >= 2;
                $job = $index === 3 ? 'Creator' : self::text($person['job'] ?? '');
                if ($crew && !in_array($job, ['Director', 'Creator', 'Screenplay', 'Writer'], true)) continue;
                $people[$person['id']] = ['id' => $person['id'], 'name' => self::text($person['name']),
                    'role' => $crew ? $job : self::text($person['character'] ?? ''), 'type' => $crew ? $job : 'Actor',
                    'image' => self::imageUrl($person['profile_path'] ?? null, 'w185')];
                if (count($people) === 30) break 2;
            }
        }
        $companies = [];
        foreach (array_merge($base['networks'] ?? [], $base['production_companies'] ?? []) as $company) {
            if (is_array($company) && is_int($company['id'] ?? null) && is_string($company['name'] ?? null)) {
                $companies[$company['id']] = ['id' => $company['id'], 'name' => self::text($company['name']), 'type' => 'Studio / netwerk'];
            }
        }
        $date = $episode !== null ? ($episode['air_date'] ?? '') : ($base[$type === 'movie' ? 'release_date' : 'first_air_date'] ?? '');
        $runtime = $episode !== null ? ($episode['runtime'] ?? null)
            : ($type === 'movie' ? ($base['runtime'] ?? null) : ($base['episode_run_time'][0] ?? null));
        $imdb = $base['external_ids']['imdb_id'] ?? $base['imdb_id'] ?? '';
        $tvdb = $base['external_ids']['tvdb_id'] ?? null;
        $link = ($type === 'movie' ? 'movie/' : 'tv/') . $id . ($episode === null ? '' : '/season/' . (int) $season . '/episode/' . (int) $number);
        $artwork = $this->artwork($path, $base);
        return [
            'provider' => 'tmdb', 'type' => $episode === null ? $type : 'episode', 'id' => $episode['id'] ?? $id, 'parent_id' => $id,
            'title' => self::text($title),
            'episode_title' => $episode === null ? '' : self::text($episode['name'] ?? ''),
            'season' => $season, 'episode' => $episode === null ? null : $number, 'episode_missing' => $episodeMissing,
            'requested_episode' => $number,
            'overview' => self::text($episode !== null ? ($episode['overview'] ?? '') : $overview, 12000),
            'date' => self::text($date, 20),
            'runtime' => is_int($runtime) && $runtime > 0 ? $runtime : null,
            'genres' => array_slice(array_values(array_filter(array_map(static fn ($g): string => is_array($g) ? self::text($g['name'] ?? '') : '', $base['genres'] ?? []))), 0, 15),
            'poster' => self::imageUrl($base['poster_path'] ?? null, 'w500'), 'fanart' => self::imageUrl($base['backdrop_path'] ?? null, 'w1280'),
            'still' => $episode === null ? '' : self::imageUrl($episode['still_path'] ?? null, 'w780'),
            ...$artwork,
            'people' => array_values($people), 'companies' => array_slice(array_values($companies), 0, 20),
            'remote_ids' => [],
            'imdb_id' => is_string($imdb) && preg_match('/^tt\d{5,10}$/D', $imdb) ? $imdb : '',
            'tvdb_id' => is_int($tvdb) && $tvdb > 0 ? $tvdb : null,
            'score' => self::score($base), 'episode_score' => $episode === null ? null : self::score($episode),
            'certification' => self::certification($type, $base, $country),
            'trailers' => self::trailers(array_merge($episode['videos']['results'] ?? [], $base['videos']['results'] ?? []), substr($language, 0, 2)),
            'links' => ['tmdb' => $link],
        ];
    }

    private function artwork(string $path, array $base): array
    {
        $artwork = [
            'posters' => array_filter([self::imageUrl($base['poster_path'] ?? null, 'original')]),
            'backdrops' => array_filter([self::imageUrl($base['backdrop_path'] ?? null, 'original')]),
        ];
        try {
            // No language filter: the gallery includes all available artwork languages.
            $data = $this->cached($path . '/images', []);
            if (!is_int($data['id'] ?? null) || (string) $data['id'] !== basename($path)) {
                throw new RuntimeException('TMDB-artwork hoort niet bij de gekozen titel.');
            }
            foreach (['posters', 'backdrops'] as $field) {
                if (!is_array($data[$field] ?? null) || !array_is_list($data[$field])) {
                    throw new RuntimeException('TMDB gaf geen geldige artworklijst.');
                }
                foreach ($data[$field] as $image) {
                    if (!is_array($image)) throw new RuntimeException('TMDB gaf ongeldige artworkgegevens.');
                    $url = self::imageUrl($image['file_path'] ?? null, 'original');
                    if ($url !== '') $artwork[$field][] = $url;
                }
            }
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            error_log('TMDB-artwork: ' . $exception->getMessage());
            $artwork['artwork_warning'] = 'Extra TMDB-afbeeldingen konden niet worden opgehaald. De metadata blijft beschikbaar; probeer over vijf minuten opnieuw.';
        }
        foreach (['posters', 'backdrops'] as $field) $artwork[$field] = array_values(array_unique($artwork[$field]));
        return $artwork;
    }

    private function cached(string $path, array $query, int $seconds = 604800, bool $optional = false): array
    {
        $key = 'tmdb:' . $path . '?' . http_build_query($query);
        $cached = $this->repo->cached($key);
        if ($cached !== null) return $cached;
        if (!$this->repo->lock('provider:' . $key)) throw new RuntimeException('TMDB-data wordt al opgehaald. Probeer later opnieuw.');
        try {
            $data = $this->repo->cached($key);
            if ($data !== null) return $data;
            $data = $this->api->get($path, $query);
            if ($data === null && !$optional) throw new RuntimeException('TMDB-record niet beschikbaar.');
            $data ??= [];
            $this->repo->cache($key, $data, $seconds);
            return $data;
        } finally {
            $this->repo->unlock('provider:' . $key);
        }
    }

    private static function score(array $data): ?array
    {
        $average = $data['vote_average'] ?? null;
        $votes = $data['vote_count'] ?? null;
        if (!is_int($votes) || $votes < 1 || (!is_float($average) && !is_int($average)) || $average < 0 || $average > 10) return null;
        return ['value' => round((float) $average, 1), 'votes' => $votes];
    }

    private static function certification(string $type, array $base, string $country): array
    {
        foreach (array_unique([$country, 'US']) as $code) {
            foreach ($base[$type === 'movie' ? 'release_dates' : 'content_ratings']['results'] ?? [] as $row) {
                if (!is_array($row) || ($row['iso_3166_1'] ?? null) !== $code) continue;
                $values = $type === 'movie' ? array_column(is_array($row['release_dates'] ?? null) ? $row['release_dates'] : [], 'certification') : [$row['rating'] ?? ''];
                foreach ($values as $value) {
                    if (is_string($value) && preg_match('/^[\p{L}\p{N} +\/-]{1,12}$/u', trim($value))) return ['country' => $code, 'value' => trim($value)];
                }
            }
        }
        return [];
    }

    private static function trailers(array $videos, string $language): array
    {
        $trailers = [];
        foreach ($videos as $video) {
            if (!is_array($video) || !in_array($video['type'] ?? null, ['Trailer', 'Teaser'], true)) continue;
            $site = $video['site'] ?? null;
            $key = $video['key'] ?? null;
            if (!is_string($key) || !(($site === 'YouTube' && preg_match('/^[A-Za-z0-9_-]{6,20}$/D', $key))
                || ($site === 'Vimeo' && preg_match('/^\d{1,12}$/D', $key)))) continue;
            $rank = ($video['type'] === 'Trailer' ? 0 : 4) + (($video['official'] ?? false) === true ? 0 : 2) + (($video['iso_639_1'] ?? '') === $language ? 0 : 1);
            $trailers[$site . ':' . $key] ??= ['site' => $site, 'key' => $key, 'name' => self::text($video['name'] ?? 'Trailer', 120), 'rank' => $rank];
        }
        usort($trailers, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        return array_map(static fn (array $trailer): array => array_diff_key($trailer, ['rank' => true]), array_slice($trailers, 0, 3));
    }

    public static function imageUrl(mixed $path, string $size): string
    {
        return is_string($path) && preg_match('~^/[A-Za-z0-9_-]{1,100}\.(?:jpe?g|png|webp|svg)$~D', $path)
            ? 'https://image.tmdb.org/t/p/' . $size . $path : '';
    }

    private static function text(mixed $value, int $length = 500): string
    {
        if (!is_string($value)) return '';
        if (preg_match('/\A.{0,' . $length . '}/us', $value, $match) !== 1) throw new RuntimeException('TMDB gaf ongeldige tekst.');
        return $match[0];
    }
}
