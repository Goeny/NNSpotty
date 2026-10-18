<?php

declare(strict_types=1);

final class Fixtures
{
    public static function xml(string $title = 'Een individuele testspot', string $category = '1'): string
    {
        return '<Spotnet><Posting><Key>7</Key><Title>' . $title . '</Title>'
            . '<Description><![CDATA[Een omschrijving met [b]opmaak[/b] en <script>onveilig</script>.]]></Description>'
            . '<Poster>Testspotter</Poster><Category>' . $category . '<Sub>1a01</Sub></Category>'
            . '<Size>4294967296</Size><Website>https://example.invalid/film</Website><Tag>test</Tag>'
            . '<Image Width="1" Height="1"><Segment>image@example.invalid</Segment></Image>'
            . '<NZB><Segment>nzb@example.invalid</Segment></NZB></Posting></Spotnet>';
    }

    public static function headers(string $id = 'spot-one@example.invalid', ?string $xml = null): string
    {
        $xml ??= self::xml();
        return "Message-ID: <{$id}>\r\nDate: " . gmdate('r') . "\r\nFrom: Testspotter <poster@example.invalid>\r\n"
            . 'X-XML: ' . substr($xml, 0, 120) . "\r\nX-XML: " . substr($xml, 120);
    }

    public static function comment(string $references = '<spot-one@example.invalid>'): string
    {
        return "Date: " . gmdate('r') . "\r\nFrom: Reageerder <reader@example.invalid>\r\n"
            . ($references === '' ? '' : 'References: ' . $references . "\r\n")
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\nEen echte reactie.\r\n.Begint met een punt.";
    }

    public static function image(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
    }
}
