<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;
use NnSpotty\Usenet\SpotFilters;

final class SpotsPageRenderer
{
    /** @param list<array<string, mixed>> $spots
     *  @param list<array<string, mixed>> $facets
     *  @param list<string> $filters
     *  @param list<string> $columns
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $spots,
        ?string $selectedCategory,
        int $totalItems,
        string $csrfField,
        array $facets = [],
        array $filters = [],
        string $search = '',
        string $theme = 'default',
        bool $showErotica = false,
        array $columns = ['cover', 'title', 'format', 'poster', 'size'],
        ?string $nextCursor = null
    ): string {
        $query = ['category' => $selectedCategory, 'subcategory' => implode(',', $filters), 'q' => $search];
        $heading = h($selectedCategory ?? 'Alle spots');
        $categoryInput = $selectedCategory !== null ? '<input type="hidden" name="category" value="' . h($selectedCategory) . '">' : '';
        $filterInput = '<input type="hidden" name="subcategory" value="' . h(implode(',', $filters)) . '">';
        $activeFilters = '';
        foreach ($filters as $filter) {
            $remove = '/spots?' . http_build_query(array_merge($query, ['subcategory' => implode(',', SpotFilters::toggle($filters, $filter))]));
            $activeFilters .= '<a class="tag-chip" href="' . h($remove) . '" aria-label="Verwijder filter ' . h(SpotCategories::label($filter)) . '">'
                . h(SpotCategories::label($filter)) . ' &times;</a>';
        }
        $safeSearch = h($search);
        $rows = self::rows($spots, $columns);
        $headers = '';
        foreach ($columns as $column) {
            $headers .= '<th scope="col" class="column-' . $column . '">' . h(SpotColumns::LABELS[$column]) . '</th>';
        }
        $nextUrl = h(self::nextUrl($query, $nextCursor) ?? '');
        $fallback = $nextCursor === null ? '' : '<noscript><a class="btn btn-outline-primary" href="'
            . h('/spots?' . http_build_query(array_merge($query, ['cursor' => $nextCursor]))) . '">Volgende resultaten</a></noscript>';
        $loaded = count($spots);
        $endText = $nextCursor === null ? 'Alle resultaten geladen.' : '';
        $content = <<<HTML
<div class="page-heading">
  <div><h1 class="h2 mb-1">{$heading}</h1><span class="text-body-secondary">{$totalItems} individuele spots</span></div>
  <a class="btn btn-sm btn-outline-secondary" href="/preferences#columns">Kolommen instellen</a>
</div>
<form class="filter-bar" method="get" action="/spots">
  {$categoryInput}{$filterInput}
  <label for="filter-search" class="visually-hidden">Zoek in titels</label>
  <input id="filter-search" class="form-control form-control-sm" name="q" value="{$safeSearch}" type="search" maxlength="200" placeholder="Zoek in titels">
  <button class="btn btn-primary btn-sm" type="submit">Filteren</button>
  <a class="btn btn-outline-secondary btn-sm" href="/spots">Wis filters</a>
  <div class="active-filters">{$activeFilters}</div>
</form>
<div class="browse-grid" data-spot-browser>
  <section class="card spot-list" aria-label="Spotlijst">
    <div class="table-responsive" id="spot-scroll">
      <table class="table table-hover align-middle mb-0 spot-table">
        <thead><tr>{$headers}</tr></thead>
        <tbody id="spot-rows">{$rows}</tbody>
      </table>
      <div id="load-sentinel" class="load-sentinel">
        <p id="load-status" class="small text-body-secondary mb-2" role="status">{$endText}</p>
        <button id="load-more" class="btn btn-sm btn-outline-primary" data-next="{$nextUrl}" type="button" hidden>Meer resultaten laden</button>
        {$fallback}
        <div id="load-error" class="alert alert-warning mt-2" role="alert" hidden></div>
      </div>
    </div>
    <div class="card-footer small text-body-secondary"><span id="loaded-count">{$loaded}</span> resultaten geladen</div>
  </section>
  <aside class="preview-drawer" id="preview" aria-label="Geselecteerde spot" aria-hidden="true" inert>
    <div class="preview-toolbar">
      <h2 class="h6 mb-0">Spotdetails</h2>
      <button id="close-preview" class="btn btn-sm btn-outline-secondary" type="button" aria-label="Spotdetails sluiten">Sluiten &times;</button>
    </div>
    <div class="preview" id="preview-content" tabindex="0">
      <p class="text-body-secondary" role="status">Selecteer een spot.</p>
    </div>
  </aside>
</div>
HTML;
        return SpotLayout::render('Spots', $username, $isAdmin, $csrfField, $content, selected: $selectedCategory, theme: $theme,
            facets: $facets, selectedSubcategory: $filters, search: $search, showErotica: $showErotica, browse: true);
    }

    /** @param array<string, mixed> $query */
    public static function nextUrl(array $query, ?string $cursor): ?string
    {
        return $cursor === null ? null : '/spots?' . http_build_query(array_merge($query, ['batch' => '1', 'cursor' => $cursor]));
    }

    /** @param list<array<string, mixed>> $spots
     *  @param list<string> $columns
     */
    public static function rows(array $spots, array $columns): string
    {
        $columns = SpotColumns::validate($columns);
        if ($spots === []) {
            return '<tr class="empty-spots"><td colspan="' . count($columns) . '" class="p-4 text-body-secondary">Geen zichtbare spots voor deze filters. Wis je filters of controleer de indexing via het beheerpanel.</td></tr>';
        }
        $rows = '';
        foreach ($spots as $spot) {
            $details = SpotCategories::describe($spot);
            $format = implode(', ', $details['Formaat'] ?? $details['Platform'] ?? []);
            $genre = implode(', ', $details['Genre'] ?? $details['Soort applicatie'] ?? []);
            $subtitle = implode(' · ', array_filter([$spot['browse_type'] ?? $spot['category'], implode(', ', $details['Bron'] ?? [])]));
            $detailUrl = '/spots/view?key=' . rawurlencode($spot['id']);
            $values = [
                'cover' => SpotPresentation::image($spot, true),
                'title' => '<a class="spot-title" data-preview="' . h($spot['id']) . '" href="' . $detailUrl . '" aria-controls="preview" aria-expanded="false">'
                    . h($spot['title']) . '</a><div class="spot-subtitle">' . h($subtitle) . '</div>',
                'format' => $format !== '' ? '<span class="format-badge">' . h($format) . '</span>' : '&mdash;',
                'genre' => h($genre),
                'poster' => h($spot['poster']),
                'posted_at' => h(substr($spot['posted_at'], 0, 16)),
                'size' => SpotPresentation::size((int) $spot['size']),
            ];
            $rows .= '<tr data-spot-id="' . h($spot['id']) . '">';
            foreach ($columns as $column) {
                $rows .= '<td class="column-' . $column . '" data-label="' . h(SpotColumns::LABELS[$column]) . '">' . $values[$column] . '</td>';
            }
            $rows .= '</tr>';
        }
        return $rows;
    }
}
