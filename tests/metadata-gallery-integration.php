<?php

declare(strict_types=1);

use NnSpotty\Metadata\Service as MetadataService;
use NnSpotty\Metadata\View as MetadataView;
use NnSpotty\UI\SpotDetailPageRenderer;

check($tmdbMeta['posters'] === ['https://image.tmdb.org/t/p/original/tmdb-poster.jpg',
    'https://image.tmdb.org/t/p/original/alternate-poster.jpg', 'https://artworks.thetvdb.com/banners/poster.jpg']
    && $tmdbMeta['backdrops'] === ['https://image.tmdb.org/t/p/original/tmdb-backdrop.jpg',
        'https://image.tmdb.org/t/p/original/alternate-backdrop.jpg', 'https://artworks.thetvdb.com/banners/fanart.jpg'],
    'Galerijen combineren alle geldige TMDB- en TVDB-afbeeldingen, origineel formaat, zonder dubbelen of externe hosts');
$imageCalls = array_values(array_filter($tmdbApi->calls, static fn (array $call): bool => $call[0] === '/movie/603/images'));
check($imageCalls === [['/movie/603/images', []]], 'TMDB-artwork wordt eenmaal zonder taalfilter opgehaald en gecachet');
check($tmdbXpath->query('//div[@class="hero-buttons"]/a[@data-trailer-open]/following-sibling::a[1][@data-image-gallery="fanart"]/svg')->length === 1
    && $tmdbXpath->query('//div[@class="hero-poster"]//a[@data-image-gallery="posters"]')->length === 1
    && $tmdbXpath->query('//template[@data-image-gallery-items="posters"]/a')->length === 3
    && $tmdbXpath->query('//template[@data-image-gallery-items="fanart"]/a')->length === 3
    && $tmdbXpath->query('//template//img')->length === 0,
    'Mediaknop naast trailer en aparte poster/fanartgalerijen zonder vooraf geladen extra afbeeldingen');
$withoutTrailers = array_replace($tmdbMeta, ['trailers' => []]);
check(str_contains(MetadataView::hero($tmdbMovie, $withoutTrailers, '', true), 'data-image-gallery="fanart"')
    && !str_contains(MetadataView::hero($tmdbMovie, $withoutTrailers, '', true), 'data-trailer-open'),
    'Mediaknop werkt onafhankelijk van de beschikbaarheid van trailers');
$posterOnly = array_replace($tmdbMeta, ['fanart' => '', 'backdrops' => [], 'posters' => []]);
check(str_contains(MetadataView::hero($tmdbMovie, $posterOnly, '', true), 'data-image-gallery="posters"')
    && !str_contains(MetadataView::hero($tmdbMovie, $posterOnly, '', true), 'data-image-gallery="fanart"'),
    'Een enkele gekozen poster blijft vergrootbaar zonder lege fanartgalerij');
$beforeGallery = [count($tmdbApi->calls), count($tvdbApi->calls)];
$tmdbService->lookup($tmdbMovie);
check($beforeGallery === [count($tmdbApi->calls), count($tvdbApi->calls)], 'Galerij hergebruikt gedeelde matchcache zonder extra requests');
foreach ([false, true] as $galleryPreview) {
    $noImages = SpotDetailPageRenderer::summary($tmdbMovie, $galleryPreview, false, true, $tmdbMeta);
    check(!str_contains($noImages, 'data-image-gallery') && !str_contains($noImages, 'image.tmdb.org')
        && !str_contains($noImages, 'artworks.thetvdb.com'), 'Afbeeldingen uit publiceert geen galerij of artwork-URLs, preview=' . (int) $galleryPreview);
}
$previewGallery = SpotDetailPageRenderer::summary($tmdbMovie, true, true, true, $tmdbMeta);
check(!str_contains($previewGallery, 'data-image-gallery') && !str_contains($previewGallery, 'alternate-poster')
    && !str_contains($previewGallery, 'alternate-backdrop'), 'Preview bevat alleen gewone artwork, geen galerijlijsten of mediaknop');
$emptyGallery = array_replace($tmdbMeta, ['poster' => '', 'fanart' => '', 'posters' => [], 'backdrops' => []]);
check(!str_contains(MetadataView::hero($tmdbMovie, $emptyGallery, '', true), 'data-image-gallery'),
    'Zonder beschikbare artwork geen lege mediaknop of galerij');
