<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use RuntimeException;

final class TmdbClient implements Gateway
{
    private readonly float $deadline;

    public function __construct(private readonly Repository $repo)
    {
        $this->deadline = microtime(true) + 22;
    }

    public function get(string $path, array $query = []): ?array
    {
        if (!preg_match('~^/(?:search/(?:movie|tv)|movie/[1-9][0-9]{0,9}(?:/images)?|tv/[1-9][0-9]{0,9}(?:/images|/season/[0-9]{1,3}/episode/[1-9][0-9]{0,3})?|find/(?:tt[0-9]{5,10}|[1-9][0-9]{0,9}))$~D', $path)) {
            throw new RuntimeException('Ongeldig TMDB-endpoint.');
        }
        $settings = $this->repo->tmdbSettings();
        if (!$settings['enabled'] || $settings['token'] === '') throw new RuntimeException('TMDB is niet ingesteld.');
        $headers = ['Accept: application/json', 'User-Agent: NNSpotty/0.0.1'];
        // A 32-character hex value is a v3 API key; anything else is a v4 Read Access Token.
        if (preg_match('/^[a-f0-9]{32}$/D', $settings['token'])) $query['api_key'] = $settings['token'];
        else $headers[] = 'Authorization: Bearer ' . $settings['token'];
        $remaining = (int) (($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) throw new RuntimeException('TMDB-tijdslimiet bereikt. Probeer later opnieuw.');
        $this->repo->claimRequest('tmdb');
        $curl = curl_init('https://api.themoviedb.org/3' . $path . ($query === [] ? '' : '?' . http_build_query($query)));
        if ($curl === false) throw new RuntimeException('TMDB HTTP-client kon niet starten.');
        $response = '';
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => min(8000, $remaining),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]) || curl_exec($curl) === false) {
                throw new RuntimeException('TMDB-transport mislukt (cURL-code ' . curl_errno($curl) . ').');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status === 404) return null;
            if ($status === 401) throw new RuntimeException('TMDB weigert de API-key of het token (HTTP 401).');
            if ($status !== 200) throw new RuntimeException('TMDB-aanvraag mislukt (HTTP ' . $status . '). Controleer toegang of API-limiet.');
            $data = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) throw new RuntimeException('TMDB gaf geen geldig JSON-object.');
            return $data;
        } finally {
            curl_close($curl);
        }
    }
}
