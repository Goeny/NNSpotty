<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotEmoticons
{
    private const SYMBOLS = [
        'biggrin' => ['&#x1F603;', 'Brede glimlach'],
        'bloos' => ['&#x1F60A;', 'Blozen'],
        'buigen' => ['&#x1F647;', 'Buigen'],
        'censored' => ['&#x1F92C;', 'Gecensureerd'],
        'clown' => ['&#x1F921;', 'Clown'],
        'confused' => ['&#x1F615;', 'Verward'],
        'cool' => ['&#x1F60E;', 'Cool'],
        'exactly' => ['&#x1F4AF;', 'Precies'],
        'frown' => ['&#x1F641;', 'Verdrietig'],
        'grijns' => ['&#x1F601;', 'Grijnzen'],
        'heh' => ['&#x1F60F;', 'Gniffelen'],
        'huh' => ['&#x1F928;', 'Huh?'],
        'klappen' => ['&#x1F44F;', 'Applaus'],
        'knipoog' => ['&#x1F609;', 'Knipoog'],
        'kwijl' => ['&#x1F924;', 'Kwijlen'],
        'lollig' => ['&#x1F61C;', 'Lollig'],
        'maf' => ['&#x1F92A;', 'Maf'],
        'ogen' => ['&#x1F440;', 'Ogen'],
        'oops' => ['&#x1F633;', 'Oeps'],
        'pijl' => ['&#x27A1;&#xFE0F;', 'Pijl'],
        'redface' => ['&#x1F633;', 'Blozen'],
        'respekt' => ['&#x1F64C;', 'Respect'],
        'respect' => ['&#x1F64C;', 'Respect'],
        'schater' => ['&#x1F602;', 'Schaterlachen'],
        'shiny' => ['&#x2728;', 'Schitterend'],
        'sleephappy' => ['&#x1F634;', 'Lekker slapen'],
        'smile' => ['&#x1F642;', 'Glimlach'],
        'uitroepteken' => ['&#x2757;', 'Uitroepteken'],
        'vlag' => ['&#x1F6A9;', 'Vlag'],
        'vraagteken' => ['&#x2753;', 'Vraagteken'],
        'wink' => ['&#x1F609;', 'Knipoog'],
    ];

    public static function render(string $code): string
    {
        if (preg_match('/^\[img=([a-z]+)\]$/iD', $code, $match) !== 1) {
            return h($code);
        }
        $name = strtolower($match[1]);
        $symbol = self::SYMBOLS[$name] ?? null;
        if ($symbol === null) {
            return h($code);
        }
        [$emoji, $label] = $symbol;
        $badge = in_array($name, ['respect', 'respekt', 'exactly', 'censored'], true);
        return '<span class="spot-emoticon' . ($badge ? ' spot-emoticon-badge' : '') . '" role="img" aria-label="'
            . h($label) . '" title="' . h($label) . '"><span aria-hidden="true">' . $emoji
            . ($badge ? ' <span class="spot-emoticon-label">' . h($label) . '</span>' : '') . '</span></span>';
    }

    public static function plainText(string $text): string
    {
        $parts = preg_split('/(\[img=[a-z]+\])/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Emoticoncodes konden niet worden verwerkt.');
        }
        $html = '';
        foreach ($parts as $index => $part) {
            $html .= $index % 2 === 1 ? self::render($part) : nl2br(h($part));
        }
        return $html;
    }
}
