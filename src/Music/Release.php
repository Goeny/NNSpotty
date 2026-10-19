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

    public static function parse(array $spot): ?array
    {
        if ($spot['category'] !== 'Geluid') return null;
        foreach ($spot['subcategories'] as $code) {
            if (preg_match('/^2z0?[23]$/D', strtolower($code))) return null;
        }
        $title = trim(str_replace(['_', '–', '—'], [' ', '-', '-'], $spot['title']));
        $title = preg_replace('/^\s*\d{1,2}\.\s+(?=\S)/', '', $title);
        $title = preg_replace('/\b(?:FLAC|MP3|AAC|ALAC|APE|WAV|WMA|OGG|DSD|SACD|WEB|CDRIP|320\s*kbps|24\s*bit|16\s*bit)\b.*$/i', '', $title);
        $artistOnly = preg_match('/\b(?:discography|discografie|discographie)\b/i', $title) === 1;
        if ($artistOnly) $title = preg_split('/\b(?:discography|discografie|discographie)\b/i', $title, 2)[0];
        if ($artistOnly) $title = preg_replace('/[ .(-]*(?:19|20)\d{2}\s*-\s*(?:19|20)\d{2}[ .)-]*$/', '', $title);
        $title = trim($title, " \t.-()[]");
        $year = null;
        if (preg_match('/(?:[\s.(-]+)((?:19|20)\d{2})(?:[)\]\s.-]*)$/', $title, $match)) {
            $year = $match[1];
            $title = substr($title, 0, -strlen($match[0]));
        }
        $parts = preg_split('/\s+-\s+/', trim($title, " \t.-()[]"), 2);
        if (count($parts) === 1 && !$artistOnly && str_contains($title, '-')) $parts = explode('-', trim($title, " \t.-()[]"), 2);
        $artist = trim(str_replace('.', ' ', $parts[0]), " \t.-()[]");
        if (in_array(strtolower($artist), ['va', 'v a', 'various'], true)) $artist = 'Various Artists';
        $album = !$artistOnly && isset($parts[1]) ? trim(str_replace('.', ' ', $parts[1]), " \t.-()[]") : '';
        $explicitArtist = preg_match('/^(?:artist|artiest)\s*:\s*(.+)$/iu', $artist, $artistMatch) === 1;
        if ($explicitArtist) $artist = trim($artistMatch[1]);
        $artistOnly = $artistOnly || $explicitArtist;
        if (strlen($artist) < 2 || strlen($artist) > 200 || strlen($album) > 200) return null;
        if (!$artistOnly && $album === '') return ['type' => 'release-group', 'artist' => '', 'title' => $artist, 'year' => $year, 'auto' => false, 'query' => $artist];
        return ['type' => $artistOnly ? 'artist' : 'release-group', 'artist' => $artist,
            'title' => $album, 'year' => $year, 'auto' => true, 'query' => $artistOnly ? $artist : $artist . ' - ' . $album];
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
