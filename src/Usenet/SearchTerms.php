<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

/**
 * Whole-word title search: FULLTEXT narrows candidates, a regular expression confirms exact words or phrases.
 */
final class SearchTerms
{
    private const MAX_TOKEN = 84;

    /** @param list<string> $words */
    private function __construct(public readonly array $words)
    {
    }

    public static function parse(string $search): ?self
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        return new self(array_slice($words === false ? [] : array_values($words), 0, 20));
    }

    /** Boolean-mode query requiring the most selective indexable words, or null when no word is indexable. */
    public function fulltext(int $minimumToken): ?string
    {
        $indexable = array_values(array_filter($this->words, static fn (string $word): bool
            => mb_strlen($word) >= $minimumToken && mb_strlen($word) <= self::MAX_TOKEN));
        // Short words such as "the" have huge posting lists; the regular expression still enforces them.
        $long = array_values(array_filter($indexable, static fn (string $word): bool => mb_strlen($word) >= 4));
        $required = array_values(array_unique(array_map('mb_strtolower', $long !== [] ? $long : $indexable)));
        return $required === [] ? null : implode(' ', array_map(static fn (string $word): string => '+' . $word, $required));
    }

    /** Matches the words consecutively, separated by anything that is not a letter or digit. */
    public function phrasePattern(): string
    {
        return '(?i)(?<![\p{L}\p{N}])' . implode('[^\p{L}\p{N}]+', array_map(
            static fn (string $word): string => preg_quote($word, '/'), $this->words
        )) . '(?![\p{L}\p{N}])';
    }

    /** @return list<string> One whole-word pattern per word, in any order. */
    public function wordPatterns(): array
    {
        return array_map(static fn (string $word): string => '(?i)(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])',
            array_values(array_unique($this->words)));
    }
}
