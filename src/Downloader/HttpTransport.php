<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use RuntimeException;

final class HttpTransport
{
    /**
     * POST to a resolved endpoint with a pinned IP, no redirects, no proxy and a 2 MiB response limit.
     *
     * @param array{url: string, host: string, port: int, ip: string} $endpoint
     * @param string|array<string, mixed> $body
     * @param list<string> $headers
     * @return array{status: int, body: string}
     */
    public static function post(array $endpoint, string|array $body, string $label, array $headers = [], ?string $basicAuth = null): array
    {
        $curl = curl_init($endpoint['url']);
        if ($curl === false) {
            throw new RuntimeException($label . ' HTTP-client kon niet starten.');
        }
        $response = '';
        try {
            $ip = str_contains($endpoint['ip'], ':') ? '[' . $endpoint['ip'] . ']' : $endpoint['ip'];
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 2 * 1024 * 1024) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($basicAuth !== null) {
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $options[CURLOPT_USERPWD] = $basicAuth;
            }
            if (filter_var($endpoint['host'], FILTER_VALIDATE_IP) === false) {
                $options[CURLOPT_RESOLVE] = [$endpoint['host'] . ':' . $endpoint['port'] . ':' . $ip];
            }
            if (!curl_setopt_array($curl, $options)) {
                throw new RuntimeException($label . ' HTTP-opties konden niet worden ingesteld.');
            }
            if (curl_exec($curl) === false) {
                throw new RuntimeException($label . '-transport mislukt (cURL-code ' . curl_errno($curl) . ').');
            }
            return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response];
        } finally {
            curl_close($curl);
        }
    }
}
