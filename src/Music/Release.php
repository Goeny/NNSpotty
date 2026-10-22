<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use NnSpotty\Metadata\Release as Titles;

final class Release
{
    public static function mbid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id) === 1;
    }

    public static function signature(array $spot): string
    {
        return hash('sha256', json_encode([$spot['title'], $spot['category'], $spot['subcategories'], 'music-v1'], JSON_THROW_ON_ERROR));
    }

    public static function matchSignature(array $spot): string
    {
        return hash('sha256', self::signature($spot) . ':discovery-v4:artist-id-v1');
    }

    private const VARIOUS = ['va', 'various', 'variousartists', 'variousartist', 'diverseartiesten', 'diverseartiest',
        'diversen', 'diverse', 'verschillendeartiesten', 'divartiesten', 'artistesdivers', 'variosartistas',
        'verschiedeneinterpreten', 'verschiedenekunstler'];

    public static function various(string $name): bool
    {
        return in_array(Titles::normalized($name), self::VARIOUS, true);
    }

    /** Comparison key that treats "vol.2"/"Volume 2" and "&"/"and" as equal. */
    public static function key(string $text): string
    {
        $text = preg_replace('/\b(?:vol(?:ume)?|deel|part|pt)\b\.?\s*(\d+)/iu', ' vol $1', $text) ?? $text;
        $text = preg_replace('/\s+(?:&|\+|and)\s+/iu', ' and ', $text) ?? $text;
        return Titles::normalized($text);
    }

    private static function named(string $target, array $names): bool
    {
        $key = self::key($target);
        if ($key === '') return false;
        foreach ($names as $name) if (is_string($name) && self::key($name) === $key) return true;
        return false;
    }

    public static function parse(array $spot): ?array
    {
        if ($spot['category'] !== 'Geluid') return null;
        foreach ($spot['subcategories'] as $code) {
            if (preg_match('/^2z0?[23]$/D', strtolower($code))) return null;
        }
        return self::parseTitle($spot['title']);
    }

    public static function parseTitle(string $title): ?array
    {
        $title = trim(str_replace(['_', '–', '—'], [' ', '-', '-'], $title));
        $sourceYear = null;
        if (preg_match('/^((?:19|20)\d{2})\s*-\s*(.+\s+-\s+.+)$/u', $title, $prefix)) {
            $sourceYear = $prefix[1];
            $title = $prefix[2];
        }
        // "Artist - YEAR - Album" carries the release year between artist and album.
        $middleYear = null;
        if (preg_match('/^(.+?\S)\s+-\s+((?:19|20)\d{2})\s+-\s+(\S.*)$/u', $title, $middle)) {
            $middleYear = $middle[2];
            $title = $middle[1] . ' - ' . $middle[3];
        }
        $title = preg_replace('/^\s*\d{1,2}\.\s+(?=\S)/', '', $title);
        $physical = preg_match('/[\[(][^)\]]*\b(?:Vinyl|LP)\b|\bCDRIP\b|\b(?:Vinyl|LP)\b(?=\s*(?:$|[-)\]]|\d+\s*[-.]?\s*bit\b|FLAC\b|WAVPACK\b))/i', $title) === 1;
        // A scene year can follow the audio suffix; capture it before removing that suffix.
        $sceneYear = preg_match('/-((?:19|20)\d{2})(?:-[A-Z0-9]+)?$/i', $title, $scene) ? $scene[1] : null;
        // Drop the "-YEAR-GROUP" scene suffix when an artist/album pair remains in front of it.
        $withoutGroup = preg_replace('/-(?:19|20)\d{2}-[A-Z0-9]+$/i', '', $title);
        if ($withoutGroup !== $title && str_contains($withoutGroup, '-')) $title = $withoutGroup;
        $title = preg_replace_callback('/\s*(?:\[[^\]]*\]|\([^)]*\))/', static function (array $match): string {
            $technical = preg_match('/\b(?:Vinyl|LP|FLAC|MP3|WAVPACK|DTS|kHz|Hi[- ]?Res|HRA|\d+\s*CDS?)\b|\b(?:16|24|32)\s*[- ]?bit\b/i', $match[0]) === 1;
            $identity = preg_match('/\b(?:live|remaster(?:ed)?|deluxe|edition|mix|version)\b/i', $match[0]) === 1;
            return $technical && !$identity ? '' : $match[0];
        }, $title);
        preg_match_all('/(?:\s+in\s+)?\b(?:FLAC|MP3|AAC|ALAC|APE|WAVPACK|WAV|WMA|OGG|DSD|SACD|WEB|CDRIP|Hi[- ]?Res|HRA|\d+\s*x\s*CD|\d+CDS?|DTS(?:[- .]?HD)?|(?:16|24|32)\s*[- .]?\s*bit|320\s*kbps|(?:LP|Vinyl)(?=\s*(?:$|-|\d+\s*[-.]?\s*bit\b|FLAC\b|WAVPACK\b)))\b/i', $title, $technical, PREG_OFFSET_CAPTURE);
        foreach ($technical[0] as [, $offset]) {
            $before = substr($title, 0, $offset);
            if (substr_count($before, '(') !== substr_count($before, ')') || substr_count($before, '[') !== substr_count($before, ']')) continue;
            $parts = preg_split('/\s+-\s+/', $before, 2);
            if (count($parts) === 1) $parts = explode('-', $before, 2);
            if (isset($parts[1]) && self::cleanEdges($parts[1]) === '') continue;
            $title = $before;
            break;
        }
        $artistOnly = preg_match('/\b(?:discography|discografie|discographie)\b/i', $title) === 1;
        if ($artistOnly) $title = preg_split('/\b(?:discography|discografie|discographie)\b/i', $title, 2)[0];
        if ($artistOnly) $title = preg_replace('/[ .(-]*(?:19|20)\d{2}\s*-\s*(?:19|20)\d{2}[ .)-]*$/', '', $title);
        $title = self::cleanEdges($title);
        $year = $sceneYear ?? $middleYear;
        if (preg_match('/(?:[\s.(-]+)((?:19|20)\d{2})(?:[)\]\s.-]*)$/', $title, $match, PREG_OFFSET_CAPTURE)) {
            $before = substr($title, 0, $match[0][1]);
            $beforeParts = preg_split('/\s+-\s+/', $before, 2);
            if (count($beforeParts) === 1) $beforeParts = explode('-', $before, 2);
            $hasAlbum = isset($beforeParts[1]) && self::cleanEdges($beforeParts[1]) !== '';
            if (($artistOnly || $hasAlbum) && substr_count($before, '(') === substr_count($before, ')')
                && substr_count($before, '[') === substr_count($before, ']')) {
                $year = $match[1][0];
                $title = $before;
            } elseif (!$hasAlbum) {
                $year = null;
            }
        }
        $parts = preg_split('/\s+-\s+/', self::cleanEdges($title), 2);
        if (count($parts) === 1 && !$artistOnly && str_contains($title, '-')) $parts = explode('-', self::cleanEdges($title), 2);
        $artist = self::cleanEdges(str_replace('.', ' ', $parts[0]));
        $album = !$artistOnly && isset($parts[1]) ? self::cleanEdges(str_replace('.', ' ', $parts[1])) : '';
        // Edition labels are not part of an album-group title; live/location qualifiers are.
        $edition = false;
        $kind = null;
        if (preg_match('/^(.+?\S)\s*(?:-\s*|[(\[]\s*)(EP|Single)\s*[)\]]?$/iu', $album, $type)
            || preg_match('/^(.+?\S)\s+(EP)$/u', $album, $type)) {
            $album = self::cleanEdges($type[1]);
            $kind = strcasecmp($type[2], 'EP') === 0 ? 'EP' : 'Single';
        }
        if (preg_match('/^(.+?\S)(?:\s*-\s*|\s+)?[(\[]([^)\]]*)[)\]]$/u', $album, $label)
            && preg_match('/\b(?:deluxe|anniversary|expanded|remaster(?:ed)?|edition|reissue|bonus|special|collector\'?s|legacy|jubileum|editie|uitgave)\b/i', $label[2])
            && !preg_match('/\b(?:live|concert|unplugged|acoustic|session|mix|version)\b/i', $label[2])) {
            $album = self::cleanEdges($label[1]);
            $edition = true;
        }
        $deluxe = '/(?:\s+|[(\[])(?:DeLuxe|Super\s+Deluxe)(?:\s+Edition)?[)\]\s.]*$/i';
        if (preg_match($deluxe, $album) === 1) {
            $album = self::cleanEdges(preg_replace($deluxe, '', $album));
            $edition = true;
        }
        if (self::various($artist)) {
            $artist = 'Various Artists';
        } elseif ($album !== '' && self::various($album)) {
            // "Compilation - Various Artists": the title comes first.
            [$artist, $album] = ['Various Artists', $artist];
        }
        $explicitArtist = preg_match('/^(?:artist|artiest)\s*:\s*(.+)$/iu', $artist, $artistMatch) === 1;
        if ($explicitArtist) $artist = trim($artistMatch[1]);
        $artistOnly = $artistOnly || $explicitArtist;
        if (strlen($artist) < 2 || strlen($artist) > 200 || strlen($album) > 200) return null;
        if (!$artistOnly && $album === '') return ['type' => 'release-group', 'artist' => '', 'title' => $artist, 'year' => $year, 'auto' => false, 'query' => $artist];
        return ['type' => $artistOnly ? 'artist' : 'release-group', 'artist' => $artist,
            'title' => $album, 'year' => $physical || $sourceYear !== null ? null : $year,
            // Scene/edition years describe this upload or reissue; the album group may be older.
            'year_exact' => $sceneYear === null && !$edition, 'kind' => $kind,
            'source_year' => $sourceYear ?? $year, 'auto' => $artistOnly || $album !== '',
            'query' => $artistOnly ? $artist : $artist . ' - ' . $album];
    }

    private static function cleanEdges(string $text): string
    {
        $text = trim($text, " \t.-");
        foreach (['(' => ')', '[' => ']'] as $open => $close) {
            if (str_starts_with($text, $open) && !str_contains($text, $close)) $text = ltrim(substr($text, 1));
            if (str_ends_with($text, $close) && !str_contains($text, $open)) $text = rtrim(substr($text, 0, -1));
        }
        return $text;
    }

    public static function select(array $release, array $candidates): ?array
    {
        if (!$release['auto']) return null;
        $matches = [];
        foreach ($candidates as $candidate) {
            if ($candidate['type'] !== $release['type']) continue;
            if ($release['type'] === 'artist') {
                if (!self::named($release['artist'], $candidate['names'])) continue;
            } else {
                $byTitle = self::named($release['title'], $candidate['names']);
                // "Hits 1997" may be parsed as album "Hits" plus year; the full name then carries the year.
                $byTitleYear = !$byTitle && $release['year'] !== null
                    && self::named($release['title'] . ' ' . $release['year'], $candidate['names']);
                if (!$byTitle && !$byTitleYear) continue;
                if (!self::named($release['artist'], array_merge([$candidate['artist']], $candidate['artists'] ?? []))) continue;
                if ($byTitle && $release['year'] !== null && !self::yearFits($release, (string) $candidate['year'])) continue;
            }
            $matches[$candidate['id']] = $candidate;
        }
        if (count($matches) > 1 && $release['type'] === 'release-group') {
            // Same artist/title as album plus single/EP: prefer the hinted type, otherwise the album.
            foreach (array_filter([$release['kind'] ?? null, 'Album']) as $kind) {
                $typed = array_filter($matches, static fn (array $candidate): bool => strcasecmp($candidate['kind'] ?? '', $kind) === 0);
                if (count($typed) === 1) return array_values($typed)[0];
                if (count($typed) > 1) return null;
            }
        }
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private static function yearFits(array $release, string $year): bool
    {
        if ($release['year_exact'] ?? true) return $year === $release['year'];
        return $year === '' || $year <= $release['year'];
    }
}
