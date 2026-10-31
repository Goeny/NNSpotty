<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;
use NnSpotty\Usenet\SpotFilters;

final class SpotLayout
{
    private static ?array $account = null;
    /** @var (callable(): ?array)|null */
    private static $downloadResolver = null;
    private static ?array $downloadOverview = null;

    public static function account(?array $account): void
    {
        self::$account = $account;
    }

    /** @param callable(): ?array $resolver Lazily returns Targets::overview() for the current user, or null. */
    public static function downloads(callable $resolver): void
    {
        self::$downloadResolver = $resolver;
        self::$downloadOverview = null;
    }

    /** @return array{targets: list<array{id: string, label: string, client: string, enabled: bool}>, default: string} */
    public static function downloadTargets(bool $admin): array
    {
        if (self::$downloadOverview === null && self::$downloadResolver !== null) {
            self::$downloadOverview = (self::$downloadResolver)();
        }
        if (self::$downloadOverview !== null) {
            return self::$downloadOverview;
        }
        $targets = array_map(static fn (string $target): array => ['id' => $target, 'label' => \NnSpotty\Downloader\Targets::label($target),
            'client' => explode(':', $target)[1], 'enabled' => false], \NnSpotty\Downloader\Targets::permitted($admin));
        return ['targets' => $targets, 'default' => 'personal:sabnzbd'];
    }

    /** Send button for the default target, with a compact menu for other enabled targets. */
    public static function sendButton(string $spotId): string
    {
        $overview = self::downloadTargets((bool) (self::$account['is_admin'] ?? false));
        $default = $overview['default'];
        $label = 'Verzenden naar ' . \NnSpotty\Downloader\Targets::label($default);
        $icon = '<svg class="send-icon" viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 1a.75.75 0 0 1 .75.75v6.69l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 0 1 1.06-1.06l2.22 2.22V1.75A.75.75 0 0 1 8 1Z"/><path fill="currentColor" d="M2.75 11a.75.75 0 0 1 .75.75v1.5h9v-1.5a.75.75 0 0 1 1.5 0v2.25a.75.75 0 0 1-.75.75H2.75a.75.75 0 0 1-.75-.75v-2.25a.75.75 0 0 1 .75-.75Z"/></svg>';
        $button = '<button class="btn btn-sm btn-warning send-button" type="button" data-download-send="' . h($spotId) . '" data-download-target="' . h($default)
            . '" title="' . h($label) . '" aria-label="Client: ' . h($label) . '">' . $icon . '<span>Client</span></button>';
        $others = array_filter($overview['targets'], static fn (array $target): bool => $target['enabled'] && $target['id'] !== $default);
        if ($others === []) {
            return $button;
        }
        $menu = '';
        foreach ($others as $target) {
            $menu .= '<button class="dropdown-link" type="button" data-download-send="' . h($spotId) . '" data-download-target="' . h($target['id']) . '">'
                . 'Verzenden naar ' . h($target['label']) . '</button>';
        }
        return '<span class="send-group">' . $button . '<details class="send-menu"><summary class="btn btn-sm btn-warning" aria-label="Verzenden naar andere downloadclient" title="Verzenden naar...">'
            . '<span aria-hidden="true">&#9662;</span></summary><div class="send-menu-dropdown">' . $menu . '</div></details></span>';
    }

    public static function currentAccount(): ?array
    {
        return self::$account;
    }

    public static function can(string $permission): bool
    {
        return self::$account === null || \NnSpotty\Users\Permissions::allows(self::$account, $permission);
    }

