<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotReleaseQuality
{
    /** @return array<string, list<string>> */
    public static function parse(string $title): array
    {
        $title = str_replace('_', '.', $title);
        // Do not interpret words in the work's title as audio/language/format tags.
        if (preg_match('/\bS\d{1,3}(?:[ .-]*E\d{1,4})?|\b\d{1,2}x\d{2,3}\b|(?<!\d)(?:19|20)\d{2}(?!\d)/i', $title, $match, PREG_OFFSET_CAPTURE)) {
            $title = substr($title, $match[0][1] + strlen($match[0][0]));
        } elseif (preg_match('/\b(?:4320p|2160p|1080[pi]|720p|480p|[48]K|UHD|Ultra[ .-]?HD|Blu[ .-]?ray|BDRemux|DVD|WEB[ .-]?DL|WEBRip|HDTV)\b/i', $title, $match, PREG_OFFSET_CAPTURE)) {
            $title = substr($title, $match[0][1]);
        } else {
            return [];
        }
        $definitions = [
            'Video' => [
                '4K (2160p)' => '2160p|4K|UHD|Ultra[ .-]?HD', '1080p' => '1080p', '1080i' => '1080i',
                '8K (4320p)' => '4320p|8K',
                '720p' => '720p', '480p' => '480p', 'HDR10+' => 'HDR10\+|HDR10[ .-]?Plus', 'HDR10' => 'HDR10(?!\+|[ .-]?Plus)',
                'HDR' => 'HDR', 'SDR' => 'SDR', 'Dolby Vision' => 'Dolby[ .-]?Vision',
                'x264' => 'x[ .-]?264', 'x265' => 'x[ .-]?265', 'H.264' => 'h[ .-]?264|AVC',
                'H.265' => 'h[ .-]?265', 'HEVC' => 'HEVC',
                'AV1' => 'AV1', 'VP9' => 'VP[ .-]?9', 'IMAX' => 'IMAX', 'AI-enhanced' => 'AI[ .-]?Enhanced',
                'RIFE' => 'RIFE', '60 fps' => '60[ .-]?fps', 'Blu-ray' => 'Blu[ .-]?ray',
                'WEB-DL' => 'WEB[ .-]?DL', 'WEBRip' => 'WEBRip', 'WEB' => 'WEB(?![ .-]?(?:DL|Rip))',
                'HDTV' => 'HDTV', 'DVD' => 'DVD|DVD[ .-]?Rip', 'BDRemux' => 'BD[ .-]?Remux', 'REMUX' => '(?<!BD[ .-])REMUX',
            ],
            'Audio' => [
                'TrueHD' => '(?:Dolby[ .-]?)?True[ .-]?HD', 'Atmos' => 'Atmos',
                'DTS-HD MA' => 'DTS[ .-]?HD[ .-]?(?:MA|Master[ .-]?Audio)',
                'DTS-HD HRA' => 'DTS[ .-]?HD[ .-]?HRA',
                'DTS:X' => 'DTS[ .:-]?X', 'DTS' => 'DTS(?![ .-]?HD|[ .:-]?X)',
                'DD+' => '(?:Dolby[ .-]?Digital[ .-]?Plus|DD\+|DDP|E[ .-]?AC[ .-]?3)(?:[257][ .][01])?',
                'Dolby Digital' => '(?:Dolby[ .-]?Digital(?![ .-]?Plus)|DD(?![P+])|AC[ .-]?3)(?:[257][ .][01])?',
                'AAC' => 'AAC', 'FLAC' => 'FLAC', 'MP3' => 'MP3',
                '7.1' => '(?:DDP?|DD\+)?7[ .]1', '5.1' => '(?:DDP?|DD\+)?5[ .]1', '2.0' => '(?:DDP?|DD\+)?2[ .]0',
            ],
            'Taal' => ['Nederlands' => 'DUTCH|NL[ .-]?DUBBED', 'Engels' => 'ENGLISH', 'Meertalig' => 'MULTI[ .-]?AUDIO'],
            'Ondertiteling' => [
                'Nederlands (retail)' => 'NL[ .-]?RETAIL[ .-]?SUBS?',
                'Nederlands' => 'NL[ .-]?SUBS?|NLSUBBED', 'Engels' => 'ENG[ .-]?SUBS?|ENGLISH[ .-]?SUBS?',
                'Meerdere talen' => 'MULTI[ .-]?SUBS?',
            ],
        ];
        $result = [];
        foreach ($definitions as $label => $patterns) {
            foreach ($patterns as $value => $pattern) {
                if (preg_match('~(?<![a-z0-9])(?:' . $pattern . ')(?![a-z0-9])~i', $title)) $result[$label][] = $value;
            }
        }
        return $result;
    }

    public static function render(string $title): string
    {
        $quality = self::parse($title);
        if ($quality === []) return '';
        $html = '<section class="release-quality" aria-label="Releasekwaliteit"><dl>';
        foreach ($quality as $label => $values) {
            $html .= '<div><dt>' . h($label) . '</dt><dd class="quality-badges">';
            foreach ($values as $value) {
                $html .= '<span class="quality-badge' . (in_array($label, ['Video', 'Audio'], true) ? ' quality-badge-format' : '') . '">'
                    . h($value) . '</span>';
            }
            $html .= '</dd></div>';
        }
        return $html . '</dl><small class="text-body-secondary">Uit de releasetitel; niet gecontroleerd in de mediabestanden. Taal is niet noodzakelijk de audiotaal.</small></section>';
    }
}
