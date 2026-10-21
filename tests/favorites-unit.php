<?php

declare(strict_types=1);

$favoriteSpot = $spot;
$favoriteSpot['is_favorite'] = false;
$favoriteButton = \NnSpotty\Favorites\View::button($favoriteSpot);
check(str_contains($favoriteButton, 'aria-pressed="false"') && str_contains($favoriteButton, 'Toevoegen aan favorieten')
    && str_contains($favoriteButton, 'data-favorite="' . $spot['id'] . '"') && str_contains($favoriteButton, 'favorite-star'),
    'Favorietknop toont toegankelijke ongeselecteerde bladwijzer met ster');
$favoriteSpot['is_favorite'] = true;
check(str_contains(\NnSpotty\Favorites\View::button($favoriteSpot), 'is-favorite')
    && str_contains(\NnSpotty\Favorites\View::button($favoriteSpot), 'aria-pressed="true"')
    && str_contains(\NnSpotty\Favorites\View::button($favoriteSpot), 'Verwijderen uit favorieten'),
    'Favorietknop heeft geselecteerde kleurklasse en duidelijke verwijderactie');
$favoriteSpot['id'] = '"><script>invalid()</script>';
check(!str_contains(\NnSpotty\Favorites\View::button($favoriteSpot), '<script>'), 'Favorietknop ontsnapt invoer');
unset($favoriteSpot['is_favorite']);
check(\NnSpotty\Favorites\View::button($favoriteSpot) === '', 'Gedeelde renderers zonder gebruikerscontext publiceren geen gegokte favorietstatus');
$favoriteSpot = $spot + ['is_favorite' => true];
$favoritePage = \NnSpotty\UI\SpotsPageRenderer::render('favorite-fixture', false, [$favoriteSpot], 'Beeld', '<input name="_csrf" value="fixture">',
    facets: null, filters: ['0:a1'], search: 'Example', nextCursor: 'fixture-cursor', favoritesOnly: true);
check(str_contains($favoritePage, 'Favorieten - Beeld') && str_contains($favoritePage, 'data-favorites-only="1"')
    && str_contains($favoritePage, 'name="favorites" value="1"') && str_contains($favoritePage, '/assets/favorites.js')
    && str_contains($favoritePage, 'data-favorite="' . $spot['id'] . '"'),
    'Favorietenoverzicht deelt bestaande lijst en houdt zoekscope en interactieve knoppen');
preg_match_all('/href="([^"]+)"/', $favoritePage, $favoriteLinks);
$favoriteLinksScoped = true;
foreach ($favoriteLinks[1] as $favoriteLink) {
    $url = html_entity_decode($favoriteLink, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!str_starts_with($url, '/spots?') || str_contains($url, 'key=')) continue;
    // Main category links intentionally leave favorites for the regular overview.
    if (preg_match('/^\/spots\?(?:category=[^&]+&)?q=[^&]*$/D', $url)) {
        $mainCategoryLinks[] = $url;
        continue;
    }
    $favoriteLinksScoped = $favoriteLinksScoped && str_contains($url, 'favorites=1');
}
check($favoriteLinksScoped, 'Subcategorie-, filter-, verwijder- en vervolg-URLs behouden favorietenscope');
check(count($mainCategoryLinks ?? []) === count(\NnSpotty\Usenet\SpotCategories::HEADS) + 1 && str_contains($favoritePage, 'href="/spots?q=Example">Alle</a>'),
    'Hoofdcategorieen en Alle vanuit favorieten openen het reguliere overzicht');
check(str_contains(\NnSpotty\UI\SpotsPageRenderer::rows([], ['title'], true), 'Markeer spots via de bladwijzer'),
    'Lege favorieten geven persoonlijke uitleg in plaats van onterechte indexingwaarschuwing');
check(str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($favoriteSpot, true), 'aria-pressed="true"')
    && str_contains(\NnSpotty\UI\SpotDetailPageRenderer::summary($favoriteSpot, false), 'data-favorite='),
    'Favorietknop verschijnt gedeeld in originele preview en detail');
