<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use RuntimeException;

final class Service
{
    public function __construct(private readonly Repository $repo, private readonly Gateway $api) {}

    public static function credit(array $credit): string
    {
        $name = '';
        foreach ($credit as $part) {
            if (!is_array($part)) throw new RuntimeException('Ongeldige MusicBrainz-artiestvermelding.');
            $name .= self::text($part['name'] ?? $part['artist']['name'] ?? '') . self::text($part['joinphrase'] ?? '');
        }
        return $name;
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value)) return '';
        if (preg_match('/\A.{0,4000}/us', $value, $match) !== 1) throw new RuntimeException('MusicBrainz gaf ongeldige tekst.');
        return $match[0];
    }

    private function cached(string $type, ?string $id, array $query = []): ?array
    {
        $key = $type . '/' . ($id ?? '') . '?' . http_build_query($query);
        $cached = $this->repo->cached($key);
        if ($cached !== null) return $cached['data'];
        if (!$this->repo->lock('provider:' . $key)) throw new RuntimeException('MusicBrainz-data wordt al opgehaald; probeer later opnieuw.');
        try {
            $cached = $this->repo->cached($key);
            if ($cached !== null) return $cached['data'];
            $data = $this->api->get($type, $id, $query);
            $this->repo->cache($key, ['data' => $data], $id === null || $data === null ? 86400 : 604800);
            return $data;
        } finally {
            $this->repo->unlock('provider:' . $key);
        }
    }

    public function search(string $query, string $type): array
    {
        if (strlen(trim($query)) < 2 || strlen($query) > 200 || !in_array($type, ['artist', 'release-group', 'release'], true)) {
            throw new \InvalidArgumentException('Gebruik een zoekterm van 2 tot 200 tekens en een geldig muziektype.');
        }
        $parsed = $type === 'release-group' ? Release::parseTitle($query) : null;
        if ($parsed !== null && $parsed['auto'] && $parsed['type'] !== 'artist') {
            // Broaden discovery, not identity: automatic selection still checks the full live title.
            $title = trim(preg_replace('/\s*[\[(](?:live|concert)\b[^)\]]*[)\]]$/i', '', $parsed['title']));
            if ($title === '') $title = $parsed['title'];
            $terms = 'artist:' . self::quoted($parsed['artist']) . ' AND releasegroup:' . self::quoted($title);
            $parameters = ['query' => $terms, 'limit' => 25];
        } else {
            $parameters = ['query' => trim($query), 'dismax' => 'true', 'limit' => 25];
        }
        $data = $this->cached($type, null, $parameters);
        $rows = $data[match ($type) { 'artist' => 'artists', 'release' => 'releases', default => 'release-groups' }] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('MusicBrainz gaf geen geldige zoekresultaten.');
        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !Release::mbid($row['id'] ?? '') || !is_string($row[$type === 'artist' ? 'name' : 'title'] ?? null)) {
                throw new RuntimeException('Ongeldig MusicBrainz-zoekresultaat.');
            }
            $name = self::text($row[$type === 'artist' ? 'name' : 'title']);
            $names = [$name];
            foreach ($row['aliases'] ?? [] as $alias) if (is_string($alias['name'] ?? null)) $names[] = self::text($alias['name']);
            $artist = $type === 'artist' ? '' : self::credit($row['artist-credit'] ?? []);
            $notes = [self::text($row['disambiguation'] ?? '')];
            if ($type === 'release') {
                $notes[] = self::text($row['country'] ?? '');
                $notes[] = self::text($row['status'] ?? '');
                foreach ($row['media'] ?? [] as $medium) $notes[] = self::text($medium['format'] ?? '');
                foreach ($row['label-info'] ?? [] as $label) {
                    $notes[] = trim(self::text($label['label']['name'] ?? '') . ' ' . self::text($label['catalog-number'] ?? ''));
                }
            }
            $results[] = ['id' => $row['id'], 'type' => $type, 'name' => $name, 'names' => $names, 'artist' => $artist,
                'year' => substr(self::text($row['first-release-date'] ?? $row['date'] ?? ''), 0, 4),
                'note' => implode(' / ', array_unique(array_filter($notes))),
                'kind' => self::text($row['primary-type'] ?? $row['type'] ?? '')];
        }
        return $results;
    }

    private static function quoted(string $value): string
    {
        return '"' . preg_replace('/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $value) . '"';
    }

    public function lookup(array $spot): array
    {
        $release = Release::parse($spot);
        if ($release === null) return ['state' => 'unsupported'];
        $old = $this->repo->match($spot['id']);
        $hash = Release::matchSignature($spot);
        if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
        if (!$this->repo->lock('spot:' . $spot['id'])) return ['state' => 'busy'];
        try {
            $old = $this->repo->match($spot['id']);
            if ($old !== null && $old['input_hash'] === $hash && $old['fresh'] && $old['result_json'] !== null) return $old['result_json'];
            $choice = $old !== null && ($old['choice_json']['input'] ?? '') === Release::signature($spot) ? $old['choice_json'] : null;
            if ($choice['blocked'] ?? false) {
                $result = ['state' => 'blocked'];
            } else {
                $candidates = [];
                if ($choice === null && $release['auto']) $candidates = $this->search($release['query'], $release['type']);
                $selected = $choice ?? Release::select($release, $candidates);
                $result = $selected === null
                    ? ['state' => $candidates === [] && $release['auto'] ? 'unmatched' : 'ambiguous']
                    : ['state' => 'matched', 'manual' => $choice !== null, 'metadata' => $this->details($selected['type'], $selected['id'])];
            }
            $this->repo->result($spot, $result, $result['state'] === 'matched' ? 604800 : 86400);
            return $result;
        } catch (\PDOException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            error_log('MusicBrainz metadata: ' . $exception->getMessage());
            $result = ['state' => 'error'];
            $this->repo->result($spot, $result, 300);
            return $result;
        } finally {
            $this->repo->unlock('spot:' . $spot['id']);
        }
    }

    public function choose(array $spot, ?array $choice): void
    {
        if (Release::parse($spot) === null) throw new \InvalidArgumentException('Geen muziekspot.');
        if ($choice !== null && !($choice['blocked'] ?? false)) {
            if (!in_array($choice['type'] ?? '', ['artist', 'release-group', 'release'], true)
                || !is_string($choice['id'] ?? null) || !Release::mbid($choice['id'])) throw new \InvalidArgumentException('Ongeldige MusicBrainz-keuze.');
            $this->details($choice['type'], $choice['id']);
        }
        if ($choice !== null) $choice['input'] = Release::signature($spot);
        if (!$this->repo->lock('spot:' . $spot['id'])) throw new RuntimeException('Muziekmatch wordt verwerkt; probeer later opnieuw.');
        try {
            $this->repo->choose($spot, $choice);
        } finally {
            $this->repo->unlock('spot:' . $spot['id']);
        }
    }

    private function details(string $type, string $id): array
    {
        $inc = match ($type) {
            'artist' => 'genres+aliases',
            'release-group' => 'artist-credits+genres',
            'release' => 'artist-credits+recordings+release-groups+labels+genres',
            default => throw new \InvalidArgumentException('Ongeldig muziektype.'),
        };
        $data = $this->cached($type, $id, ['inc' => $inc]);
        if ($data === null || ($data['id'] ?? '') !== $id || !is_string($data[$type === 'artist' ? 'name' : 'title'] ?? null)) {
            throw new RuntimeException('MusicBrainz-record ontbreekt of wijkt af van de gekozen identiteit.');
        }
        $genres = [];
        foreach ($data['genres'] ?? [] as $genre) if (is_string($genre['name'] ?? null)) $genres[] = self::text($genre['name']);
        $tracks = [];
        $trackCount = 0;
        $duration = 0;
        $completeDuration = true;
        foreach ($data['media'] ?? [] as $disc) {
            $trackCount += (int) ($disc['track-count'] ?? count($disc['tracks'] ?? []));
            foreach ($disc['tracks'] ?? [] as $track) {
                if (count($tracks) >= 200) break;
                $length = $track['length'] ?? $track['recording']['length'] ?? null;
                if (!is_int($length) || $length < 0) $completeDuration = false;
                else $duration += $length;
                $tracks[] = ['disc' => (int) ($disc['position'] ?? 1), 'number' => self::text($track['number'] ?? ''),
                    'title' => self::text($track['title'] ?? $track['recording']['title'] ?? ''),
                    'artist' => self::credit($track['artist-credit'] ?? $track['recording']['artist-credit'] ?? []),
                    'length' => is_int($length) && $length >= 0 ? $length : null];
            }
        }
        $labels = [];
        foreach ($data['label-info'] ?? [] as $label) if (is_string($label['label']['name'] ?? null)) $labels[] = self::text($label['label']['name']);
        $cover = $type === 'artist' ? '' : 'https://coverartarchive.org/' . $type . '/' . $id . '/front-500';
        if ($type === 'release' && !($data['cover-art-archive']['front'] ?? false)) $cover = '';
        return ['provider' => 'musicbrainz', 'type' => $type, 'id' => $id,
            'title' => self::text($data[$type === 'artist' ? 'name' : 'title']),
            'artist' => $type === 'artist' ? '' : self::credit($data['artist-credit'] ?? []),
            'date' => self::text($data['first-release-date'] ?? $data['date'] ?? $data['life-span']['begin'] ?? ''),
            'end' => self::text($data['life-span']['end'] ?? ''), 'ended' => (bool) ($data['life-span']['ended'] ?? false),
            'kind' => self::text($data['primary-type'] ?? $data['type'] ?? $data['release-group']['primary-type'] ?? ''),
            'country' => self::text($data['country'] ?? ''), 'note' => self::text($data['disambiguation'] ?? ''),
            'genres' => array_slice($genres, 0, 15), 'labels' => array_values(array_unique($labels)),
            'cover' => $cover, 'tracks' => $tracks, 'track_count' => $trackCount,
            'duration' => $completeDuration && $tracks !== [] && count($tracks) === $trackCount ? $duration : null];
    }
}
