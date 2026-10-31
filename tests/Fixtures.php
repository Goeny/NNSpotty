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

    public static function spotwebComment(string $article, string $reference, string $publicKey, string $serverPublicKey): bool
    {
        // Check the emitted wire independently of NNSpotty's permissive comment parser.
        [$header, $body] = explode("\r\n\r\n", $article, 2);
        $headers = [];
        foreach (explode("\r\n", $header) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)][] = trim($value);
        }
        foreach (['newsgroups', 'message-id', 'references', 'x-user-key', 'x-user-signature',
            'x-server-key', 'x-server-signature', 'x-user-rating'] as $name) {
            if (count($headers[$name] ?? []) !== 1) return false;
        }
        $id = $headers['message-id'][0];
        $referenceLocal = explode('@', $reference, 2)[0];
        $idParts = explode('.', trim($id, '<>'));
        $spotwebReference = $idParts[0] . substr(trim($id, '<>'), strpos(trim($id, '<>'), '@'));
        if ($headers['newsgroups'][0] !== 'free.usenet' || $headers['references'][0] !== '<' . $reference . '>'
            || $spotwebReference !== $reference || $idParts[1] !== '0'
            || !str_starts_with($id, '<' . $referenceLocal . '.0.') || !str_ends_with($id, '>')
            || !str_starts_with(sha1($id), '0000') || $headers['x-user-rating'][0] !== '0'
            || trim($body) === '') {
            return false;
        }
        foreach (['user' => $publicKey, 'server' => $serverPublicKey] as $scope => $pem) {
            $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
            $xml = simplexml_load_string($headers['x-' . $scope . '-key'][0]);
            if ($xml === false || base64_decode((string) $xml->Modulus, true) !== $details['rsa']['n']
                || base64_decode((string) $xml->Exponent, true) !== $details['rsa']['e']) {
                return false;
            }
            // Spotweb's spotUnprepareBase64 and its commentsfull/commentsxover column limits (strict SQL mode).
            $prepared = $headers['x-' . $scope . '-signature'][0];
            $unprepared = str_replace(['-s', '-p'], ['/', '+'], $prepared . str_repeat('=', (4 - strlen($prepared) % 4) % 4));
            $userKey = serialize(['exponent' => (string) $xml->Exponent, 'modulo' => (string) $xml->Modulus]);
            if ($scope === 'user' && (strlen($unprepared) > 255 || strlen($userKey) > 512
                || strlen(trim($id, '<>')) > 128 || strlen($spotwebReference) > 128)) {
                return false;
            }
            $spotwebKey = openssl_pkey_get_public(self::spotwebPublicKey(base64_decode((string) $xml->Modulus), base64_decode((string) $xml->Exponent)));
            if ($spotwebKey === false || openssl_verify($id, (string) base64_decode($unprepared), $spotwebKey) !== 1) return false;
        }
        return true;
    }

    /** Port of Spotweb's Services_Signing_Openssl::seclibToOpenSsl (modulus encoded without a leading zero byte). */
    private static function spotwebPublicKey(string $modulus, string $exponent): string
    {
        $length = static function (int $length): string {
            if ($length <= 0x7F) return chr($length);
            $bytes = ltrim(pack('N', $length), "\0");
            return chr(0x80 | strlen($bytes)) . $bytes;
        };
        $integers = "\x02" . $length(strlen($modulus)) . $modulus . "\x02" . $length(strlen($exponent)) . $exponent;
        $sequence = "\x30" . $length(strlen($integers)) . $integers;
        $bitString = "\x03" . $length(strlen($sequence) + 1) . "\0" . $sequence;
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30" . $length(15 + strlen($bitString)) . $algorithm . $bitString), 64)
            . "-----END PUBLIC KEY-----\n";
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
