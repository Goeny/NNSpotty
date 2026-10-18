<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotPresentation
{
    /** @param array<string, mixed> $spot */
    public static function image(array $spot, bool $thumbnail): string
    {
        $source = $spot['image_segments'] !== []
            ? '/spots/image?key=' . rawurlencode($spot['id'])
            : $spot['image_url'];
        if ($source === '') {
            return '<div class="cover-placeholder' . ($thumbnail ? ' small' : '') . '">Geen afbeelding</div>';
        }
        $class = $thumbnail ? 'spot-thumbnail' : 'img-fluid rounded';
        return '<figure class="mb-0"><img class="' . $class . '" src="' . h($source)
            . '" alt="Afbeelding bij ' . h($spot['title']) . '" loading="lazy" referrerpolicy="no-referrer"'
            . ' onerror="this.hidden=true;this.nextElementSibling.hidden=false">'
            . '<p class="alert alert-warning small" role="status" hidden>Afbeelding niet beschikbaar. Controleer de serverlog.</p>'
            . '</figure>';
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
