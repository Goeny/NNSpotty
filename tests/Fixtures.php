<?php

declare(strict_types=1);

final class Fixtures
{
    public static function signingKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => __DIR__ . '/openssl-test.cnf']);
        if ($key === false) throw new RuntimeException('Synthetische RSA-testkey kon niet worden aangemaakt.');
        return $key;
    }

    public static function signedHeaders(string $id, OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (!openssl_sign('<' . $id . '>', $signature, $key, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('Ondertekenen van RSA/SHA1-testfixture mislukt. '
                . 'Controleer de OpenSSL-providerconfiguratie en het cryptografiebeleid van PHP; '
                . 'start de tests met OPENSSL_CONF als de hoster een aparte configuratie heeft ingericht '
                . '(zie docs/linux-server-testing.md#openssl-diagnostiek). '
                . 'Deze tests vereisen RSA/SHA1-ondertekening en -verificatie.');
        }
        return self::headers($id) . "\r\nX-User-Key: <RSAKeyValue><Modulus>" . base64_encode($details['rsa']['n'])
            . '</Modulus><Exponent>' . base64_encode($details['rsa']['e']) . "</Exponent></RSAKeyValue>\r\nX-User-Signature: "
            . strtr(rtrim(base64_encode($signature), '='), ['/' => '-s', '+' => '-p']);
    }

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
        return "Message-ID: <{$id}>\r\nDate: " . gmdate('r') . "\r\nFrom: Testspotter <poster@example.invalid>\r\nX-Newsreader: Spotnet 1.9.0.8\r\n"
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

    public static function nzb(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="fixture" date="1792320000" subject="test.bin">'
            . '<groups><group>alt.binaries.test</group></groups><segments>'
            . '<segment bytes="1234" number="1">binary-part@example.invalid</segment></segments></file></nzb>';
    }
}
