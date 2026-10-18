<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use RuntimeException;

final class SabClient
{
    public function __construct(private readonly string $url, private readonly string $key, private readonly bool $internalAllowed)
    {
    }

    /** @return list<string> */
    public function categories(): array
    {
        $categories = $this->call('get_cats')['categories'] ?? null;
        if (!is_array($categories) || !array_is_list($categories)
            || array_filter($categories, static fn ($category): bool => !is_string($category)) !== []) {
            throw new RuntimeException('SABnzbd gaf geen geldige categorielijst.');
        }
        return $categories;
    }

    /** @return array<string, mixed> */
    public function call(string $mode, array $parameters = [], ?string $nzb = null): array
    {
        $endpoint = Endpoint::resolve($this->url, $this->internalAllowed);
        $fields = ['mode' => $mode, 'apikey' => $this->key, 'output' => 'json'] + $parameters;
        if ($nzb !== null) {
            $fields['nzbfile'] = new \CURLStringFile($nzb, 'nnspotty.nzb', 'application/x-nzb');
        }
        $curl = curl_init($endpoint['url']);
        if ($curl === false) {
            throw new RuntimeException('SABnzbd HTTP-client kon niet starten.');
        }
        $body = '';
        try {
            $ip = str_contains($endpoint['ip'], ':') ? '[' . $endpoint['ip'] . ']' : $endpoint['ip'];
            $options = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $nzb === null ? http_build_query($fields) : $fields,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ];
            if (filter_var($endpoint['host'], FILTER_VALIDATE_IP) === false) {
                $options[CURLOPT_RESOLVE] = [$endpoint['host'] . ':' . $endpoint['port'] . ':' . $ip];
            }
            if (!curl_setopt_array($curl, $options)) {
                throw new RuntimeException('SABnzbd HTTP-opties konden niet worden ingesteld.');
            }
            if (curl_exec($curl) === false) {
                throw new RuntimeException('SABnzbd-transport mislukt (cURL-code ' . curl_errno($curl) . ').');
            }
            if (curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('SABnzbd gaf HTTP ' . curl_getinfo($curl, CURLINFO_RESPONSE_CODE) . '; redirects worden niet gevolgd.');
            }
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new RuntimeException('SABnzbd gaf geen JSON-object.');
            }
            if (($data['status'] ?? null) === false || isset($data['error'])) {
                // Do not relay remote error text: it can contain credentials or active markup.
                throw new SabRejected('SABnzbd heeft de aanvraag geweigerd. Controleer API-key, rechten en categorie.');
            }
            return $data;
        } finally {
            curl_close($curl);
        }
    }
}