    /** @param list<array<string, mixed>> $categories
     *  @param list<array<string, mixed>>|null $facets
     *  @param list<string>|null $available Subcategory codes that still yield results; null shows every code.
     */
    public static function render(string $title, string $username, bool $isAdmin, string $csrfField, string $content, array $categories = [], ?string $selected = null, string $theme = 'default', ?array $facets = [], string|array|null $selectedSubcategory = null, string $search = '', bool $showErotica = false, bool $browse = false, bool $favoritesOnly = false, ?array $available = null, string $section = ''): string
    {
        $mode = $theme === 'plex-light' ? 'light' : 'dark';
        $head = SpotCategories::headForCategory($selected);
        $favoriteQuery = $favoritesOnly ? ['favorites' => '1'] : [];
        // Main categories always lead to the regular overview, also when coming from favorites.
        $allUrl = '/spots' . ($search === '' ? '' : '?' . http_build_query(['q' => $search]));
        $allActive = $head === null && !$favoritesOnly && $section === '';
        $mainNavigation = '<a class="side-link' . ($allActive ? ' active' : '') . '" href="'
            . h($allUrl) . '"' . ($allActive ? ' aria-current="page"' : '') . '>Alle</a>';
        foreach (SpotCategories::HEADS as $index => $category) {
            $active = $index === $head && !$favoritesOnly ? ' active' : '';
            $mainNavigation .= '<a class="side-link' . $active . '"' . ($active !== '' ? ' aria-current="page"' : '') . ' href="'
                . h('/spots?' . http_build_query(['category' => $category, 'q' => $search])) . '">' . h($category) . '</a>';
        }
        $filters = is_array($selectedSubcategory) ? $selectedSubcategory : SpotFilters::parse($selectedSubcategory);
        $navigation = self::subcategories($head, $facets, $filters, $search, $showErotica, $favoritesOnly, $available);
        $favoritesNavigation = '<nav class="mb-3" aria-label="Mijn spots"><p class="nav-caption">Mijn spots</p>'
            . '<a class="side-link' . ($favoritesOnly ? ' active' : '') . '" href="/spots?favorites=1"'
            . ($favoritesOnly ? ' aria-current="page"' : '') . '>Favorieten</a>'
            . '<a class="side-link' . ($section === 'history' ? ' active' : '') . '" href="/history"'
            . ($section === 'history' ? ' aria-current="page"' : '') . '>Historie</a>';
        if (self::$account !== null ? \NnSpotty\Users\Permissions::allows(self::$account, 'statistics.view') : $isAdmin) {
            $favoritesNavigation .= '<a class="side-link' . ($section === 'statistics' ? ' active' : '') . '" href="/statistics"'
                . ($section === 'statistics' ? ' aria-current="page"' : '') . '>Statistieken</a>';
        }
        $favoritesNavigation .= '</nav>';
        $admin = $isAdmin ? '<a class="dropdown-link" href="/admin">Beheerpanel</a>' : '';
        $safeTitle = h($title);
        $safeUsername = h($username);
        $account = self::$account !== null && self::$account['username'] === $username ? self::$account : null;
        if ($account !== null) {
            $isAdmin = (bool) $account['is_admin'];
            $safeUsername = h($account['display_name']);
            if ($account['has_avatar']) $safeUsername = '<img class="account-avatar" src="/account/avatar?user=' . rawurlencode($username)
                . '" width="28" height="28" alt="" referrerpolicy="no-referrer"> ' . $safeUsername;
            $admin = \NnSpotty\Users\Permissions::allows($account, 'settings.manage') ? '<a class="dropdown-link" href="/admin">Beheerpanel</a>' : '';
            foreach (['users.manage', 'groups.manage', 'audit.view', 'spotters.manage'] as $permission) {
                if (\NnSpotty\Users\Permissions::allows($account, $permission)) {
                    $admin .= '<a class="dropdown-link" href="/manage">Gebruikers, groepen en moderatie</a>';
                    break;
                }
            }
        }
        $safeSearch = h($search);
        $userMenu = '<details class="user-menu"><summary class="account-link">' . $safeUsername . '</summary><div class="user-menu-dropdown">'
            . '<a class="dropdown-link" href="/preferences#profile">Profiel</a><a class="dropdown-link" href="/preferences">Voorkeuren</a>' . $admin . '</div></details>';
        $categoryInput = $head !== null ? '<input type="hidden" name="category" value="' . h(SpotCategories::HEADS[$head]) . '">' : '';
        $filterInput = $filters !== [] ? '<input type="hidden" name="subcategory" value="' . h(implode(',', $filters)) . '">' : '';
        $favoriteInput = $favoritesOnly ? '<input type="hidden" name="favorites" value="1">' : '';
        $contentClass = $browse ? 'content browse-page' : 'content';
        $scripts = $browse ? '<script src="/assets/spots.js" defer></script>' : '';
        $facetAttributes = '';
        if ($head !== null && $facets === null) {
            $facetAttributes = ' data-facets-url="' . h('/spots/facets?' . http_build_query([
                'category' => $selected, 'subcategory' => implode(',', $filters), 'q' => $search,
            ] + $favoriteQuery)) . '"';
            $scripts .= '<script src="/assets/facets.js" defer></script>';
        }
        $retrieveForm = ($account !== null ? \NnSpotty\Users\Permissions::allows($account, 'retrieve.run') : $isAdmin)
            ? '<form method="post" action="/admin/retrieve" data-retrieve-form>' . $csrfField
                . '<button class="btn btn-sm btn-outline-primary" type="submit">Retrieve</button></form>'
            : '';
        $downloadOverview = $account === null || \NnSpotty\Users\Permissions::allows($account, 'nzb.forward')
            ? self::downloadTargets($isAdmin) : null;
        $connectionOptions = '';
        foreach ($downloadOverview['targets'] ?? [] as $target) {
            $connectionOptions .= '<option value="' . h($target['id']) . '"' . ($target['id'] === $downloadOverview['default'] ? ' selected' : '') . '>'
                . h($target['label']) . ($target['enabled'] ? '' : ' (niet ingeschakeld)') . '</option>';
        }
        $downloadButton = $downloadOverview !== null
            ? '<button class="btn btn-sm btn-outline-secondary" type="button" data-download-open="' . h($downloadOverview['default']) . '">Downloads</button>' : '';
        $releaseLabel = h(\NnSpotty\Config\Release::VERSION) . ' &middot; ' . h(\NnSpotty\Config\Release::CODENAME);
        $updateNotice = ($account !== null ? \NnSpotty\Users\Permissions::allows($account, 'system.update') : $isAdmin)
            ? '<a class="small d-block mt-1" href="/system/update" data-update-notice>Releases controleren</a>' : '';
        $updateScript = $updateNotice !== '' ? '<script src="/assets/updater.js" defer></script>' : '';
        return <<<HTML
<!doctype html>
<html lang="nl" data-bs-theme="{$mode}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle} - NNSpotty</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/spotty.css" rel="stylesheet">
  <script src="/assets/presentation.js" defer></script>
  <script src="/assets/downloader.js" defer></script>
  <script src="/assets/metadata.js" defer></script>
  <script src="/assets/retrieve.js" defer></script>
  <script src="/assets/favorites.js" defer></script>
  <script src="/assets/comments.js" defer></script>
  {$updateScript}
  {$scripts}
</head>
<body class="spotty">
  <a class="visually-hidden-focusable skip-link" href="#content">Naar inhoud</a>
  <header class="topbar">
    <div class="header-menu">
      <a class="brand" href="/spots"><img class="brand-mark" src="/assets/images/brand-icon.png" width="34" height="34" alt="">NNSpotty</a>
      <div class="retrieve-control" data-retrieve-control>
        {$retrieveForm}
        <div class="retrieve-status">
          <span class="small text-body-secondary" data-retrieve-last>Laatste retrieve: laden...</span>
          <span class="small retrieve-status-message" role="status" aria-live="polite">
            <span class="spinner-border spinner-border-sm me-1" data-retrieve-indicator aria-hidden="true" hidden></span>
            <span data-retrieve-state></span>
          </span>
        </div>
      </div>
      {$userMenu}
      {$downloadButton}
    </div>
    <form method="get" action="/spots" role="search" class="global-search">
      {$categoryInput}
      {$filterInput}
      {$favoriteInput}
      <label for="global-search" class="visually-hidden">Zoek in spottitels</label>
      <input id="global-search" class="form-control form-control-sm" name="q" value="{$safeSearch}" type="search" maxlength="200" placeholder="Zoek in films, series, muziek, games...">
      <button class="btn btn-primary btn-sm" type="submit">Zoek</button>
    </form>
  </header>
  <aside class="sidebar" aria-label="Spotnavigatie">
    {$favoritesNavigation}
    <nav aria-label="Hoofdcategorieen">
      <p class="nav-caption">Hoofdcategorieen</p>
      {$mainNavigation}
    </nav>
    <nav aria-label="Subcategorieen"{$facetAttributes}>
      {$navigation}
    </nav>
    <div class="sidebar-footer">
      <span class="small text-body-secondary">{$releaseLabel}</span>
      {$updateNotice}
      <form method="post" action="/logout" class="mt-3">{$csrfField}<button class="btn btn-sm btn-outline-secondary w-100" type="submit">Uitloggen</button></form>
    </div>
  </aside>
  <div class="workspace">
    <main id="content" class="{$contentClass}" tabindex="-1">{$content}</main>
    <div class="alert alert-info alert-dismissible favorite-notice" data-favorite-notice role="status" hidden>
      <span data-favorite-message></span>
      <button class="btn-close" type="button" data-favorite-dismiss aria-label="Melding sluiten"></button>
    </div>
  </div>
  <dialog class="image-lightbox" id="image-lightbox" aria-labelledby="lightbox-title">
    <div class="lightbox-toolbar">
      <h2 class="h6 mb-0" id="lightbox-title">Afbeelding</h2>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-close-lightbox>Sluiten &times;</button>
    </div>
    <div class="lightbox-body">
      <p class="small text-body-secondary" data-lightbox-status role="status">Afbeelding laden...</p>
      <div class="alert alert-warning" data-lightbox-error role="alert" hidden>De afbeelding kon niet worden geladen. Probeer opnieuw of bekijk een andere afbeelding.</div>
      <img class="lightbox-image" alt="" referrerpolicy="no-referrer">
    </div>
    <div class="lightbox-navigation" data-lightbox-navigation hidden>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-lightbox-previous aria-label="Vorige afbeelding">&larr; Vorige</button>
      <span class="small" data-lightbox-counter role="status" aria-live="polite" aria-atomic="true"></span>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-lightbox-next aria-label="Volgende afbeelding">Volgende &rarr;</button>
    </div>
    <button class="btn btn-sm btn-outline-secondary lightbox-retry" type="button" data-lightbox-retry hidden>Opnieuw proberen</button>
  </dialog>
  <dialog class="download-drawer" id="download-drawer" aria-labelledby="download-title">
    <div class="download-toolbar">
      <h2 class="h5 m-0" id="download-title">Downloads</h2>
      <button class="btn btn-sm btn-outline-secondary" type="button" data-download-close>Sluiten &times;</button>
    </div>
    <div class="download-body">
      <label class="form-label" for="download-connection">Downloadclient</label>
      <select class="form-select mb-3" id="download-connection">{$connectionOptions}</select>
      <p class="small text-body-secondary">Je ziet de volledige wachtrij van de gekozen host. Bij verzenden kun je hier eenmalig een andere client kiezen. Centrale koppelingen zijn uitsluitend voor admins.</p>
      <div role="status" class="alert alert-info" data-download-message>Open het paneel om de wachtrij te laden.</div>
      <form data-download-confirm hidden class="card p-3 mb-3">
        {$csrfField}
        <h3 class="h6">Spot versturen</h3>
        <p data-download-spot-title></p>
        <p class="small">Naar: <strong data-download-client-name></strong> &middot; Categorie: <strong data-download-category></strong></p>
        <p class="small text-body-secondary">Bij een onzekere uitkomst eerst de downloadclient controleren om dubbele downloads te voorkomen.</p>
        <button class="btn btn-primary" type="submit">Bevestigen en verzenden</button>
        <button class="btn btn-outline-secondary mt-2" type="button" data-download-cancel>Annuleren</button>
      </form>
      <section class="card p-3 mb-3" aria-label="Downloadsnelheid">
        <div class="d-flex justify-content-between gap-2"><span data-download-state>Niet verbonden</span><strong data-download-speed>--</strong></div>
        <small class="text-body-secondary">Schaal: 0 - <span data-download-peak>--</span></small>
        <svg viewBox="0 0 480 100" class="download-chart mt-3" role="img" aria-label="Downloadsnelheid tijdens deze panelsessie; laatste vijf minuten">
          <path d="M0 99H480" class="chart-grid"></path><polyline points="" data-download-chart></polyline><circle cx="480" cy="99" r="3" data-download-dot hidden></circle>
        </svg>
        <div class="d-flex justify-content-between small text-body-secondary"><span>-5 minuten</span><span>Nu</span></div>
        <p class="small text-body-secondary mb-0">Live snelheid, laatste vijf minuten. Pauzeert als het paneel of tabblad gesloten is.</p>
      </section>
      <section aria-labelledby="download-queue-title"><h3 class="h6" id="download-queue-title">Wachtrij</h3><div data-download-queue></div>
        <div class="d-flex justify-content-between my-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-download-prev disabled>Vorige</button><span data-download-total></span><button type="button" class="btn btn-sm btn-outline-secondary" data-download-next disabled>Volgende</button></div>
      </section>
      <section class="mt-4"><h3 class="h6">Verzonden vanuit NNSpotty</h3><p class="small text-body-secondary">De laatste 30 pogingen op deze koppeling. Verzonden betekent geaccepteerd door de downloadclient, niet voltooid.</p><div data-download-history></div></section>
      <button class="btn btn-outline-secondary mt-3" type="button" data-download-refresh>Vernieuwen / opnieuw proberen</button>
      <a class="btn btn-link" href="/preferences#downloads">Mijn koppelingen instellen</a>
    </div>
  </dialog>
</body>
</html>
HTML;
    }

