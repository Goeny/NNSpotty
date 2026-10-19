<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use RuntimeException;

final class Client implements Gateway
{
    private readonly float $deadline;

    public function __construct(private readonly Repository $repo, private readonly string $contact)
    {
        $this->deadline = microtime(true) + 22;
    }

    public function get(string $type, ?string $id, array $query = []): ?array
    {
        if (!in_array($type, ['artist', 'release-group', 'release'], true) || ($id !== null && !Release::mbid($id))) {
            throw new \InvalidArgumentException('Ongeldig MusicBrainz-endpoint.');
        }
        $url = parse_url($this->contact);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || preg_match('/[\x00-\x20\x7f()]/', $this->contact)) {
            throw new RuntimeException('Stel APP_URL in op een publieke HTTP(S)-project/contact-URL zonder geheimen voor de MusicBrainz User-Agent.');
        }
        if (!$this->repo->enabled()) throw new RuntimeException('Muziekmetadata is uitgeschakeld.');
        $this->repo->claimRequest();
        $remaining = (int) (($this->deadline - microtime(true)) * 1000);
        if ($remaining < 1) throw new RuntimeException('MusicBrainz-tijdslimiet bereikt.');
        $curl = curl_init('https://musicbrainz.org/ws/2/' . $type . ($id === null ? '' : '/' . $id)
            . '?' . http_build_query(['fmt' => 'json'] + $query));
        if ($curl === false) throw new RuntimeException('MusicBrainz HTTP-client kon niet starten.');
        $body = '';
        try {
            if (!curl_setopt_array($curl, [
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NNSpotty/0.0.1 (' . $this->contact . ')'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remaining), CURLOPT_TIMEOUT_MS => min(8000, $remaining),
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]) || curl_exec($curl) === false) throw new RuntimeException('MusicBrainz-transport mislukt (cURL ' . curl_errno($curl) . '). Controleer ook de CA-configuratie.');
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status === 404) return null;
            if ($status !== 200) throw new RuntimeException('MusicBrainz-aanvraag mislukt (HTTP ' . $status . '). Probeer later opnieuw.');
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data)) throw new RuntimeException('MusicBrainz gaf geen geldig JSON-object.');
            return $data;
        } finally {
            curl_close($curl);
        }
    }
}
