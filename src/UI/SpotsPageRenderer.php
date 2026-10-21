<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;
use NnSpotty\Usenet\SpotFilters;

final class SpotsPageRenderer
{
    /** @param list<array<string, mixed>> $spots
     *  @param list<array<string, mixed>>|null $facets
     *  @param list<string> $filters
     *  @param list<string> $columns
     *  @param list<string>|null $available Subcategory codes that still yield results; null shows every code.
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $spots,
        ?string $selectedCategory,
        string $csrfField,
        ?array $facets = [],
        array $filters = [],
        string $search = '',
        string $theme = 'default',
        bool $showErotica = false,
        array $columns = ['cover', 'title', 'format', 'poster', 'size'],
        ?string $nextCursor = null,
        bool $favoritesOnly = false,
        ?array $available = null
    ): string {
        $query = ['category' => $selectedCategory, 'subcategory' => implode(',', $filters), 'q' => $search];
        if ($favoritesOnly) $query['favorites'] = '1';
        $heading = h($favoritesOnly ? 'Favorieten' . ($selectedCategory === null ? '' : ' - ' . $selectedCategory) : ($selectedCategory ?? 'Alle spots'));
        $activeFilters = '';
        foreach ($filters as $filter) {
            $remove = '/spots?' . http_build_query(array_merge($query, ['subcategory' => implode(',', SpotFilters::toggle($filters, $filter))]));
            $activeFilters .= '<a class="tag-chip" href="' . h($remove) . '" aria-label="Verwijder filter ' . h(SpotCategories::label($filter)) . '">'
                . h(SpotCategories::label($filter)) . ' &times;</a>';
        }
        $rows = self::rows($spots, $columns, $favoritesOnly);
        $headers = '';
        foreach ($columns as $column) {
            $headers .= '<th scope="col" class="column-' . $column . '">' . h(SpotColumns::LABELS[$column]) . '</th>';
        }
        $nextUrl = h(self::nextUrl($query, $nextCursor) ?? '');
        $fallback = $nextCursor === null ? '' : '<noscript><a class="btn btn-outline-primary" href="'
            . h('/spots?' . http_build_query(array_merge($query, ['cursor' => $nextCursor]))) . '">Volgende resultaten</a></noscript>';
        $loaded = count($spots);
        $endText = $nextCursor === null ? 'Alle resultaten geladen.' : '';
        $browseUser = h($username);
        $headingActions = '';
        if ($favoritesOnly) $headingActions .= '<a class="btn btn-sm btn-outline-secondary" href="/spots">Alle spots</a>';
        if ($filters !== []) {
            $headingActions .= '<div class="active-filters">' . $activeFilters . '</div>';
        }
        if ($selectedCategory !== null || $filters !== [] || $search !== '') {
            $headingActions .= '<a class="btn btn-sm btn-outline-secondary" href="' . ($favoritesOnly ? '/spots?favorites=1' : '/spots') . '">Wis filters</a>';
        }
        $headingActions .= '<a class="btn btn-sm btn-outline-secondary" href="/preferences#columns">Kolommen instellen</a>';
        $content = <<<HTML
<div class="page-heading">
  <h1 class="h2 mb-0" tabindex="-1">{$heading}</h1>
  <div class="page-heading-actions">{$headingActions}</div>
</div>
<div class="browse-grid" data-spot-browser data-browse-user="{$browseUser}" data-favorites-only="{$favoritesOnly}">
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
            facets: $facets, selectedSubcategory: $filters, search: $search, showErotica: $showErotica, browse: true, favoritesOnly: $favoritesOnly, available: $available);
    }

    /** @param array<string, mixed> $query */
    public static function nextUrl(array $query, ?string $cursor): ?string
    {
        return $cursor === null ? null : '/spots?' . http_build_query(array_merge($query, ['batch' => '1', 'cursor' => $cursor]));
    }

    /** @param list<array<string, mixed>> $spots
     *  @param list<string> $columns
     */
    public static function rows(array $spots, array $columns, bool $favoritesOnly = false): string
    {
        $columns = SpotColumns::validate($columns);
        if ($spots === []) {
            $message = $favoritesOnly
                ? 'Geen favorieten voor deze filters. Markeer spots via de bladwijzer met ster of wis je filters.'
                : 'Geen zichtbare spots voor deze filters. Wis je filters of controleer de indexing via het beheerpanel.';
            return '<tr class="empty-spots"><td colspan="' . count($columns) . '" class="p-4 text-body-secondary">' . $message . '</td></tr>';
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
                'title' => '<div class="spot-title-line">' . \NnSpotty\Favorites\View::button($spot)
                    . '<div><a class="spot-title" data-preview="' . h($spot['id']) . '" href="' . $detailUrl . '" aria-controls="preview" aria-expanded="false">'
                    . h($spot['title']) . '</a><div class="spot-subtitle">' . h($subtitle) . '</div></div></div>',
                'format' => $format !== '' ? '<span class="format-badge">' . h($format) . '</span>' : '&mdash;',
                'genre' => h($genre),
                'poster' => h($spot['poster']) . \NnSpotty\Moderation\View::badge($spot),
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
