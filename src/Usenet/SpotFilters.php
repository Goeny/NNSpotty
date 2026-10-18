<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use InvalidArgumentException;

final class SpotFilters
{
    /** @return list<string> */
    public static function parse(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $filters = array_values(array_unique(explode(',', $value)));
        if (count($filters) > 512) {
            throw new InvalidArgumentException('Te veel subcategoriefilters.');
        }
        foreach ($filters as $filter) {
            if (!preg_match('/^[0-3]:[abcdz](?:0|[1-9]\d{0,2})$/D', $filter) || SpotCategories::label($filter) === $filter) {
                throw new InvalidArgumentException('Ongeldige subcategorie.');
            }
        }
        sort($filters);
        return $filters;
    }

    /** @param list<string> $selected
     *  @return list<string>
     */
    public static function toggle(array $selected, string $code): array
    {
        return self::parse(implode(',', in_array($code, $selected, true)
            ? array_values(array_diff($selected, [$code]))
            : [...$selected, $code]));
    }
}
