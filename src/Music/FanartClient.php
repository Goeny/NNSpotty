<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use RuntimeException;

final class FanartClient implements FanartGateway
{
    public function __construct(private readonly Repository $repo) {}

    public function get(string $artistId): ?array
    {
        if (!Release::mbid($artistId)) throw new \InvalidArgumentException('Ongeldige fanart-artiestidentiteit.');
        $settings = $this->repo->fanartSettings();
        if (!$this->repo->enabled() || !$settings['enabled'] || $settings['api_key'] === '') {
            throw new RuntimeException('Fanart.tv is niet ingesteld of uitgeschakeld.');
        }
        $curl = curl_init('https://webservice.fanart.tv/v3/music/' . $artistId . '?' . http_build_query(['api_key' => $settings['api_key']]));
        if ($curl === false) throw new RuntimeException('Fanart.tv HTTP-client kon niet starten.');
        $body = '';
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NNSpotty/0.0.1'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => 3000, CURLOPT_TIMEOUT_MS => 8000,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]) || curl_exec($curl) === false) throw new RuntimeException('Fanart.tv-transport mislukt (cURL ' . curl_errno($curl) . '). Controleer ook de CA-configuratie.');
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status === 404) return null;
            if ($status !== 200) throw new RuntimeException('Fanart.tv-aanvraag mislukt (HTTP ' . $status . '). Controleer de API-key of probeer later opnieuw.');
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) throw new RuntimeException('Fanart.tv gaf geen geldig JSON-object.');
            return $data;
        } finally {
            curl_close($curl);
        }
    }
}
