<?php

declare(strict_types=1);

namespace NnSpotty\Moderation;

final class SpotlistClient
{
    public const URLS = [
        'whitelist' => 'https://spotlist.store/spotnet/whitelist.xml',
        'blacklist' => 'https://spotlist.store/spotnet/blacklist.xml',
    ];

    public function fetch(string $type): string
    {
        if (!isset(self::URLS[$type])) throw new \InvalidArgumentException('Onbekende spotterlijst.');
        $curl = curl_init(self::URLS[$type]);
        if ($curl === false) throw new \RuntimeException('Spotlist HTTP-client kon niet starten.');
        $body = '';
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/xml', 'User-Agent: NNSpotty/0.0.1'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]) || curl_exec($curl) === false) {
                throw new \RuntimeException('Spotlist-transport mislukt (cURL-code ' . curl_errno($curl)
                    . '). Controleer netwerk en de TLS/CA-configuratie van PHP.');
            }
            if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new \RuntimeException('Spotlist gaf HTTP ' . curl_getinfo($curl, CURLINFO_RESPONSE_CODE) . '.');
            }
            return $body;
        } finally {
            curl_close($curl);
        }
    }
}
