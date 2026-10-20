<?php

declare(strict_types=1);

namespace NnSpotty\Moderation;

final class SpotterIdentity
{
    /** @return array{spotter_key: ?string, signature_state: string} */
    public static function fromHeaders(array $headers, string $messageId): array
    {
        if (!isset($headers['x-user-key'], $headers['x-user-signature'])) {
            return ['spotter_key' => null, 'signature_state' => 'missing'];
        }
        $invalid = ['spotter_key' => null, 'signature_state' => 'invalid'];
        if (count($headers['x-user-key']) !== 1 || count($headers['x-user-signature']) !== 1) {
            return $invalid;
        }
        try {
            $xml = self::xml($headers['x-user-key'][0], 'RSAKeyValue', 4096);
            if (count($xml->Modulus) !== 1 || count($xml->Exponent) !== 1 || count($xml->children()) !== 2) {
                return $invalid;
            }
            $modulus = self::modulus((string) $xml->Modulus);
            $exponent = base64_decode(trim((string) $xml->Exponent), true);
            // Spotlist publishes moduli, not exponents: never accept an attacker-chosen exponent.
            if ($exponent !== "\x01\x00\x01") {
                return $invalid;
            }
            $encoded = strtr(trim($headers['x-user-signature'][0]), ['-s' => '/', '-p' => '+']);
            if (strlen($encoded) > 2048) return $invalid;
            $signature = base64_decode($encoded, true);
            if ($signature === false || strlen($signature) !== strlen($modulus)) return $invalid;
            $rsa = self::der(0x30, self::integer($modulus) . self::integer($exponent));
            $pem = "-----BEGIN RSA PUBLIC KEY-----\n" . chunk_split(base64_encode($rsa), 64, "\n")
                . "-----END RSA PUBLIC KEY-----\n";
            $key = openssl_pkey_get_public($pem);
            if ($key === false) return $invalid;
            $verification = openssl_verify('<' . $messageId . '>', $signature, $key, OPENSSL_ALGO_SHA1);
            if ($verification === -1 || $verification === false) {
                throw new \RuntimeException('Spotnet RSA/SHA1-verificatie kon niet worden uitgevoerd door OpenSSL. '
                    . 'Controleer de OpenSSL-providerconfiguratie en het cryptografiebeleid van deze PHP-runtime; '
                    . 'de handtekening is niet als ongeldig beoordeeld.');
            }
            if ($verification !== 1) {
                return $invalid;
            }
            return ['spotter_key' => hash('sha256', $modulus), 'signature_state' => 'verified'];
        } catch (\UnexpectedValueException) {
            return $invalid;
        } finally {
            while (openssl_error_string() !== false) {
                // Do not retain errors from untrusted signatures in the process-wide OpenSSL queue.
            }
        }
    }

    public static function modulus(string $encoded): string
    {
        $encoded = preg_replace('/\s+/', '', $encoded);
        $bytes = strlen($encoded) <= 1400 ? base64_decode($encoded, true) : false;
        if ($bytes === false) throw new \UnexpectedValueException('Ongeldige publieke spottersleutel.');
        $bytes = ltrim($bytes, "\0");
        if (strlen($bytes) < 48 || strlen($bytes) > 1024 || (ord($bytes[strlen($bytes) - 1]) & 1) !== 1) {
            throw new \UnexpectedValueException('Ongeldige RSA-modulus.');
        }
        return $bytes;
    }

    public static function xml(string $text, string $root, int $limit): \SimpleXMLElement
    {
        if (strlen($text) > $limit || preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            throw new \UnexpectedValueException('Spotter-XML is te groot of bevat verboden declaraties.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($text, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || $xml->getName() !== $root || $xml->getNamespaces(true) !== []) {
                throw new \UnexpectedValueException('Ongeldige spotter-XML.');
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function integer(string $bytes): string
    {
        return self::der(2, (ord($bytes[0]) & 128) ? "\0" . $bytes : $bytes);
    }

    private static function der(int $tag, string $bytes): string
    {
        $length = strlen($bytes);
        $size = $length < 128 ? chr($length) : ltrim(pack('N', $length), "\0");
        return chr($tag) . ($length < 128 ? $size : chr(128 | strlen($size)) . $size) . $bytes;
    }
}
