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
            return '<div class="bg-secondary-subtle rounded p-3 text-center text-body-secondary">Geen afbeelding</div>';
        }
        $style = $thumbnail ? 'width:64px;height:90px;object-fit:contain' : 'max-height:550px;object-fit:contain';
        return '<figure class="mb-0"><img class="img-fluid rounded" style="' . $style . '" src="' . h($source)
            . '" alt="Afbeelding bij ' . h($spot['title']) . '" loading="lazy" referrerpolicy="no-referrer"'
            . ' onerror="this.hidden=true;this.nextElementSibling.hidden=false">'
            . '<p class="alert alert-warning small" role="status" hidden>Afbeelding niet beschikbaar.</p>'
            . ($thumbnail ? '' : '<figcaption class="small text-body-secondary">Afbeeldingen zijn afhankelijk van beschikbaarheid bij de Usenet-provider of externe bron. Bij een laadfout: controleer de serverlog.</figcaption>')
            . '</figure>';
    }
}
