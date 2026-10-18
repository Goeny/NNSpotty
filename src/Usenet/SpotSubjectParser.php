<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

final class SpotSubjectParser
{
    /**
     * @return array{release_key: string, release_title: string}
     */
    public static function parse(string $subject, string $groupName): array
    {
        $raw = trim($subject);
        if ($raw === '') {
            $fallbackKey = sha1($groupName . '|empty-subject');

            return [
                'release_key' => $fallbackKey,
                'release_title' => '(zonder subject)',
            ];
        }

        $candidate = preg_replace('/\s+-\s+yEnc\b.*$/i', '', $raw) ?? $raw;
        $candidate = preg_replace('/\s+yEnc\b.*$/i', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^\(\d+\/\d+\)\s*/', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);

        if (preg_match('/"([^"]+)"/', $candidate, $matches) === 1 && isset($matches[1])) {
            $candidate = trim((string) $matches[1]);
        }

        $candidate = preg_replace('/\s*\(\d+\/\d+\)\s*$/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/[\[\(]?\s*part[\s._-]?\d+[\]\)]?/i', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/[\[\(]?\s*vol[\s._-]?\d+[\]\)]?/i', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\.(par2|rar|r\d+|zip|7z|nfo|sfv|mkv|mp4|avi|wmv|iso|m2ts|ts|mp3|flac|epub|pdf|cbz|cbr|jpg|jpeg|png|gif|001|002|003)$/i', '', $candidate) ?? $candidate;
        $candidate = str_replace(['_', '.'], ' ', $candidate);
        $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;
        $candidate = trim($candidate, " \t\n\r\0\x0B-");

        if ($candidate === '') {
            $candidate = $raw;
        }

        $normalized = strtolower($candidate);
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            $normalized = sha1(strtolower($raw));
        }

        return [
            'release_key' => sha1($groupName . '|' . $normalized),
            'release_title' => substr($candidate, 0, 255),
        ];
    }
}
