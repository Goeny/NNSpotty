<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotPresentation
{
    /** @param array<string, mixed> $spot */
    public static function image(array $spot, bool $thumbnail, bool $enabled = true, ?string $gallery = null): string
    {
        if (!$enabled) {
            return '<div class="cover-placeholder' . ($thumbnail ? ' small' : '') . '">Afbeeldingen uitgeschakeld</div>';
        }
        $source = self::imageSource($spot);
        if ($source === '') {
            return self::placeholder($thumbnail);
        }
        $class = $thumbnail ? 'spot-thumbnail' : 'spot-poster-image';
        $background = $thumbnail ? '' : self::backdrop($spot, 'poster-backdrop');
        $image = '<img class="' . $class . '" src="' . h($source)
            . '" alt="Afbeelding bij ' . h($spot['title']) . '" loading="lazy" referrerpolicy="no-referrer"'
            . ' onerror="this.hidden=true;this.closest(\'figure\').classList.add(\'image-failed\');this.closest(\'figure\').querySelector(\'[data-image-fallback]\').hidden=false">';
        if (!$thumbnail) {
            $image = '<a class="poster-enlarge" href="' . h($source) . '" target="_blank" rel="noopener noreferrer" data-image-lightbox'
                . ($gallery === null ? '' : ' data-image-gallery="' . h($gallery) . '"')
                . ' data-image-title="' . h($spot['title']) . '" aria-label="Afbeelding vergroten: ' . h($spot['title']) . '">' . $image . '</a>';
        }
        return '<figure class="' . ($thumbnail ? 'mb-0' : 'spot-poster mb-0') . '">' . $background . $image
            . '<div data-image-fallback hidden>' . self::placeholder($thumbnail) . '</div>'
            . '</figure>';
    }

    private static function placeholder(bool $thumbnail): string
    {
        $file = $thumbnail ? 'placeholder-small.png' : 'placeholder.png';
        return '<div class="cover-placeholder has-image' . ($thumbnail ? ' small' : '') . '">'
            . '<img src="/assets/images/' . $file . '" alt="Geen afbeelding" loading="lazy" width="320" height="480"></div>';
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
        return '<img class="' . h($class) . '" src="' . h($source === '' ? '/assets/images/placeholder.png' : $source)
            . '" alt="" aria-hidden="true" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src=\'/assets/images/placeholder.png\'">';
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
