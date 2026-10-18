<?php

declare(strict_types=1);

namespace NnSpotty\Newznab;

use NnSpotty\Usenet\SpotCategories;

final class Categories
{
    /** @return array<int, string> */
    public static function definitions(): array
    {
        return [
            1000 => 'Console', 1010 => 'Console/NDS', 1020 => 'Console/PSP', 1030 => 'Console/Wii',
            1040 => 'Console/XBox', 1050 => 'Console/XBox 360', 1080 => 'Console/PS3',
            1090 => 'Console/Other', 1110 => 'Console/3DS', 1140 => 'Console/XBox One', 1180 => 'Console/PS4',
            2000 => 'Movies', 2030 => 'Movies/SD', 2040 => 'Movies/HD', 2045 => 'Movies/UHD',
            2050 => 'Movies/BluRay', 2060 => 'Movies/3D',
            3000 => 'Audio', 3010 => 'Audio/MP3', 3020 => 'Audio/Video', 3030 => 'Audio/Audiobook', 3040 => 'Audio/Lossless',
            4000 => 'PC', 4010 => 'PC/0day', 4020 => 'PC/ISO', 4030 => 'PC/Mac', 4040 => 'PC/Mobile-Other',
            4050 => 'PC/Games', 4060 => 'PC/Mobile-iOS', 4070 => 'PC/Mobile-Android',
            5000 => 'TV', 5020 => 'TV/Foreign', 5030 => 'TV/SD', 5040 => 'TV/HD', 5045 => 'TV/UHD',
            5050 => 'TV/Other', 5060 => 'TV/Sport', 5070 => 'TV/Anime',
            6000 => 'XXX', 6010 => 'XXX/DVD', 6020 => 'XXX/WMV', 6030 => 'XXX/XviD', 6040 => 'XXX/x264',
            7000 => 'Books', 7020 => 'Books/EBook', 7030 => 'Books/Comics',
            8000 => 'Other', 8010 => 'Other/Misc',
        ];
    }

    /** @param list<string> $subcategories
     *  @return list<int>
     */
    public static function forSpot(string $category, array $subcategories): array
    {
        $classification = SpotCategories::classify($category, $subcategories);
        $codes = $classification['codes'];
        $has = static fn (string $code): bool => in_array($code, $codes, true);
        $type = $classification['type'];
        $result = [];
        if ($classification['adult']) {
            $result = [6000];
            foreach (['a3' => 6010, 'a10' => 6010, 'a1' => 6020, 'a0' => 6030, 'a9' => 6040] as $code => $id) {
                if ($has($code)) {
                    $result[] = $id;
                }
            }
        } elseif ($type === 'Boeken') {
            $result = [7000, $has('d31') ? 7030 : 7020];
        } elseif ($category === 'Beeld' && $type === 'Films' && $has('d13')) {
            $result = [3000, 3020];
        } elseif ($category === 'Beeld' && in_array($type, ['Films', 'Series'], true)) {
            $tv = $type === 'Series';
            $result = [$tv ? 5000 : 2000];
            foreach ($codes as $code) {
                if ($code[0] !== 'a') {
                    continue;
                }
                $format = (int) substr($code, 1);
                if (in_array($format, [0, 1, 2, 3, 10], true)) {
                    $result[] = $tv ? 5030 : 2030;
                } elseif (in_array($format, [4, 6, 7, 8, 9], true)) {
                    $result[] = $tv ? 5040 : 2040;
                } elseif ($format === 15) {
                    $result[] = $tv ? 5045 : 2045;
                }
                if (!$tv && $format === 6) {
                    $result[] = 2050;
                }
                if (!$tv && $format === 14) {
                    $result[] = 2060;
                }
            }
            if ($tv && $has('d18')) {
                $result[] = 5060;
            }
            if ($tv && $has('d29')) {
                $result[] = 5070;
            }
            if ($tv && array_intersect($codes, ['c12', 'c13', 'c14', 'c15']) !== []) {
                $result[] = 5020;
            }
            if ($tv && count($result) === 1) {
                $result[] = 5050;
            }
        } elseif ($category === 'Geluid') {
            $result = [3000];
            if ($type === 'Luisterboeken') {
                $result[] = 3030;
            } else {
                if ($has('a0')) {
                    $result[] = 3010;
                }
                if (array_intersect($codes, ['a2', 'a4', 'a7', 'a8', 'c8']) !== []) {
                    $result[] = 3040;
                }
            }
        } elseif ($category === 'Spellen') {
            $platforms = ['a4' => 1090, 'a5' => 1020, 'a6' => 1040, 'a7' => 1050,
                'a8' => 1090, 'a9' => 1090, 'a10' => 1010, 'a11' => 1030, 'a12' => 1080,
                'a16' => 1110, 'a17' => 1180, 'a18' => 1140, 'a3' => 1090];
            foreach ($platforms as $code => $id) {
                if ($has($code)) {
                    $result[] = 1000;
                    $result[] = $id;
                }
            }
            if ($result === [] || array_intersect($codes, ['a0', 'a1', 'a2', 'a13', 'a14', 'a15']) !== []) {
                $result = array_merge($result, [4000, 4050]);
                foreach (['a1' => 4030, 'a13' => 4040, 'a14' => 4060, 'a15' => 4070] as $code => $id) {
                    if ($has($code)) {
                        $result[] = $id;
                    }
                }
                if ($has('b0')) {
                    $result[] = 4020;
                }
            }
        } elseif ($category === 'Applicaties') {
            $result = [4000];
            foreach (['a0' => 4010, 'a1' => 4030, 'a4' => 4040, 'a6' => 4060, 'a7' => 4070] as $code => $id) {
                if ($has($code)) {
                    $result[] = $id;
                }
            }
        } else {
            $result = [8000, 8010];
        }
        return array_values(array_unique($result));
    }
}
