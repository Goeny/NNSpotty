<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;
use NnSpotty\Usenet\SpotFilters;

final class SpotLayout
{
    /** @param list<array<string, mixed>> $categories
     *  @param list<array<string, mixed>> $facets
     */
    public static function render(string $title, string $username, bool $isAdmin, string $csrfField, string $content, array $categories = [], ?string $selected = null, string $theme = 'default', array $facets = [], string|array|null $selectedSubcategory = null, string $search = '', bool $showErotica = false, bool $browse = false): string
    {
        $mode = $theme === 'plex-light' ? 'light' : 'dark';
        $head = SpotCategories::headForCategory($selected);
        $mainNavigation = '<a class="' . ($head === null ? 'active' : '') . '" href="/spots">Alle</a>';
        foreach (SpotCategories::HEADS as $index => $category) {
            $mainNavigation .= '<a class="' . ($index === $head ? 'active' : '') . '" href="'
                . h('/spots?' . http_build_query(['category' => $category, 'q' => $search])) . '">' . h($category) . '</a>';
        }
        $filters = is_array($selectedSubcategory) ? $selectedSubcategory : SpotFilters::parse($selectedSubcategory);
        $navigation = self::subcategories($head, $facets, $filters, $search, $showErotica);
        $admin = $isAdmin ? '<a class="side-link" href="/admin">Beheerpanel</a>' : '';
        $safeTitle = h($title);
        $safeUsername = h($username);
        $safeSearch = h($search);
        $categoryInput = $head !== null ? '<input type="hidden" name="category" value="' . h(SpotCategories::HEADS[$head]) . '">' : '';
        $filterInput = $filters !== [] ? '<input type="hidden" name="subcategory" value="' . h(implode(',', $filters)) . '">' : '';
        $contentClass = $browse ? 'content browse-page' : 'content';
        $scripts = $browse ? '<script src="/assets/spots.js" defer></script>' : '';
        $connectionOptions = '<option value="personal">Mijn SABnzbd</option>' . ($isAdmin ? '<option value="central">Centrale SABnzbd (admin)</option>' : '');
        return <<<HTML
<!doctype html>
<html lang="nl" data-bs-theme="{$mode}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/spotty.css" rel="stylesheet">
  <script src="/assets/presentation.js" defer></script>
  <script src="/assets/downloader.js" defer></script>
  {$scripts}
</head>
<body class="spotty">
  <a class="visually-hidden-focusable skip-link" href="#content">Naar inhoud</a>
  <header class="topbar">
    <div class="header-menu">
      <a class="brand" href="/spots"><span class="brand-mark" aria-hidden="true">N</span>NNSpotty</a>
      <nav class="category-pills" aria-label="Hoofdcategorieen">{$mainNavigation}</nav>
      <a class="account-link" href="/preferences">{$safeUsername}</a>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-sab-open="personal">Downloads</button>
    </div>
    <form method="get" action="/spots" role="search" class="global-search">
      {$categoryInput}
      {$filterInput}
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
  <dialog class="image-lightbox" id="image-lightbox" aria-labelledby="lightbox-title">
    <div class="lightbox-toolbar">
      <h2 class="h6 mb-0" id="lightbox-title">Afbeelding</h2>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-close-lightbox>Sluiten &times;</button>
    </div>
    <div class="lightbox-body">
      <p class="small text-body-secondary" data-lightbox-status role="status">Afbeelding laden...</p>
      <div class="alert alert-warning" data-lightbox-error role="alert" hidden>De afbeelding kon niet worden geladen. Sluit dit venster en probeer opnieuw.</div>
      <img class="lightbox-image" alt="" referrerpolicy="no-referrer">
    </div>
  </dialog>
  <dialog class="download-drawer" id="download-drawer" aria-labelledby="download-title">
    <div class="download-toolbar">
      <h2 class="h5 m-0" id="download-title">Downloads</h2>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-sab-close>Sluiten &times;</button>
    </div>
    <div class="download-body">
      <label class="form-label" for="sab-connection">Koppeling</label>
      <select class="form-select mb-3" id="sab-connection">{$connectionOptions}</select>
      <p class="small text-body-secondary">Je ziet de volledige wachtrij van de gekozen host. De centrale koppeling is uitsluitend voor admins.</p>
      <div role="status" class="alert alert-info" data-sab-message>Open het paneel om de wachtrij te laden.</div>
      <form data-sab-confirm hidden class="card p-3 mb-3">
        {$csrfField}
        <h3 class="h6">Spot versturen</h3>
        <p data-sab-spot-title></p>
        <p class="small">Categorie: <strong data-sab-category></strong></p>
        <p class="small text-body-secondary">Bij een onzekere uitkomst eerst SABnzbd controleren om dubbele downloads te voorkomen.</p>
        <button class="btn btn-primary" type="submit">Bevestigen en verzenden</button>
        <button class="btn btn-outline-secondary mt-2" type="button" data-sab-cancel>Annuleren</button>
      </form>
      <section class="card p-3 mb-3" aria-label="Downloadsnelheid">
        <div class="d-flex justify-content-between gap-2"><span data-sab-state>Niet verbonden</span><strong data-sab-speed>--</strong></div>
        <small class="text-body-secondary">Schaal: 0 - <span data-sab-peak>--</span></small>
        <svg viewBox="0 0 480 100" class="download-chart mt-3" role="img" aria-label="Downloadsnelheid tijdens deze panelsessie; laatste vijf minuten">
          <path d="M0 99H480" class="chart-grid"></path><polyline points="" data-sab-chart></polyline><circle cx="480" cy="99" r="3" data-sab-dot hidden></circle>
        </svg>
        <div class="d-flex justify-content-between small text-body-secondary"><span>-5 minuten</span><span>Nu</span></div>
        <p class="small text-body-secondary mb-0">Live snelheid, laatste vijf minuten. Pauzeert als het paneel of tabblad gesloten is.</p>
      </section>
      <section aria-labelledby="sab-queue-title"><h3 class="h6" id="sab-queue-title">Wachtrij</h3><div data-sab-queue></div>
        <div class="d-flex justify-content-between my-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-sab-prev disabled>Vorige</button><span data-sab-total></span><button type="button" class="btn btn-sm btn-outline-secondary" data-sab-next disabled>Volgende</button></div>
      </section>
      <section class="mt-4"><h3 class="h6">Verzonden vanuit NNSpotty</h3><p class="small text-body-secondary">De laatste 30 pogingen op deze koppeling. Verzonden betekent geaccepteerd door SABnzbd, niet voltooid.</p><div data-sab-history></div></section>
      <button class="btn btn-outline-secondary mt-3" type="button" data-sab-refresh>Vernieuwen / opnieuw proberen</button>
      <a class="btn btn-link" href="/preferences#downloads">Mijn koppeling instellen</a>
    </div>
  </dialog>
</body>
</html>
HTML;
    }

    /** @param list<array<string, mixed>> $facets */
    private static function subcategories(?int $head, array $facets, array $selected, string $search, bool $showErotica): string
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
                $active = in_array($code, $selected, true) ? ' active' : '';
                $count = '<span class="side-count">' . (int) ($counts[$code] ?? 0) . '</span>';
                $html .= '<a class="side-link' . $active . '"' . ($active !== '' ? ' aria-current="page"' : '') . ' href="'
                    . h('/spots?' . http_build_query($base + ['subcategory' => implode(',', SpotFilters::toggle($selected, $code))])) . '">' . h($label) . $count . '</a>';
            }
            $html .= '</details>';
        }
        return $html;
    }
}
