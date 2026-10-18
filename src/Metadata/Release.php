<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use NnSpotty\Usenet\SpotCategories;

final class Release
{
    public static function parse(array $spot): ?array
    {
        $type = SpotCategories::classify($spot['category'], $spot['subcategories'])['type'];
        if (!in_array($type, ['Films', 'Series'], true)) return null;
        $raw = str_replace('_', '.', $spot['title']);
        $season = null;
        $episode = null;
        $cut = strlen($raw);
        $multi = false;
        if (preg_match('/\bS(\d{1,3})(?:[ ._-]*E(\d{1,4}))?/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $season = (int) $match[1][0];
            $episode = isset($match[2]) ? (int) $match[2][0] : null;
            $cut = $match[0][1];
            $tail = substr($raw, $cut + strlen($match[0][0]));
            $multi = preg_match('~^(?:[ ._&,+/-]*(?:E\d|S\d)|[ -]*\d{1,3}\b|[ ._-]*(?:to|tot|t/m)\b)~i', $tail) === 1;
        } elseif (preg_match('/\b(\d{1,2})x(\d{2,3})\b/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $season = (int) $match[1][0];
            $episode = (int) $match[2][0];
            $cut = $match[0][1];
            $multi = preg_match('/^(?:[ ._-]*\d+x\d|[ -]+\d{2}\b)/i', substr($raw, $cut + strlen($match[0][0]))) === 1;
        }
        $kind = $type === 'Series' || $season !== null ? 'series' : 'movie';
        $name = substr($raw, 0, $cut);
        $year = null;
        if (preg_match_all('/(?<!\d)((?:19|20)\d{2})(?!\d)/', $name, $years, PREG_OFFSET_CAPTURE)) {
            foreach (array_reverse($years[0]) as [$value, $offset]) {
                // A leading numeric movie title (e.g. 1917) is not a release year.
                if (trim(substr($name, 0, $offset), " ._-([") === '') continue;
                $year = (int) $value;
                $name = substr($name, 0, $offset);
                break;
            }
        }
        $name = preg_split('/\b(?:2160p|1080[pi]|720p|480p|WEB[- .]?DL|WEBRip|BluRay|BDRip|BRRip|DVDRip|HDTV|x26[45]|h[ .]?26[45]|HEVC|REMUX|COMPLETE|REPACK|PROPER|MULTISUBS|NL[ ._-]?SUBS)\b/i', $name, 2)[0];
        $name = trim(preg_replace('/[._]+/', ' ', $name), " \t\n\r\0\x0B-()[]");
        if (strlen($name) < 2 || strlen($name) > 200) return null;
        return ['type' => $kind, 'query' => $name, 'year' => $year, 'season' => $season,
            'episode' => $multi ? null : $episode, 'multi' => $multi];
    }

    public static function normalized(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($ascii === false ? $title : $ascii));
        return $normalized !== '' ? $normalized : preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
    }

    public static function select(array $release, array $candidates): ?array
    {
        $matches = [];
        $title = self::normalized($release['query']);
        if ($title === '') return null;
        foreach ($candidates as $candidate) {
            if ($candidate['type'] !== $release['type']) continue;
            if ($release['year'] !== null && $candidate['year'] !== (string) $release['year']) continue;
            if ($release['type'] === 'movie' && $release['year'] === null) continue;
            foreach ($candidate['names'] as $name) {
                if (self::normalized($name) === $title) {
                    $matches[$candidate['id']] = $candidate;
                    break;
                }
            }
        }
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }
}
