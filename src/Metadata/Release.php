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
        $raw = preg_replace('/^\s*\d{1,2}\.\s+(?=\S)/', '', $raw);
        $season = null;
        $episode = null;
        $cut = strlen($raw);
        $multi = preg_match('/\b(?:trilogy|trilogie|collection|boxset)\b|\b[2-9]\s*x\s*BD(?:25|50|66|100)\b/i', $raw) === 1;
        if (preg_match('/\bS(\d{1,3})(?:[ ._-]*E(\d{1,4}))?/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $season = (int) $match[1][0];
            $episode = isset($match[2]) ? (int) $match[2][0] : null;
            $cut = $match[0][1];
            $tail = substr($raw, $cut + strlen($match[0][0]));
            $multi = $multi || preg_match('~^(?:[ ._&,+/-]*(?:E\d|S\d)|[ -]*\d{1,3}\b|[ ._-]*(?:to|tot|t/m)\b)~i', $tail) === 1;
        } elseif (preg_match('/\b(\d{1,2})x(\d{2,3})\b/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $season = (int) $match[1][0];
            $episode = (int) $match[2][0];
            $cut = $match[0][1];
            $multi = $multi || preg_match('/^(?:[ ._-]*\d+x\d|[ -]+\d{2}\b)/i', substr($raw, $cut + strlen($match[0][0]))) === 1;
        } elseif (preg_match('/\b(?:seizoen|season)\s*0*(\d{1,3})(?:\s*(?:-|t\/m|tm|to)\s*0*(\d{1,3}))?/i', $raw, $seasonMatch, PREG_OFFSET_CAPTURE)) {
            $season = (int) $seasonMatch[1][0];
            $cut = $seasonMatch[0][1];
            $hasSeasonRange = isset($seasonMatch[2]) && $seasonMatch[2][0] !== '';
            if (preg_match('/\b(?:aflevering|episode|ep)\s*0*(\d{1,4})(?:\s*(?:-|t\/m|tm|to)\s*0*(\d{1,4}))?/i', $raw, $episodeMatch, PREG_OFFSET_CAPTURE)) {
                $episode = (int) $episodeMatch[1][0];
                $multi = $multi || (isset($episodeMatch[2]) && $episodeMatch[2][0] !== '');
            }
            $multi = $multi || $hasSeasonRange;
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
        $name = preg_split('/\b(?:2160p|1080[pi]|720p|480p|4K|UHD|Ultra[ .-]?HD|WEB[- .]?DL|WEBRip|Blu[ .-]?Ray|BDRip|BRRip|DVDRip|HDTV|x26[45]|h[ .]?26[45]|HEVC|REMUX|DV|DoVi|Dolby[ .-]?Vision|COMPLETE|REPACK|PROPER|MULTISUBS|NL[ ._-]?(?:RETAIL)?SUBS|IMAX|Theatrical[ .-]+(?:Version|Cut)|Extended[ .-]+(?:Edition|Cut)|Director.?s[ .-]+Cut)\b/i', $name, 2)[0];
        $name = trim(preg_replace('/[._]+/', ' ', $name), " \t\n\r\0\x0B-()[]");
        if (strlen($name) < 2 || strlen($name) > 200) return null;
        return ['type' => $kind, 'query' => $name, 'year' => $year, 'season' => $season,
            'episode' => $multi ? null : $episode, 'multi' => $multi];
    }

    public static function normalized(string $title): string
    {
        $title = preg_replace('/\s*&\s*/u', ' and ', $title) ?? $title;
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($ascii === false ? $title : $ascii));
        return $normalized !== '' ? $normalized : preg_replace('/[^\p{L}\p{N}]+/u', '', $title);
    }

    public static function select(array $release, array $candidates): ?array
    {
        return self::assess($release, $candidates)['selected'];
    }

    /** @return array{selected:?array, plausible:int} */
    public static function assess(array $release, array $candidates): array
    {
        if ($release['type'] === 'movie' && ($release['multi'] ?? false)) return ['selected' => null, 'plausible' => 0];
        $title = self::normalized($release['query']);
        if ($title === '') return ['selected' => null, 'plausible' => 0];
        $scored = [];
        foreach ($candidates as $candidate) {
            if ($candidate['type'] !== $release['type']) continue;
            if ($release['type'] === 'movie' && $release['year'] === null) continue;
            $bestTitleScore = 0;
            $bestTitleExact = false;
            foreach ($candidate['names'] as $name) {
                $candidateName = self::normalized($name);
                if ($candidateName === $title) {
                    $bestTitleScore = max($bestTitleScore, 100);
                    $bestTitleExact = true;
                    continue;
                }
                similar_text($title, $candidateName, $similarity);
                if ($similarity >= 92.0) $bestTitleScore = max($bestTitleScore, 85);
                elseif ($similarity >= 85.0) $bestTitleScore = max($bestTitleScore, 70);
            }
            if ($bestTitleScore === 0) continue;
            $yearScore = 0;
            $candidateYear = null;
            if ($release['year'] !== null) {
                $candidateYear = is_string($candidate['year']) && preg_match('/^\d{4}$/D', $candidate['year']) === 1 ? (int) $candidate['year'] : null;
                if ($candidateYear === $release['year']) {
                    $yearScore = 25;
                } elseif ($candidateYear !== null && abs($candidateYear - $release['year']) === 1) {
                    $yearScore = 8;
                } else {
                    continue;
                }
            }
            $scored[] = ['score' => $bestTitleScore + $yearScore, 'title_exact' => $bestTitleExact,
                'year_exact' => $release['year'] !== null && $candidateYear === $release['year'],
                'popularity' => is_numeric($candidate['popularity'] ?? null) ? (float) $candidate['popularity'] : null,
                'vote_count' => is_numeric($candidate['vote_count'] ?? null) ? (int) $candidate['vote_count'] : null,
                'candidate' => $candidate];
        }
        if ($scored === []) return ['selected' => null, 'plausible' => 0];
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        if (count($scored) === 1) return ['selected' => $scored[0]['candidate'], 'plausible' => 1];
        if ($scored[0]['title_exact'] && $scored[0]['year_exact']) {
            $top = array_values(array_filter($scored, static fn (array $row): bool => $row['score'] === $scored[0]['score']
                && $row['title_exact'] && $row['year_exact']));
            if (count($top) === 1) return ['selected' => $top[0]['candidate'], 'plausible' => count($scored)];
            usort($top, static function (array $a, array $b): int {
                $votes = ($b['vote_count'] ?? -1) <=> ($a['vote_count'] ?? -1);
                if ($votes !== 0) return $votes;
                $popularity = ($b['popularity'] ?? -1.0) <=> ($a['popularity'] ?? -1.0);
                if ($popularity !== 0) return $popularity;
                return 0;
            });
            if (($top[0]['vote_count'] ?? null) !== ($top[1]['vote_count'] ?? null)
                || ($top[0]['popularity'] ?? null) !== ($top[1]['popularity'] ?? null)) {
                return ['selected' => $top[0]['candidate'], 'plausible' => count($scored)];
            }
        }
        if ($scored[0]['score'] >= 110 && ($scored[0]['score'] - $scored[1]['score']) >= 15) {
            return ['selected' => $scored[0]['candidate'], 'plausible' => count($scored)];
        }
        return ['selected' => null, 'plausible' => count($scored)];
    }
}
