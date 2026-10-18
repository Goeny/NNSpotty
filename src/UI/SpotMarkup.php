<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use RuntimeException;

final class SpotMarkup
{
    public static function renderText(string $text, bool $allowLinks = true): string
    {
        $tokens = preg_split('/(\[(?:\/?(?:b|i|u|s|quote|code|url|list|li|color|size|font|center|left|right)(?:=[^\]\r\n]*)?|img=[a-z]+|br\s*\/?|hr\s*\/?|\*)\])/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            throw new RuntimeException('Spotnet-opmaak kon niet worden verwerkt.');
        }
        $position = 0;
        return self::sequence($tokens, $position, null, 0, $allowLinks);
    }

    public static function link(string $href, string $content): string
    {
        $href = trim($href);
        if (preg_match('~^https?://~i', $href) && filter_var($href, FILTER_VALIDATE_URL) !== false
            && preg_match('/[\x00-\x20\x7f]/', $href) !== 1) {
            return '<a href="' . h($href) . '" target="_blank" rel="noopener noreferrer nofollow">' . $content . '</a>';
        }
        return $content;
    }

    /** @param list<string> $tokens */
    private static function sequence(array $tokens, int &$position, ?string $closing, int $depth, bool $allowLinks): string
    {
        if ($depth > 100) {
            throw new RuntimeException('Spotomschrijving bevat te diep geneste Spotnet-opmaak.');
        }
        $html = '';
        $listItemOpen = false;
        $tags = ['b' => 'strong', 'i' => 'em', 'u' => 'u', 's' => 's', 'quote' => 'blockquote', 'li' => 'li',
            'center' => 'div', 'left' => 'div', 'right' => 'div', 'color' => 'span', 'size' => 'span', 'font' => 'span'];
        while ($position < count($tokens)) {
            $token = $tokens[$position++];
            if ($closing !== null && strcasecmp($token, '[/' . $closing . ']') === 0) {
                return $html . ($listItemOpen ? '</li>' : '');
            }
            if ($token === '[*]' && $closing === 'list') {
                if ($listItemOpen) {
                    $html .= '</li>';
                } elseif (trim($html) !== '') {
                    $html = '<li>' . $html . '</li>';
                }
                $html .= '<li>';
                $listItemOpen = true;
            } elseif (preg_match('/^\[img=[a-z]+\]$/iD', $token)) {
                $html .= SpotEmoticons::render($token);
            } elseif (preg_match('/^\[(br|hr)\s*\/?\]$/iD', $token, $match)) {
                $html .= '<' . strtolower($match[1]) . '>';
            } elseif (preg_match('/^\[(b|i|u|s|quote|code|url|list|li|color|size|font|center|left|right)(?:=([^\]]*))?\]$/iD', $token, $match)) {
                $tag = strtolower($match[1]);
                $start = $position;
                if ($tag === 'code') {
                    $raw = '';
                    while ($position < count($tokens) && strcasecmp($tokens[$position], '[/code]') !== 0) {
                        $raw .= $tokens[$position++];
                    }
                    $closed = $position < count($tokens);
                    if ($closed) {
                        $position++;
                    }
                    $html .= $closed ? '<pre><code>' . h($raw) . '</code></pre>' : h($token . $raw);
                    continue;
                }
                $inner = self::sequence($tokens, $position, $tag, $depth + 1, $allowLinks && $tag !== 'url');
                $closed = strcasecmp($tokens[$position - 1], '[/' . $tag . ']') === 0;
                if (!$closed) {
                    $html .= h($token) . $inner;
                } elseif ($tag === 'url') {
                    $href = $match[2] ?? implode('', array_slice($tokens, $start, $position - $start - 1));
                    $html .= $allowLinks ? self::link($href, $inner) : $inner;
                } elseif ($tag === 'list') {
                    $listTag = ($match[2] ?? '') === '1' ? 'ol' : 'ul';
                    $html .= '<' . $listTag . '>' . $inner . '</' . $listTag . '>';
                } else {
                    $html .= '<' . $tags[$tag] . '>' . $inner . '</' . $tags[$tag] . '>';
                }
            } else {
                $html .= nl2br(h($token));
            }
        }
        return $html . ($listItemOpen ? '</li>' : '');
    }
}