    /** @param list<array<string, mixed>>|null $facets
     *  @param list<string>|null $available
     */
    private static function subcategories(?int $head, ?array $facets, array $selected, string $search, bool $showErotica, bool $favoritesOnly = false, ?array $available = null): string
    {
        if ($head === null) {
            return '<p class="nav-caption">Subcategorieen</p><p class="small text-body-secondary px-2">Kies hierboven een hoofdcategorie om alle bijbehorende subcategorieen te bekijken.</p>';
        }
        $counts = array_column($facets ?? [], 'total', 'code');
        $category = SpotCategories::HEADS[$head];
        $base = ['category' => $category, 'q' => $search];
        if ($favoritesOnly) $base['favorites'] = '1';
        $html = '<p class="nav-caption">' . h($category) . '</p><a class="side-link" href="'
            . h('/spots?' . http_build_query($base)) . '">Alles in ' . h($category) . '</a>'
            . '<p class="small text-body-secondary px-2">Nieuw sinds je vorige bezoek. Tellers blijven tijdens deze browsersessie staan.</p>';
        if ($facets === null) {
            $html .= '<p class="small text-body-secondary px-2" data-facets-status role="status">Nieuwtellers laden...</p>'
                . '<button class="btn btn-sm btn-outline-secondary mb-2" data-facets-retry type="button" hidden>Opnieuw proberen</button>'
                . '<noscript><p class="small px-2">Nieuwtellers vereisen JavaScript; de filters werken zonder.</p></noscript>';
        }
        // Drill-down: only codes that still combine with the current selection are offered.
        $offered = $available === null ? null : array_fill_keys(array_merge($available, $selected), true);
        if ($offered !== null && $selected !== []) {
            $html .= '<p class="small text-body-secondary px-2">Alleen filters die nog resultaten opleveren worden getoond.</p>';
        }
        foreach (SpotCategories::dimensions($head) as $dimension => $name) {
            $links = '';
            foreach (SpotCategories::definitions()[$head][$dimension] as $number => $label) {
                $code = $head . ':' . $dimension . $number;
                $adult = $head === 0 && (($dimension === 'z' && $number === 3)
                    || ($dimension === 'd' && (($number >= 23 && $number <= 26) || ($number >= 72 && $number <= 89))));
                if (($adult && !$showErotica) || ($offered !== null && !isset($offered[$code]))) {
                    continue;
                }
                $active = in_array($code, $selected, true) ? ' active' : '';
                $countLabel = $facets === null ? 'Nieuwteller wordt geladen' : (int) ($counts[$code] ?? 0) . ' nieuw sinds je vorige bezoek';
                $count = '<span class="side-count" data-facet-count="' . h($code) . '" title="' . h($countLabel) . '" aria-label="'
                    . h($countLabel) . '">' . ($facets === null ? '&hellip;' : (int) ($counts[$code] ?? 0)) . '</span>';
                $links .= '<a class="side-link' . $active . '"' . ($active !== '' ? ' aria-current="page"' : '') . ' href="'
                    . h('/spots?' . http_build_query($base + ['subcategory' => implode(',', SpotFilters::toggle($selected, $code))])) . '">' . h($label) . $count . '</a>';
            }
            if ($links !== '') {
                $html .= '<details class="category-group" open><summary>' . h($name) . '</summary>' . $links . '</details>';
            }
        }
        return $html;
    }
}
