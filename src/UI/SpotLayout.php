<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;

final class SpotLayout
{
    /** @param list<array<string, mixed>> $categories
     *  @param list<array<string, mixed>> $facets
     */
    public static function render(string $title, string $username, bool $isAdmin, string $csrfField, string $content, array $categories = [], ?string $selected = null, string $theme = 'default', array $facets = [], ?string $selectedSubcategory = null, string $search = '', bool $showErotica = false, bool $browse = false): string
    {
        $mode = $theme === 'plex-light' ? 'light' : 'dark';
        $head = SpotCategories::headForCategory($selected);
        $mainNavigation = '<a class="' . ($head === null ? 'active' : '') . '" href="/spots">Alle</a>';
        foreach (SpotCategories::HEADS as $index => $category) {
            $mainNavigation .= '<a class="' . ($index === $head ? 'active' : '') . '" href="'
                . h('/spots?' . http_build_query(['category' => $category, 'q' => $search])) . '">' . h($category) . '</a>';
        }
        $navigation = self::subcategories($head, $facets, $selectedSubcategory, $search, $showErotica);
        $admin = $isAdmin ? '<a class="side-link" href="/admin">Beheerpanel</a>' : '';
        $safeTitle = h($title);
        $safeUsername = h($username);
        $safeSearch = h($search);
        $categoryInput = $head !== null ? '<input type="hidden" name="category" value="' . h(SpotCategories::HEADS[$head]) . '">' : '';
        $contentClass = $browse ? 'content browse-page' : 'content';
        return <<<HTML
<!doctype html>
<html lang="nl" data-bs-theme="{$mode}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/spotty.css" rel="stylesheet">
</head>
<body class="spotty">
  <a class="visually-hidden-focusable skip-link" href="#content">Naar inhoud</a>
  <header class="topbar">
    <div class="header-menu">
      <a class="brand" href="/spots"><span class="brand-mark" aria-hidden="true">N</span>NNSpotty</a>
      <nav class="category-pills" aria-label="Hoofdcategorieen">{$mainNavigation}</nav>
      <a class="account-link" href="/preferences">{$safeUsername}</a>
    </div>
    <form method="get" action="/spots" role="search" class="global-search">
      {$categoryInput}
      <label for="global-search" class="visually-hidden">Zoek in spottitels</label>
      <input id="global-search" class="form-control form-control-sm" name="q" value="{$safeSearch}" type="search" maxlength="200" placeholder="Zoek in films, series, muziek, games...">
      <button class="btn btn-primary btn-sm" type="submit">Zoek</button>
    </form>
  </header>
  <aside class="sidebar" aria-label="Subcategorieen en beheer">
    <nav aria-label="Subcategorieen">
      {$navigation}
    </nav>
    <nav aria-label="Beheer en voorkeuren">
      <hr>
      <a class="side-link" href="/preferences">Voorkeuren</a>
      {$admin}
      <a class="side-link" href="/">Home</a>
    </nav>
    <div class="sidebar-footer">
      <span class="small text-body-secondary">0.0.1 &middot; Delta Integrale</span>
      <form method="post" action="/logout" class="mt-3">{$csrfField}<button class="btn btn-sm btn-outline-secondary w-100" type="submit">Uitloggen</button></form>
    </div>
  </aside>
  <div class="workspace">
    <main id="content" class="{$contentClass}" tabindex="-1">{$content}</main>
  </div>
</body>
</html>
HTML;
    }

    /** @param list<array<string, mixed>> $facets */
    private static function subcategories(?int $head, array $facets, ?string $selected, string $search, bool $showErotica): string
    {
        if ($head === null) {
            return '<p class="nav-caption">Subcategorieen</p><p class="small text-body-secondary px-2">Kies een hoofdcategorie bovenin om alle bijbehorende subcategorieen te bekijken.</p>';
        }
        $counts = array_column($facets, 'total', 'code');
        $category = SpotCategories::HEADS[$head];
        $base = ['category' => $category, 'q' => $search];
        $html = '<p class="nav-caption">' . h($category) . '</p><a class="side-link" href="'
            . h('/spots?' . http_build_query($base)) . '">Alles in ' . h($category) . '</a>';
        foreach (SpotCategories::dimensions($head) as $dimension => $name) {
            $html .= '<details class="category-group" open><summary>' . h($name) . '</summary>';
            foreach (SpotCategories::definitions()[$head][$dimension] as $number => $label) {
                $code = $head . ':' . $dimension . $number;
                $adult = $head === 0 && (($dimension === 'z' && $number === 3)
                    || ($dimension === 'd' && (($number >= 23 && $number <= 26) || ($number >= 72 && $number <= 89))));
                if ($adult && !$showErotica) {
                    continue;
                }
                $active = $code === $selected ? ' active' : '';
                $count = isset($counts[$code]) ? '<span class="side-count">' . (int) $counts[$code] . '</span>' : '';
                $html .= '<a class="side-link' . $active . '"' . ($active !== '' ? ' aria-current="page"' : '') . ' href="'
                    . h('/spots?' . http_build_query($base + ['subcategory' => $code])) . '">' . h($label) . $count . '</a>';
            }
            $html .= '</details>';
        }
        return $html;
    }
}
