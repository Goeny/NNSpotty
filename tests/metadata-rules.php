<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Metadata' . DIRECTORY_SEPARATOR . 'MatchRules.php';

use NnSpotty\Metadata\MatchRules;

$checks = 0;
$rule = static fn (string $operation, string $term, string $type = 'movie', bool $enabled = true): array =>
    ['media_type' => $type, 'operation' => $operation, 'term' => $term, 'enabled' => $enabled];
$assert = static function (string $expected, string $title, array $rows, string $type = 'movie') use (&$checks): void {
    $actual = MatchRules::transform($title, $rows, $type);
    if ($actual !== $expected) {
        throw new RuntimeException("Expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    ++$checks;
};
$invalid = static function (array $rows, string $type = 'movie') use (&$checks): void {
    try {
        MatchRules::transform('A title 2020', $rows, $type);
    } catch (InvalidArgumentException $expected) {
        ++$checks;
        return;
    }
    throw new RuntimeException('Invalid rule was accepted.');
};

$assert('Film 2020', '-- Promo :: Film 2020', [$rule('remove_prefix', 'Promo')]);
$assert('Film_2020', '__Promo__Film_2020', [$rule('remove_prefix', 'Promo')]);
$assert('Film 2020', 'Film 2020 -- Promo!!!', [$rule('remove_suffix', 'Promo')]);
$assert('Film_2020', 'Film_2020__Promo__', [$rule('remove_suffix', 'Promo')]);
$assert('Film Promo 2020', 'Film Promo 2020', [$rule('remove_prefix', 'Promo')]);
$assert('Promo Film 2020', 'Promo Film 2020', [$rule('remove_suffix', 'Promo')]);
$assert('Film 2020', 'Film Promotional 2020', [$rule('remove_word', 'Promotional')]);
$assert('Film Promotional 2020', 'Film Promotional 2020', [$rule('remove_word', 'Promo')]);
$assert('Film 2020', 'Film Special upload 2020', [$rule('remove_word', 'Special upload')]);
$assert('Film (Director Cut) 2020', 'Film [Promo upload] (Director Cut) 2020', [$rule('remove_bracketed', 'Promo')]);
$assert('Film [Director Cut] 2020', 'Film (Promo upload) [Director Cut] 2020', [$rule('remove_bracketed', 'Promo')]);
$assert('Film (Promotional) 2020', 'Film (Promotional) 2020', [$rule('remove_bracketed', 'Promo')]);
$assert('Film (Promo 2020)', 'Film (Promo 2020)', [$rule('remove_bracketed', 'Promo')]);
$assert('Show [Promo S01E02]', 'Show [Promo S01E02]', [$rule('remove_bracketed', 'Promo')]);
$assert('Show (Promo Season 01-03)', 'Show (Promo Season 01-03)', [$rule('remove_bracketed', 'Promo')]);
$assert('Film 2020', 'Film Jaar:2020', [$rule('year_marker', 'Jaar')]);
$assert('Film Jaar:1899', 'Film Jaar:1899', [$rule('year_marker', 'Jaar')]);
$assert('Film Jaar:2100', 'Film Jaar:2100', [$rule('year_marker', 'Jaar')]);
$assert('Film Jaar:20201', 'Film Jaar:20201', [$rule('year_marker', 'Jaar')]);
$assert('Film Jaar:02020', 'Film Jaar:02020', [$rule('year_marker', 'Jaar')]);
$assert('Show Season 002 Episode 0012', 'Show Staffel_002 Folge-0012', [
    $rule('season_marker', 'Staffel', 'series'), $rule('episode_marker', 'Folge', 'series'),
], 'series');
$assert('Show Season 002-004 Episode 0012 t/m 0015', 'Show Staffel 002-004 Folge 0012 t/m 0015', [
    $rule('season_marker', 'Staffel', 'series'), $rule('episode_marker', 'Folge', 'series'),
], 'series');
$assert('Show Staffel 1234', 'Show Staffel 1234', [$rule('season_marker', 'Staffel')]);
$assert('Show Folge 12345', 'Show Folge 12345', [$rule('episode_marker', 'Folge')]);
$assert('Show Folge 0', 'Show Folge 0', [$rule('episode_marker', 'notFolge')]);
$assert('Film 1999-2001', 'Film Jaar1999-2001', [$rule('year_marker', 'Jaar')]);
$assert('Été 2020', 'Été ÉTIQUETTE 2020', [$rule('remove_word', 'étiquette')]);
$assert('映画 2020', '映画 宣伝 2020', [$rule('remove_word', '宣伝')]);
$assert('Film 2020', 'Film [a.*(x)+$] 2020', [$rule('remove_word', '[a.*(x)+$]')]);
$assert('Film aZZZ 2020', 'Film aZZZ 2020', [$rule('remove_word', 'a.*')]);
$assert('Unrelated.2020.1080p', 'Unrelated.2020.1080p', [$rule('remove_word', '(.*)')]);
$assert('Film Promo 2020', 'Film Promo 2020', [$rule('remove_word', 'Promo', 'music')]);
$assert('Film Promo 2020', 'Film Promo 2020', [$rule('remove_word', 'Promo', 'movie', false)]);
$assert('Artist - Album 2020', 'Artist - Album Jaar2020', [$rule('year_marker', 'Jaar', 'music')], 'music');
$assert('A 2020 B', 'A 2020 B', [$rule('remove_word', '2020')]);
$assert('Film 2020', 'Promo Film Promo 2020', [$rule('remove_word', 'Promo')]);
$assert('Film 2020', 'Promo Film [Junk] Jaar2020', [
    $rule('remove_prefix', 'Promo'), $rule('remove_bracketed', 'Junk'), $rule('year_marker', 'Jaar'),
]);
$assert(" Unrelated.2020 ", " Unrelated.2020 ", []);
$assert(str_repeat('x', 17000), str_repeat('x', 17000), [$rule('remove_word', 'x')]);
$assert("\xff", "\xff", [$rule('remove_word', 'x')]);
$invalid([$rule('unknown', 'text')]);
$invalid([$rule('remove_word', '')]);
$invalid([$rule('remove_word', '   ')]);
$invalid([$rule('remove_word', "bad\nterm")]);
$invalid([$rule('remove_word', "bad\0term")]);
$invalid([$rule('remove_word', "\xff")]);
$invalid([$rule('remove_word', str_repeat('é', 81))]);
$invalid([$rule('season_marker', '123+')]);
$invalid([$rule('episode_marker', '🎬')]);
$invalid([], 'invalid');
$invalid(array_fill(0, 101, $rule('remove_word', 'Promo')));
$assert('Film 2020', 'Film 2020', [$rule('remove_word', str_repeat('é', 80))]);

echo "Metadata rules: {$checks} checks passed.\n";
