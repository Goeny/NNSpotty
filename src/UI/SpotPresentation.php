<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotPresentation
{
    /** @param array<string, mixed> $spot */
    public static function image(array $spot, bool $thumbnail, bool $enabled = true): string
    {
        if (!$enabled) {
            return '<div class="cover-placeholder' . ($thumbnail ? ' small' : '') . '">Afbeeldingen uitgeschakeld</div>';
        }
        $source = self::imageSource($spot);
        if ($source === '') {
            return '<div class="cover-placeholder' . ($thumbnail ? ' small' : '') . '">Geen afbeelding</div>';
        }
        $class = $thumbnail ? 'spot-thumbnail' : 'spot-poster-image';
        $background = $thumbnail ? '' : self::backdrop($spot, 'poster-backdrop');
        $image = '<img class="' . $class . '" src="' . h($source)
            . '" alt="Afbeelding bij ' . h($spot['title']) . '" loading="lazy" referrerpolicy="no-referrer"'
            . ' onerror="this.hidden=true;this.closest(\'figure\').classList.add(\'image-failed\');this.closest(\'figure\').querySelector(\'[role=status]\').hidden=false">';
        if (!$thumbnail) {
            $image = '<a class="poster-enlarge" href="' . h($source) . '" target="_blank" rel="noopener noreferrer" data-image-lightbox'
                . ' data-image-title="' . h($spot['title']) . '" aria-label="Afbeelding vergroten: ' . h($spot['title']) . '">' . $image . '</a>';
        }
        return '<figure class="' . ($thumbnail ? 'mb-0' : 'spot-poster mb-0') . '">' . $background . $image
            . '<p class="alert alert-warning small" role="status" hidden>Afbeelding niet beschikbaar. Controleer de serverlog.</p>'
            . '</figure>';
    }

    /** @param array<string, mixed> $spot */
    public static function imageSource(array $spot): string
    {
        if ($spot['image_segments'] !== []) {
            return '/spots/image?key=' . rawurlencode($spot['id']);
        }
        $url = $spot['image_url'];
        return preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /** @param array<string, mixed> $spot */
    public static function backdrop(array $spot, string $class, bool $enabled = true): string
    {
        if (!$enabled) {
            return '';
        }
        $source = self::imageSource($spot);
        return $source === '' ? '' : '<img class="' . h($class) . '" src="' . h($source)
            . '" alt="" aria-hidden="true" referrerpolicy="no-referrer" onerror="this.hidden=true">';
    }

    public static function size(int $bytes): string
    {
        $value = (float) $bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return number_format($value, $unit === 0 ? 0 : 2, ',', '.') . ' ' . $units[$unit];
    }
}
