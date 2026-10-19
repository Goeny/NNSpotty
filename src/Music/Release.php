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
        return hash('sha256', self::signature($spot) . ':discovery-v2:artist-id-v1');
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
        $title = preg_replace('/^\s*\d{1,2}\.\s+(?=\S)/', '', $title);
        $physical = preg_match('/[\[(][^)\]]*\b(?:Vinyl|LP)\b|\bCDRIP\b|\b(?:Vinyl|LP)\b(?=\s*(?:$|[-)\]]|\d+\s*[-.]?\s*bit\b|FLAC\b|WAVPACK\b))/i', $title) === 1;
        // A scene year can follow the audio suffix; capture it before removing that suffix.
        $sceneYear = preg_match('/-((?:19|20)\d{2})(?:-[A-Z0-9]+)?$/i', $title, $scene) ? $scene[1] : null;
        $title = preg_replace_callback('/\s*(?:\[[^\]]*\]|\([^)]*\))/', static function (array $match): string {
            $technical = preg_match('/\b(?:Vinyl|LP|FLAC|MP3|WAVPACK|DTS|kHz)\b|\b(?:16|24|32)\s*[- ]?bit\b/i', $match[0]) === 1;
            $identity = preg_match('/\b(?:live|remaster(?:ed)?|deluxe|edition|mix|version)\b/i', $match[0]) === 1;
            return $technical && !$identity ? '' : $match[0];
        }, $title);
        preg_match_all('/(?:\s+in\s+)?\b(?:FLAC|MP3|AAC|ALAC|APE|WAVPACK|WAV|WMA|OGG|DSD|SACD|WEB|CDRIP|DTS(?:[- .]?HD)?|(?:16|24|32)\s*[- .]?\s*bit|320\s*kbps|(?:LP|Vinyl)(?=\s*(?:$|-|\d+\s*[-.]?\s*bit\b|FLAC\b|WAVPACK\b)))\b/i', $title, $technical, PREG_OFFSET_CAPTURE);
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
        $year = $sceneYear;
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
        if (in_array(strtolower($artist), ['va', 'v a', 'various'], true)) $artist = 'Various Artists';
        $album = !$artistOnly && isset($parts[1]) ? self::cleanEdges(str_replace('.', ' ', $parts[1])) : '';
        // Edition labels are not part of an album-group title; live/location qualifiers are.
        $album = self::cleanEdges(preg_replace('/(?:\s+|[(\[])(?:DeLuxe|Super\s+Deluxe)(?:\s+Edition)?[)\]\s.]*$/i', '', $album));
        $explicitArtist = preg_match('/^(?:artist|artiest)\s*:\s*(.+)$/iu', $artist, $artistMatch) === 1;
        if ($explicitArtist) $artist = trim($artistMatch[1]);
        $artistOnly = $artistOnly || $explicitArtist;
        if (strlen($artist) < 2 || strlen($artist) > 200 || strlen($album) > 200) return null;
        if (!$artistOnly && $album === '') return ['type' => 'release-group', 'artist' => '', 'title' => $artist, 'year' => $year, 'auto' => false, 'query' => $artist];
        return ['type' => $artistOnly ? 'artist' : 'release-group', 'artist' => $artist,
            'title' => $album, 'year' => $physical || $sourceYear !== null ? null : $year,
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
            $target = $release['type'] === 'artist' ? $release['artist'] : $release['title'];
            if (!in_array(Titles::normalized($target), array_map(Titles::normalized(...), $candidate['names']), true)) continue;
            if ($release['type'] !== 'artist') {
                if (Titles::normalized($candidate['artist']) !== Titles::normalized($release['artist'])) continue;
                if ($release['year'] !== null && $candidate['year'] !== $release['year']) continue;
            }
            $matches[$candidate['id']] = $candidate;
        }
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }
}
