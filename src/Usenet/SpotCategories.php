<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

final class SpotCategories
{
    public const HEADS = ['Beeld', 'Geluid', 'Spellen', 'Applicaties'];

    /** @return array<int, array<string, array<int, string>>> */
    public static function definitions(): array
    {
        return [
            0 => [
                'a' => explode('|', 'DivX|WMV|MPG|DVD5|HD overig|ePub|Blu-ray|HD-DVD|WMVHD|x264|DVD9|PDF|Bitmap|Vector|3D|UHD'),
                'b' => [0 => 'CAM', 1 => '(S)VCD', 2 => 'Promo', 3 => 'Retail', 4 => 'TV', 6 => 'Satelliet', 7 => 'R5', 8 => 'Telecine', 9 => 'Telesync', 10 => 'Scan', 11 => 'WEB-DL', 12 => 'WEBRip', 13 => 'HDRip'],
                'c' => [0 => 'Geen ondertiteling', 1 => 'Nederlands extern', 2 => 'Nederlands ingebouwd', 3 => 'Engels extern', 4 => 'Engels ingebouwd', 6 => 'Nederlands beschikbaar', 7 => 'Engels beschikbaar', 10 => 'Engels gesproken/geschreven', 11 => 'Nederlands gesproken/geschreven', 12 => 'Duits', 13 => 'Frans', 14 => 'Spaans', 15 => 'Aziatisch'],
                'd' => [
                    0 => 'Actie', 1 => 'Avontuur', 2 => 'Animatie', 3 => 'Cabaret', 4 => 'Komedie', 5 => 'Misdaad', 6 => 'Documentaire', 7 => 'Drama', 8 => 'Familie', 9 => 'Fantasy', 10 => 'Arthouse', 11 => 'Televisie', 12 => 'Horror', 13 => 'Muziek', 14 => 'Musical', 15 => 'Mysterie', 16 => 'Romantiek', 17 => 'Sciencefiction', 18 => 'Sport', 19 => 'Korte film', 20 => 'Thriller', 21 => 'Oorlog', 22 => 'Western',
                    23 => 'Erotica (legacy)', 24 => 'Erotica (legacy)', 25 => 'Erotica (legacy)', 26 => 'Erotica (legacy)',
                    28 => 'Aziatisch', 29 => 'Anime', 30 => 'Omslagen', 31 => 'Strips', 32 => 'Cartoons', 33 => 'Jeugd', 34 => 'Zakelijk', 35 => 'Computers', 36 => 'Hobby', 37 => 'Koken', 38 => 'Handwerken', 39 => 'Knutselen', 40 => 'Gezondheid', 41 => 'Geschiedenis', 42 => 'Psychologie', 43 => 'Kranten', 44 => 'Tijdschriften', 45 => 'Wetenschap', 46 => 'Vrouwen', 47 => 'Religie', 48 => 'Roman', 49 => 'Biografie', 50 => 'Detective', 51 => 'Dieren', 52 => 'Humor', 53 => 'Reizen', 54 => 'Waargebeurd', 55 => 'Non-fictie', 56 => 'Politiek', 57 => 'Poezie', 58 => 'Sprookjes', 59 => 'Techniek', 60 => 'Kunst',
                    72 => 'Bi', 73 => 'Lesbisch', 74 => 'Homo', 75 => 'Hetero', 76 => 'Amateur', 77 => 'Groep', 78 => 'POV', 79 => 'Solo', 80 => 'Jongvolwassen', 81 => 'Soft', 82 => 'Fetisj', 83 => 'Ouder', 84 => 'Plus-size', 85 => 'SM', 86 => 'Ruw', 87 => 'Donker', 88 => 'Hentai', 89 => 'Buiten',
                ],
                'z' => ['Films', 'Series', 'Boeken', 'Erotica', 'Afbeeldingen'],
            ],
            1 => [
                'a' => explode('|', 'MP3|WMA|WAV|OGG|EAC|DTS|AAC|APE|FLAC'),
                'b' => explode('|', 'CD|Radio|Compilatie|DVD|Overig|Vinyl|Stream'),
                'c' => explode('|', 'Variabel|< 96 kbit|96 kbit|128 kbit|160 kbit|192 kbit|256 kbit|320 kbit|Lossless|Overig'),
                'd' => explode('|', 'Blues|Compilatie|Cabaret|Dance|Diversen|Hardcore|Wereldmuziek|Jazz|Jeugd|Klassiek|Kleinkunst|Nederlands|New Age|Pop|RnB|Hiphop|Reggae|Religieus|Rock|Soundtracks|Overig|Hardstyle|Aziatisch|Disco|Classics|Metal|Country|Dubstep|Nederhop|DnB|Electro|Folk|Soul|Trance|Balkan|Techno|Ambient|Latin|Live'),
                'z' => ['Muziek', 'Livesets', 'Podcasts', 'Luisterboeken'],
            ],
            2 => [
                'a' => explode('|', 'Windows|Macintosh|Linux|PlayStation|PlayStation 2|PSP|Xbox|Xbox 360|Game Boy Advance|GameCube|Nintendo DS|Nintendo Wii|PlayStation 3|Windows Phone|iOS|Android|Nintendo 3DS|PlayStation 4|Xbox One'),
                'b' => [0 => 'ISO', 1 => 'Rip', 2 => 'Retail', 3 => 'DLC', 5 => 'Patch', 6 => 'Crack'],
                'c' => explode('|', 'Actie|Avontuur|Strategie|Rollenspel|Simulatie|Racen|Vliegen|Shooter|Platform|Sport|Jeugd|Puzzel|Overig|Bordspel|Kaarten|Educatief|Muziek|Familie'),
            ],
            3 => [
                'a' => explode('|', 'Windows|Macintosh|Linux|OS/2|Windows Phone|Navigatie|iOS|Android'),
                'b' => explode('|', 'Audio|Video|Grafisch|CD/DVD-tools|Mediaspelers|Rippers en encoders|Plugins|Databases|E-mail|Foto|Screensavers|Skins|Drivers|Browsers|Downloadmanagers|Download|Usenet|RSS|FTP|Firewalls|Antivirus|Antispyware|Optimalisatie|Beveiliging|Systeem|Overig|Educatief|Office|Internet|Communicatie|Ontwikkeling|Spotnet'),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function dimensions(int $head): array
    {
        return match ($head) {
            0 => ['z' => 'Type', 'a' => 'Formaat', 'b' => 'Bron', 'c' => 'Taal / ondertiteling', 'd' => 'Genre'],
            1 => ['z' => 'Type', 'a' => 'Formaat', 'b' => 'Bron', 'c' => 'Bitrate', 'd' => 'Genre'],
            2 => ['a' => 'Platform', 'b' => 'Uitvoering', 'c' => 'Genre'],
            3 => ['a' => 'Platform', 'b' => 'Soort applicatie'],
            default => [],
        };
    }

    /** @param list<string> $raw
     *  @return array{type: string, adult: bool, codes: list<string>}
     */
    public static function classify(string $category, array $raw): array
    {
        $head = array_search($category, self::HEADS, true);
        if ($head === false) {
            return ['type' => 'Overig', 'adult' => false, 'codes' => []];
        }
        $codes = [];
        foreach ($raw as $value) {
            if (preg_match('/^(?:\d+)?([abcdz])0*(\d{1,3})$/iD', trim($value), $match)) {
                $codes[] = strtolower($match[1]) . (int) $match[2];
            }
        }
        $codes = array_values(array_unique($codes));
        $adult = $head === 0 && (in_array('z3', $codes, true) || count(array_intersect(
            $codes, array_map(static fn (int $n): string => 'd' . $n, array_merge(range(23, 26), range(72, 89)))
        )) > 0);
        $type = match ($head) {
            0 => 'Films', 1 => 'Muziek', 2 => 'Spellen', 3 => 'Applicaties',
        };
        if ($head <= 1) {
            $explicit = array_values(array_filter($codes, static fn (string $code): bool => $code[0] === 'z'));
            if ($explicit !== []) {
                $type = self::definitions()[$head]['z'][(int) substr($explicit[0], 1)] ?? 'Overig';
            } elseif ($head === 0) {
                $type = match (true) {
                    count(array_intersect($codes, ['a5', 'a11'])) > 0 => 'Boeken',
                    count(array_intersect($codes, ['a12', 'a13'])) > 0 => 'Afbeeldingen',
                    count(array_intersect($codes, ['b4', 'd11'])) > 0 => 'Series',
                    default => 'Films',
                };
            }
            if ($adult) {
                $type = 'Erotica';
            }
            if ($explicit === [] || $adult) {
                $z = array_search($type, self::definitions()[$head]['z'], true);
                if ($z !== false) {
                    $codes[] = 'z' . $z;
                }
            }
        }
        if ($head === 0 && $adult) {
            $codes = array_map(static fn (string $code): string => ['d23' => 'd75', 'd24' => 'd74', 'd25' => 'd73', 'd26' => 'd72'][$code] ?? $code, $codes);
        }
        if ($head === 0 && $type === 'Boeken') {
            $codes = array_map(static fn (string $code): string => [
                'c1' => 'c11', 'c2' => 'c11', 'c6' => 'c11', 'c3' => 'c10', 'c4' => 'c10', 'c7' => 'c10',
            ][$code] ?? $code, $codes);
        }

        return ['type' => $type, 'adult' => $adult, 'codes' => array_values(array_unique($codes))];
    }

    public static function label(string $filter): string
    {
        if (preg_match('/^([0-3]):([abcdz])(\d+)$/D', $filter, $match)) {
            return self::definitions()[(int) $match[1]][$match[2]][(int) $match[3]] ?? $filter;
        }
        return $filter;
    }

    public static function headForCategory(?string $category): ?int
    {
        foreach (self::HEADS as $head => $label) {
            if ($label === $category || in_array($category, self::definitions()[$head]['z'] ?? [], true)) {
                return $head;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $spot
     *  @return array<string, list<string>>
     */
    public static function describe(array $spot): array
    {
        $head = array_search($spot['category'], self::HEADS, true);
        $details = [];
        foreach (self::classify($spot['category'], $spot['subcategories'] ?? [])['codes'] as $code) {
            $dimension = self::dimensions($head === false ? -1 : $head)[$code[0]] ?? 'Overig';
            $details[$dimension][] = self::label($head . ':' . $code);
        }
        return $details;
    }
}
