<?php

declare(strict_types=1);

namespace NnSpotty\Comments;

use NnSpotty\Usenet\SpotnetParser;
use OpenSSLAsymmetricKey;

final class Article
{
    public static function body(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if ($body === '' || strlen($body) > 10000 || preg_match('//u', $body) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) {
            throw new \InvalidArgumentException('Een reactie moet geldige tekst bevatten en maximaal 10000 UTF-8-bytes lang zijn.');
        }
        // Classic Spotnet readers expect Latin-1. Never silently replace unsupported characters.
        $encoded = @iconv('UTF-8', 'ISO-8859-1', $body);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Gebruik voor Usenet tekst met West-Europese tekens. Voeg emoji toe via de emoticonknoppen ([img=...]-codes).');
        }
        foreach (explode("\n", $encoded) as $line) {
            if (strlen($line) > 900) {
                throw new \InvalidArgumentException('Een reactieregel mag maximaal 900 bytes bevatten. Voeg een nieuwe regel toe.');
            }
        }
        return $body;
    }

    /**
     * Spotnet and Spotweb use 1024-bit RSA keys. Spotweb stores the user signature in VARCHAR(255) and the
     * serialized key in VARCHAR(512); a 2048-bit signature (344 base64 chars) makes its strict-mode insert fail,
     * so the comment is never shown there.
     */
    public const KEY_BITS = 1024;
    // Spotweb stores comment message-IDs and spot references in VARCHAR(128).
    public const MAX_MESSAGE_ID = 128;

    public static function messageId(string $reference): string
    {
        [$local, $domain] = explode('@', SpotnetParser::messageId($reference), 2);
        $prefix = $local . '.0.' . bin2hex(random_bytes(8)) . '.';
        if (strlen($prefix . dechex(2000000) . '@' . $domain) > self::MAX_MESSAGE_ID) {
            throw new \InvalidArgumentException('Het message-ID van deze spot is te lang om een compatibele reactie te publiceren.');
        }
        $deadline = microtime(true) + 5;
        for ($nonce = 0; $nonce < 2000000; $nonce++) {
            $id = $prefix . dechex($nonce) . '@' . $domain;
            if (str_starts_with(sha1('<' . $id . '>'), '0000')) return $id;
            if ($nonce % 4096 === 0 && microtime(true) > $deadline) break;
        }
        throw new PublicationException('De anti-spamberekening voor deze reactie kon niet tijdig worden voltooid. Probeer opnieuw.');
    }

    public static function key(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => self::KEY_BITS, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            self::configurationError();
        }
        return $pem;
    }

    public static function compatibleKey(string $pem): bool
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            while (openssl_error_string() !== false) {
            }
            return false;
        }
        $details = openssl_pkey_get_details($key);
        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) === self::KEY_BITS;
    }

    public static function privateKey(string $pem): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) self::configurationError();
        return $key;
    }

    public static function sign(string $message, OpenSSLAsymmetricKey $key): string
    {
        if (!openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA1)) self::configurationError();
        $details = openssl_pkey_get_details($key);
        if ($details === false || openssl_verify($message, $signature, $details['key'], OPENSSL_ALGO_SHA1) !== 1) {
            self::configurationError();
        }
        return strtr(rtrim(base64_encode($signature), '='), ['/' => '-s', '+' => '-p']);
    }

    private static function configurationError(): never
    {
        while (openssl_error_string() !== false) {
        }
        throw new PublicationException('Publiceren is niet mogelijk door de serverconfiguratie: OpenSSL kan de vereiste RSA/SHA1-handtekening niet aanmaken of controleren. Laat de beheerder de PHP/OpenSSL-configuratie herstellen. Er is niets naar Usenet verstuurd.');
    }

    /** @return array{from: string, subject: string, body: string, headers: string, spotter_id: string} */
    public static function build(array $spot, string $username, string $body, string $id, string $userPem, string $serverPem): array
    {
        $body = self::body($body);
        SpotnetParser::messageId($id);
        if (strlen($id) > self::MAX_MESSAGE_ID) throw new \InvalidArgumentException('Het reactie-ID is te lang voor Spotnet-clients.');
        if (!self::compatibleKey($userPem) || !self::compatibleKey($serverPem)) {
            throw new PublicationException('Publiceren is niet mogelijk: de ondertekeningssleutel is geen Spotnet-compatibele 1024-bit RSA-sleutel. Er is niets naar Usenet verstuurd.');
        }
        $reference = SpotnetParser::messageId($spot['message_id']);
        if (preg_match('/[\x00-\x1F\x7F]/', $username . $spot['title']) || preg_match('//u', $username . $spot['title']) !== 1) {
            throw new \InvalidArgumentException('Auteur of titel bevat ongeldige tekens voor Usenet-publicatie.');
        }
        $name = @iconv('UTF-8', 'ISO-8859-1', $username);
        $title = @iconv('UTF-8', 'ISO-8859-1', 'Re: ' . $spot['title']);
        if ($name === false || $title === false) {
            throw new \InvalidArgumentException('De gebruikersnaam of spottitel bevat tekens die klassieke Spotnet-reacties niet ondersteunen.');
        }
        $fromName = '"' . addcslashes($name, "\\\"") . '"';
        $from = $fromName . ' <' . substr(hash('sha256', $username), 0, 24) . '@spot.net>';
        $wireBody = str_replace("\n", "\r\n", (string) iconv('UTF-8', 'ISO-8859-1', $body));
        $user = self::privateKey($userPem);
        $server = self::privateKey($serverPem);
        $userDetails = openssl_pkey_get_details($user);
        if ($userDetails === false || !isset($userDetails['rsa']['n'])) self::configurationError();
        $headers = [
            'Message-ID: <' . $id . '>',
            'References: <' . $reference . '>',
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Content-Type: text/plain; charset=ISO-8859-1',
            'Content-Transfer-Encoding: 8bit',
            'X-Newsreader: NNSpotty ' . \NnSpotty\Config\Release::VERSION,
            'X-No-Archive: yes',
            'X-User-Rating: 0',
            'X-User-Key: ' . self::publicXml($user),
            'X-User-Signature: ' . self::sign('<' . $id . '>', $user),
            'X-Server-Key: ' . self::publicXml($server),
            'X-Server-Signature: ' . self::sign('<' . $id . '>', $server),
        ];
        if (strlen($from) > 900 || strlen($title) > 900) throw new \InvalidArgumentException('Auteur of titel is te lang voor Usenet-publicatie.');
        return [
            'from' => $from,
            'subject' => $title,
            'body' => $wireBody,
            'headers' => implode("\r\n", $headers),
            'spotter_id' => \NnSpotty\Moderation\SpotterIdentity::spotterId($userDetails['rsa']['n']),
        ];
    }

    private static function publicXml(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['rsa'])) self::configurationError();
        return '<RSAKeyValue><Modulus>' . base64_encode($details['rsa']['n'])
            . '</Modulus><Exponent>' . base64_encode($details['rsa']['e']) . '</Exponent></RSAKeyValue>';
    }
}
