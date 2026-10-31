<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use RuntimeException;

final class NzbgetClient implements DownloadClient
{
    private const STATUS_LABELS = ['PP_QUEUED' => 'Post-processing queued', 'PP_FINISHED' => 'Post-processing finished'];

    public function __construct(
        private readonly string $url,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $internalAllowed,
    ) {
    }

    public function version(): string
    {
        $version = $this->call('version');
        if (!is_string($version) || preg_match('/^(\d{1,3})(?:[.\-][0-9A-Za-z.\-]{0,30})?$/D', $version, $match) !== 1) {
            throw new RuntimeException('NZBGet gaf geen geldige versie.');
        }
        if ((int) $match[1] < 16) {
            throw new NzbgetRejected('NZBGet 16 of nieuwer is vereist.');
        }
        return $version;
    }

    /** @return list<string> */
    public function categories(): array
    {
        $config = $this->call('config');
        if (!is_array($config) || !array_is_list($config)) {
            throw new RuntimeException('NZBGet gaf geen geldige configuratie.');
        }
        $categories = [];
        // The config response also contains secrets; only category names are extracted and nothing else is kept.
        foreach ($config as $option) {
            if (is_array($option) && is_string($option['Name'] ?? null) && is_string($option['Value'] ?? null)
                && preg_match('/^Category\d{1,4}\.Name$/iD', $option['Name']) === 1 && trim($option['Value']) !== '') {
                $categories[] = trim($option['Value']);
            }
        }
        return array_values(array_unique($categories));
    }

    public function queue(int $offset, int $limit): array
    {
        $status = $this->call('status');
        if (!is_array($status) || !isset($status['DownloadRate']) || !is_int($status['DownloadRate']) || $status['DownloadRate'] < 0
            || !is_bool($status['DownloadPaused'] ?? null)) {
            throw new RuntimeException('NZBGet gaf een onvolledige status.');
        }
        $groups = $this->call('listgroups', [0]);
        if (!is_array($groups) || !array_is_list($groups)) {
            throw new RuntimeException('NZBGet gaf een onvolledige wachtrij.');
        }
        $rate = $status['DownloadRate'];
        $slots = [];
        $remaining = 0;
        foreach ($groups as $index => $group) {
            if (!is_array($group) || !is_string($group['NZBName'] ?? null) || !is_string($group['Status'] ?? null)
                || !is_string($group['Category'] ?? null) || !is_int($group['FileSizeMB'] ?? null) || !is_int($group['RemainingSizeMB'] ?? null)
                || $group['FileSizeMB'] < 0 || $group['RemainingSizeMB'] < 0) {
                throw new RuntimeException('NZBGet gaf een onvolledig wachtrij-item.');
            }
            // Like SABnzbd, the ETA is cumulative in queue order.
            $remaining += $group['RemainingSizeMB'];
            if ($index < $offset || count($slots) >= $limit) {
                continue;
            }
            $size = $group['FileSizeMB'];
            $slots[] = [
                'title' => $group['NZBName'],
                'status' => self::STATUS_LABELS[$group['Status']] ?? ucfirst(strtolower(str_replace('_', ' ', $group['Status']))),
                'percentage' => $size > 0 ? round(max(0, min(100, ($size - $group['RemainingSizeMB']) / $size * 100)), 1) : 0.0,
                'category' => $group['Category'],
                'eta' => $rate > 0 ? self::duration((int) ceil($remaining * 1048576 / $rate)) : '--',
            ];
        }
        $state = $status['DownloadPaused'] ? 'Paused' : ($rate > 0 ? 'Downloading' : 'Idle');
        return ['status' => $state, 'speed' => (float) $rate, 'total' => count($groups), 'slots' => $slots];
    }

    public function add(string $title, string $nzb, string $category): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]+/', ' ', $title));
        $name = mb_substr($name === '' ? 'nnspotty' : $name, 0, 200);
        $id = $this->call('append', [$name . '.nzb', base64_encode($nzb), $category, 0, false, false, '', 0, 'SCORE', []]);
        if (!is_int($id)) {
            throw new RuntimeException('NZBGet heeft geen geldig download-ID bevestigd.');
        }
        if ($id <= 0) {
            throw new NzbgetRejected('NZBGet heeft de NZB geweigerd.');
        }
        return (string) $id;
    }

    public function call(string $method, array $parameters = []): mixed
    {
        $endpoint = Endpoint::resolve($this->url, $this->internalAllowed, '/jsonrpc');
        $body = json_encode(['method' => $method, 'params' => $parameters, 'id' => 1], JSON_THROW_ON_ERROR);
        $response = HttpTransport::post($endpoint, $body, 'NZBGet', ['Content-Type: application/json', 'Accept: application/json'],
            $this->username . ':' . $this->password);
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new NzbgetRejected('NZBGet weigerde de inloggegevens. Controleer gebruikersnaam, wachtwoord en rechten.');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('NZBGet gaf HTTP ' . $response['status'] . '; redirects worden niet gevolgd.');
        }
        $data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('NZBGet gaf geen JSON-object.');
        }
        if (isset($data['error']) || !array_key_exists('result', $data)) {
            // Do not relay remote error text: it can contain credentials or active markup.
            throw new NzbgetRejected('NZBGet heeft de aanvraag geweigerd. Controleer inloggegevens, rechten en categorie.');
        }
        return $data['result'];
    }

    private static function duration(int $seconds): string
    {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
