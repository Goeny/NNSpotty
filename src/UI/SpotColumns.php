<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use InvalidArgumentException;
use NnSpotty\Http\Request;
use NnSpotty\Usenet\SpotCategories;

final class SpotColumns
{
    public const LABELS = [
        'cover' => 'Afbeelding', 'title' => 'Titel', 'format' => 'Formaat / platform',
        'genre' => 'Genre / soort', 'poster' => 'Spotter', 'posted_at' => 'Geplaatst (UTC)', 'size' => 'Grootte',
    ];

    /** @return array<int, list<string>> */
    public static function defaults(): array
    {
        return [
            0 => ['cover', 'title', 'format', 'poster', 'posted_at', 'size'],
            1 => ['cover', 'title', 'format', 'genre', 'size'],
            2 => ['cover', 'title', 'format', 'genre', 'size'],
            3 => ['title', 'format', 'genre', 'poster', 'size'],
        ];
    }

    /** @param array<mixed> $columns
     *  @return list<string>
     */
    public static function validate(array $columns): array
    {
        foreach ($columns as $column) {
            if (!is_string($column) || !isset(self::LABELS[$column])) {
                throw new InvalidArgumentException('Ongeldige kolomselectie.');
            }
        }
        if (!array_is_list($columns) || count($columns) !== count(array_unique($columns))
            || !in_array('title', $columns, true)) {
            throw new InvalidArgumentException('Elke kolomselectie moet Titel bevatten en mag geen dubbele kolommen bevatten.');
        }
        return array_values(array_intersect(array_keys(self::LABELS), $columns));
    }

    /** @return array<int, list<string>> */
    public static function fromRequest(Request $request, bool $personal): array
    {
        $result = [];
        foreach (SpotCategories::HEADS as $head => $label) {
            $mode = $request->inputScalar('column_mode_' . $head);
            if ($personal && $mode === 'inherit') {
                continue;
            }
            if ($personal && $mode !== 'custom') {
                throw new InvalidArgumentException('Kies per hoofdcategorie de globale standaard of een persoonlijke selectie.');
            }
            $columns = ['title'];
            foreach (array_keys(self::LABELS) as $column) {
                if ($column === 'title') {
                    continue;
                }
                $value = $request->inputScalar('column_' . $head . '_' . $column);
                if ($value !== null && $value !== '1') {
                    throw new InvalidArgumentException('Ongeldige kolomwaarde voor ' . $label . '.');
                }
                if ($value === '1') {
                    $columns[] = $column;
                }
            }
            $result[$head] = self::validate($columns);
        }
        return $result;
    }

    /** @param array<int, list<string>> $global
     *  @param array<int, list<string>>|null $personal
     */
    public static function form(array $global, ?array $personal, string $csrfField): string
    {
        $isPersonal = $personal !== null;
        $html = '<section class="card mt-4" id="columns"><div class="card-header">Kolommen per hoofdcategorie</div><div class="card-body">'
            . '<p class="text-body-secondary">Titel blijft altijd zichtbaar. '
            . ($isPersonal ? 'Gebruik de globale standaard of kies een eigen selectie. Terugzetten naar de globale standaard verwijdert je override.' : 'Deze standaard geldt voor gebruikers zonder persoonlijke override.')
            . '</p><form method="post" action="' . ($isPersonal ? '/preferences/columns' : '/admin/columns') . '">' . $csrfField;
        foreach (SpotCategories::HEADS as $head => $label) {
            $columns = $personal[$head] ?? $global[$head];
            $custom = $isPersonal && isset($personal[$head]);
            $html .= '<fieldset class="border rounded p-3 mb-3"><legend class="float-none w-auto fs-6 px-2">' . h($label) . '</legend>';
            if ($isPersonal) {
                $html .= '<label class="form-label" for="column_mode_' . $head . '">Kolominstelling voor ' . h($label) . '</label>'
                    . '<select class="form-select mb-3" id="column_mode_' . $head . '" name="column_mode_' . $head . '">'
                    . '<option value="inherit"' . (!$custom ? ' selected' : '') . '>Globale standaard gebruiken</option>'
                    . '<option value="custom"' . ($custom ? ' selected' : '') . '>Eigen selectie</option></select>';
            }
            $html .= '<div class="d-flex flex-wrap gap-3">';
            foreach (self::LABELS as $column => $name) {
                $id = 'column_' . $head . '_' . $column;
                $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $id
                    . '" value="1"' . (in_array($column, $columns, true) ? ' checked' : '') . ($column === 'title' ? ' disabled' : '')
                    . '><label class="form-check-label" for="' . $id . '">' . h($name) . '</label></div>';
            }
            $html .= '</div></fieldset>';
        }
        return $html . '<button class="btn btn-primary" type="submit">Kolommen opslaan</button></form></div></section>';
    }
}
