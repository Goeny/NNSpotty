<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotLayout
{
    /** @param list<array<string, mixed>> $categories */
    public static function render(string $title, string $username, bool $isAdmin, string $csrfField, string $content, array $categories = [], ?string $selected = null, string $theme = 'default'): string
    {
        $mode = $theme === 'plex-light' ? 'light' : 'dark';
        $navigation = '<a class="side-link' . ($selected === null ? ' active' : '') . '" href="/spots">Alle spots</a>';
        foreach ($categories as $entry) {
            $category = (string) $entry['category'];
            $active = $category === $selected ? ' active' : '';
            $navigation .= '<a class="side-link' . $active . '" href="/spots?category=' . rawurlencode($category) . '">'
                . h($category) . '<span class="side-count">' . number_format((int) $entry['total'], 0, ',', '.') . '</span></a>';
        }
        $admin = $isAdmin ? '<a class="side-link" href="/admin">Beheerpanel</a>' : '';
        $safeTitle = h($title);
        $safeUsername = h($username);
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
  <aside class="sidebar">
    <a class="brand" href="/spots"><span class="brand-mark" aria-hidden="true">N</span>NNSpotty</a>
    <nav aria-label="Hoofdnavigatie">
      <p class="nav-caption">Ontdek spots</p>
      {$navigation}
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
    <header class="topbar">
      <form method="get" action="/spots" role="search" class="global-search">
        <label for="global-search" class="visually-hidden">Zoek in spottitels</label>
        <input id="global-search" class="form-control" name="q" type="search" maxlength="200" placeholder="Zoek in films, series, muziek, games...">
        <button class="btn btn-primary" type="submit">Zoek</button>
      </form>
      <a class="account-link" href="/preferences">{$safeUsername}</a>
    </header>
    <main id="content" class="content" tabindex="-1">{$content}</main>
  </div>
</body>
</html>
HTML;
    }
}
