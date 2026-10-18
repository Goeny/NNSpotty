<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;

final class SpotsPageRenderer
{
    /** @param list<array<string, mixed>> $categories
     *  @param list<array<string, mixed>> $spots
     *  @param list<array<string, mixed>> $facets
     *  @param array<string, mixed>|null $preview
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $categories,
        array $spots,
        ?string $selectedCategory,
        int $currentPage,
        int $totalPages,
        int $totalItems,
        string $csrfField,
        array $facets = [],
        ?string $selectedSubcategory = null,
        string $search = '',
        ?array $preview = null,
        string $theme = 'default'
    ): string {
        $query = array_filter(['category' => $selectedCategory, 'subcategory' => $selectedSubcategory, 'q' => $search], static fn ($value): bool => $value !== null && $value !== '');
        $link = static fn (array $changes): string => h('/spots?' . http_build_query(array_merge($query, $changes)));
        $heading = h($selectedCategory ?? 'Alle spots');
        $pills = '<a class="' . ($selectedCategory === null ? 'active' : '') . '" href="/spots">Alle</a>';
        foreach ($categories as $entry) {
            $pills .= '<a class="' . ($selectedCategory === $entry['category'] ? 'active' : '') . '" href="'
                . h('/spots?' . http_build_query(['category' => $entry['category'], 'q' => $search])) . '">' . h($entry['category']) . '</a>';
        }
        $options = '<option value="">Alle subcategorieen</option>';
        $groups = [];
        foreach ($facets as $facet) {
            $code = (string) $facet['code'];
            $label = SpotCategories::label($code);
            if ($label === $code) {
                continue;
            }
            $head = (int) $code[0];
            $group = SpotCategories::HEADS[$head] . ' / ' . (SpotCategories::dimensions($head)[$code[2]] ?? 'Overig');
            $selected = $code === $selectedSubcategory ? ' selected' : '';
            $groups[$group][] = '<option value="' . h($code) . '"' . $selected . '>' . h($label) . ' (' . (int) $facet['total'] . ')</option>';
        }
        foreach ($groups as $group => $items) {
            $options .= '<optgroup label="' . h($group) . '">' . implode('', $items) . '</optgroup>';
        }
        $categoryInput = $selectedCategory !== null ? '<input type="hidden" name="category" value="' . h($selectedCategory) . '">' : '';
        $safeSearch = h($search);
        $rows = '';
        foreach ($spots as $spot) {
            $details = SpotCategories::describe($spot);
            $format = implode(', ', $details['Formaat'] ?? $details['Platform'] ?? []);
            $genre = implode(', ', $details['Genre'] ?? $details['Soort applicatie'] ?? []);
            $subtitle = implode(' · ', array_filter([$spot['browse_type'] ?? $spot['category'], implode(', ', $details['Bron'] ?? [])]));
            $selected = ($preview['id'] ?? '') === $spot['id'] ? ' class="selected"' : '';
            $previewLink = $link(['page' => $currentPage, 'spot' => $spot['id']]) . '#preview';
            $rows .= '<tr' . $selected . '><td class="cover-cell">' . SpotPresentation::image($spot, true) . '</td>'
                . '<td><a class="spot-title" href="' . $previewLink . '">' . h($spot['title']) . '</a><div class="spot-subtitle">' . h($subtitle) . '</div></td>'
                . '<td>' . ($format !== '' ? '<span class="format-badge">' . h($format) . '</span>' : '&mdash;') . '</td>'
                . '<td>' . h($genre) . '</td><td>' . h($spot['poster']) . '</td>'
                . '<td class="text-nowrap">' . h(substr($spot['posted_at'], 0, 16)) . '</td>'
                . '<td class="text-nowrap">' . SpotPresentation::size((int) $spot['size']) . '</td>'
                . '<td><a class="btn btn-sm btn-outline-primary" href="/spots/view?key=' . rawurlencode($spot['id']) . '">Details</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8" class="p-4 text-body-secondary">Geen zichtbare spots voor deze filters. Wis je filters of controleer de indexing via het beheerpanel.</td></tr>';
        }
        $previous = $link(['page' => max(1, $currentPage - 1)]);
        $next = $link(['page' => min($totalPages, $currentPage + 1)]);
        $previousDisabled = $currentPage <= 1 ? ' disabled' : '';
        $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
        $previewHtml = $preview !== null
            ? SpotDetailPageRenderer::summary($preview, true)
            : '<div class="card card-body text-body-secondary">Selecteer een spot om de omschrijving en details te bekijken.</div>';
        $content = <<<HTML
<div class="page-heading">
  <div><h1 class="h2 mb-1">{$heading}</h1><span class="text-body-secondary">{$totalItems} individuele spots</span></div>
</div>
<nav class="category-pills" aria-label="Spotcategorieen">{$pills}</nav>
<form class="filter-bar" method="get" action="/spots">
  {$categoryInput}
  <label for="subcategory" class="visually-hidden">Subcategorie</label>
  <select id="subcategory" name="subcategory" class="form-select form-select-sm">{$options}</select>
  <label for="filter-search" class="visually-hidden">Zoek in titels</label>
  <input id="filter-search" class="form-control form-control-sm" name="q" value="{$safeSearch}" type="search" maxlength="200" placeholder="Zoek in titels">
  <button class="btn btn-primary btn-sm" type="submit">Filteren</button>
  <a class="btn btn-outline-secondary btn-sm" href="/spots">Wis filters</a>
</form>
<div class="browse-grid">
  <section class="card" aria-label="Spotlijst">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 spot-table">
        <thead><tr><th><span class="visually-hidden">Cover</span></th><th>Titel</th><th>Formaat</th><th>Genre</th><th>Spotter</th><th>Geplaatst (UTC)</th><th>Grootte</th><th></th></tr></thead>
        <tbody>{$rows}</tbody>
      </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center gap-2">
      <span>Pagina {$currentPage} van {$totalPages}</span>
      <nav class="btn-group" aria-label="Paginering">
        <a class="btn btn-outline-secondary btn-sm{$previousDisabled}" href="{$previous}">Vorige</a>
        <a class="btn btn-outline-secondary btn-sm{$nextDisabled}" href="{$next}">Volgende</a>
      </nav>
    </div>
  </section>
  <aside class="preview" id="preview" aria-label="Geselecteerde spot">{$previewHtml}</aside>
</div>
HTML;
        return SpotLayout::render('Spots', $username, $isAdmin, $csrfField, $content, $categories, $selectedCategory, $theme);
    }
}