$unsafeGallery = array_replace($emptyGallery, ['posters' => ['javascript:alert(1)', 'https://evil.invalid/a.jpg'],
    'backdrops' => ['https://image.tmdb.org.evil.invalid/t/p/original/a.jpg', 'https://artworks.thetvdb.com@evil.invalid/a.jpg']]);
check(!str_contains(MetadataView::hero($tmdbMovie, $unsafeGallery, '', true), 'data-image-gallery'),
    'Galerijweergave valideert ook gecachte URLs opnieuw en weigert niet-providerhosts');

$galleryRepo = $tmdbRepo;
$galleryApi = new TvdbFixture();
$galleryApi->searchResults = [TvdbFixture::candidate()];
$galleryApi->extraArtwork = [
    ['type' => 14, 'image' => '/banners/alternate-poster.jpg'],
    ['type' => 15, 'image' => '/banners/alternate-fanart.jpg'],
    ['type' => 15, 'image' => '/banners/fanart.jpg'],
    ['type' => 14, 'image' => 'https://evil.invalid/poster.jpg'],
    ['type' => 99, 'image' => '/banners/not-a-poster.jpg'],
];
$galleryService = new MetadataService($galleryRepo, $galleryApi, $tmdbApi);
$galleryRepo->saveTmdbSettings(false, '', 'nl-NL');
$galleryRepo->saveProvider('movie', 'tvdb');
$pdo->exec('DELETE FROM tvdb_cache');
$galleryRepo->choose($tmdbMovie['id'], 'stale', null);
$tvdbGallery = $galleryService->lookup($tmdbMovie)['metadata'];
check($tvdbGallery['posters'] === ['https://artworks.thetvdb.com/banners/poster.jpg',
    'https://artworks.thetvdb.com/banners/alternate-poster.jpg']
    && $tvdbGallery['backdrops'] === ['https://artworks.thetvdb.com/banners/fanart.jpg',
        'https://artworks.thetvdb.com/banners/alternate-fanart.jpg']
    && $tvdbGallery['poster'] === 'https://artworks.thetvdb.com/banners/poster.jpg',
    'TVDB bewaart meerdere posters/fanart per artworktype, dedupliceert en verandert de gekozen hero niet');

$galleryRepo->saveProvider('movie', 'tmdb');
$galleryRepo->saveTmdbSettings(true, '', 'nl-NL');
foreach (['outage', 'wrong-id', 'invalid-list'] as $galleryFailure) {
    $pdo->exec('DELETE FROM tvdb_cache');
    $galleryRepo->choose($tmdbMovie['id'], 'stale', null);
    $tmdbApi->failImages = $galleryFailure === 'outage';
    $tmdbApi->images = match ($galleryFailure) {
        'wrong-id' => ['id' => 999, 'posters' => [], 'backdrops' => []],
        'invalid-list' => ['id' => 603, 'posters' => 'broken', 'backdrops' => []],
        default => null,
    };
    $failedGallery = $tmdbService->lookup($tmdbMovie);
    check($failedGallery['state'] === 'matched' && $failedGallery['metadata']['provider'] === 'tmdb'
        && count($failedGallery['warnings']) === 1 && str_contains($failedGallery['warnings'][0], 'Extra TMDB-afbeeldingen')
        && $failedGallery['metadata']['backdrops'][0] === 'https://image.tmdb.org/t/p/original/tmdb-backdrop.jpg',
        'Extra artworkfout behoudt primaire match en bestaande afbeelding met zichtbare waarschuwing: ' . $galleryFailure);
}
$tmdbApi->failImages = false;
$tmdbApi->images = null;
$pdo->exec('DELETE FROM tvdb_cache');
$galleryRepo->result($tmdbMovie['id'], MetadataService::signature($tmdbMovie, $galleryRepo->profile() . ':release-v4'),
    ['state' => 'matched', 'manual' => false, 'metadata' => $emptyGallery], 604800);
check(count($tmdbService->lookup($tmdbMovie)['metadata']['posters']) === 3,
    'Verse oude match zonder artworklijsten wordt bij detail vernieuwd zonder flush of migratie');
