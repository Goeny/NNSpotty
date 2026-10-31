<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use RuntimeException;

final class Endpoint
{
    public static function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || strlen($url) > 1000
            || preg_match('~(?:%|\.{2})~', $parts['path'] ?? '')
            || !preg_match('~^(/[a-zA-Z0-9_/-]+)?$~D', $parts['path'] ?? '')) {
            throw new InvalidArgumentException('Gebruik een HTTP(S)-basisadres, eventueel met padprefix zoals /sabnzbd of /nzbget, zonder login, query, /api, /jsonrpc of fragment.');
        }
        if (preg_match('~/(?:api|jsonrpc|xmlrpc)$~D', $parts['path'] ?? '') === 1) {
            throw new InvalidArgumentException('Voer het basisadres zonder /api of /jsonrpc in.');
        }
        return $url;
    }

    public static function publicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (str_contains($ip, ':')) {
            // Only global unicast; exclude transition/translation ranges to IPv4 networks.
            $bytes = inet_pton($ip);
            $hex = $bytes === false ? '' : bin2hex($bytes);
            return $bytes !== false && (ord($bytes[0]) & 0xe0) === 0x20
                && !(str_starts_with($hex, '2001') && hexdec(substr($hex, 4, 4)) < 512)
                && !str_starts_with($hex, '20010db8')
                && !str_starts_with($hex, '2002')
                && !str_starts_with($hex, '3fff0');
        }
        $parts = array_map('intval', explode('.', $ip));
        return $parts[0] !== 0 && $parts[0] < 224
            && !($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127)
            && !($parts[0] === 198 && in_array($parts[1], [18, 19], true))
            && !($parts[0] === 192 && $parts[1] === 88 && $parts[2] === 99)
            && !($parts[0] === 192 && $parts[1] === 0 && in_array($parts[2], [0, 2], true))
            && !($parts[0] === 198 && $parts[1] === 51 && $parts[2] === 100)
            && !($parts[0] === 203 && $parts[1] === 0 && $parts[2] === 113);
    }

    /** @param list<string> $addresses */
    public static function checkAddresses(array $addresses, bool $internalAllowed): void
    {
        if ($addresses === []) {
            throw new RuntimeException('Downloadclient-host kon niet naar een IP-adres worden vertaald.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false || (!$internalAllowed && !self::publicIp($address))) {
                throw new InvalidArgumentException('Alleen admins mogen downloadclients op lokale, interne of gereserveerde adressen benaderen.');
            }
        }
    }

    /** @return array{url: string, host: string, port: int, ip: string} */
    public static function resolve(string $url, bool $internalAllowed, string $suffix = '/api'): array
    {
        $url = self::normalize($url);
        $parts = parse_url($url);
        $host = trim($parts['host'], '[]');
        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                throw new InvalidArgumentException('Ongeldige downloadclient-hostnaam.');
            }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false) {
                throw new RuntimeException('DNS-opvraag voor downloadclient is mislukt.');
            }
            foreach ($records as $record) {
                if (isset($record['ip']) || isset($record['ipv6'])) {
                    $addresses[] = $record['ip'] ?? $record['ipv6'];
                }
            }
        }
        self::checkAddresses($addresses, $internalAllowed);
        return ['url' => $url . $suffix, 'host' => $host, 'port' => $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80), 'ip' => $addresses[0]];
    }
}
