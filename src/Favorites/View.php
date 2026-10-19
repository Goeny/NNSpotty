<?php

declare(strict_types=1);

namespace NnSpotty\Favorites;

final class View
{
    public static function button(array $spot): string
    {
        if (!array_key_exists('is_favorite', $spot)) return '';
        $active = (bool) $spot['is_favorite'];
        $label = $active ? 'Verwijderen uit favorieten' : 'Toevoegen aan favorieten';
        return '<button class="favorite-button' . ($active ? ' is-favorite' : '') . '" type="button" data-favorite="' . h($spot['id'])
            . '" aria-pressed="' . ($active ? 'true' : 'false') . '" aria-label="' . $label . '" title="' . $label . '">'
            . '<svg viewBox="0 0 24 28" width="22" height="26" aria-hidden="true" focusable="false">'
            . '<path class="favorite-ribbon" d="M3 2h18v24l-9-5-9 5z"/>'
            . '<path class="favorite-star" d="m12 6 1.7 3.5 3.8.5-2.8 2.7.7 3.8-3.4-1.8-3.4 1.8.7-3.8L6.5 10l3.8-.5z"/></svg>'
            . '</button>';
    }
}
