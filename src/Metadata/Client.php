<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use RuntimeException;

final class Client implements Gateway
{
    private readonly float $deadline;

    public function __construct(private readonly Repository $repo)
    {
        $this->deadline = microtime(true) + 22;
    }

    public function get(string $path, array $query = []): ?array
    {
        if (!preg_match('~^/(search|search/remoteid/tt[0-9]{5,10}|artwork/types|(?:movies|series|episodes)/[1-9][0-9]*(?:/extended|/translations/(?:nld|eng)|/episodes/official)?)$~D', $path)) {
            throw new RuntimeException('Ongeldig TVDB-endpoint.');
        }
        $settings = $this->repo->settings();
        if (!$settings['enabled'] || $settings['api_key'] === '') throw new RuntimeException('TVDB is niet ingesteld.');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->authenticate($settings, $attempt === 1);
            [$status, $data] = $this->request($path . ($query === [] ? '' : '?' . http_build_query($query)), $token);
            if ($status === 401 && $attempt === 0) continue;
            if ($status === 404) return null;
            if ($status !== 200 || ($data['status'] ?? '') !== 'success' || !is_array($data['data'] ?? null)) {
                throw new RuntimeException('TVDB-aanvraag mislukt (HTTP ' . $status . '). Controleer toegang of API-limiet.');
            }
            return $data['data'];
        }
        throw new RuntimeException('TVDB-authenticatie mislukt.');
    }

    private function authenticate(array $settings, bool $renew): string
    {
        if (!$renew && is_string($settings['token']) && strtotime($settings['token_until'] . ' UTC') > time()) {
            return $settings['token'];
        }
        if (!$this->repo->lock('authentication')) throw new RuntimeException('TVDB meldt zich al aan; probeer later opnieuw.');
        try {
            $current = $this->repo->settings();
            if (!$renew && is_string($current['token']) && strtotime($current['token_until'] . ' UTC') > time()) return $current['token'];
            $credentials = ['apikey' => $settings['api_key']];
            if ($settings['pin'] !== '') $credentials['pin'] = $settings['pin'];
            [$status, $data] = $this->request('/login', null, $credentials);
            $token = $data['data']['token'] ?? null;
            if ($status !== 200 || !is_string($token) || $token === '' || strlen($token) > 10000 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException('TVDB-login mislukt. Controleer API-key, abonnement en eventuele PIN.');
            }
            $this->repo->token($settings['api_key'], $settings['pin'], $token);
            return $token;
        } finally {
            $this->repo->unlock('authentication');
        }
    }

    private function request(string $path, ?string $token, ?array $body = null): array
    {
        $remaining = (int) (($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) throw new RuntimeException('TVDB-tijdslimiet bereikt. Probeer later opnieuw.');
        $this->repo->claimRequest();
        $curl = curl_init('https://api4.thetvdb.com/v4' . $path);
        if ($curl === false) throw new RuntimeException('TVDB HTTP-client kon niet starten.');
        $response = '';
        $headers = ['Accept: application/json', 'User-Agent: NNSpotty/0.0.1'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        try {
            $options = [
                CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => min(8000, $remaining),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            }
            if (!curl_setopt_array($curl, $options) || curl_exec($curl) === false) {
                throw new RuntimeException('TVDB-transport mislukt (cURL-code ' . curl_errno($curl) . ').');
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status !== 200) return [$status, []];
            $data = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('TVDB gaf geen geldig JSON-object.');
            return [$status, $data];
        } finally {
            curl_close($curl);
        }
    }
}
