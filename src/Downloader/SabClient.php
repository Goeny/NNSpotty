<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use RuntimeException;

final class SabClient implements DownloadClient
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

    public function queue(int $offset, int $limit): array
    {
        $response = $this->call('queue', ['start' => (string) $offset, 'limit' => $limit]);
        $queue = $response['queue'] ?? null;
        if (!is_array($queue) || !isset($queue['status'], $queue['kbpersec'], $queue['slots'], $queue['noofslots_total'])
            || !is_string($queue['status']) || !is_array($queue['slots']) || !array_is_list($queue['slots'])
            || !is_numeric($queue['kbpersec']) || !is_finite((float) $queue['kbpersec']) || (float) $queue['kbpersec'] < 0
            || !(is_int($queue['noofslots_total']) || is_string($queue['noofslots_total']))
            || !ctype_digit((string) $queue['noofslots_total'])) {
            throw new RuntimeException('SABnzbd gaf een onvolledige wachtrij.');
        }
        $slots = [];
        foreach (array_slice($queue['slots'], 0, $limit) as $slot) {
            if (!is_array($slot) || !isset($slot['filename'], $slot['status'], $slot['percentage'], $slot['cat'], $slot['timeleft'])
                || !is_string($slot['filename']) || !is_string($slot['status']) || !is_string($slot['cat']) || !is_string($slot['timeleft'])
                || !is_numeric($slot['percentage']) || !is_finite((float) $slot['percentage'])) {
                throw new RuntimeException('SABnzbd gaf een onvolledig wachtrij-item.');
            }
            $slots[] = ['title' => $slot['filename'], 'status' => $slot['status'],
                'percentage' => max(0, min(100, (float) $slot['percentage'])), 'category' => $slot['cat'], 'eta' => $slot['timeleft']];
        }
        return ['status' => $queue['status'], 'speed' => (float) $queue['kbpersec'] * 1024,
            'total' => (int) $queue['noofslots_total'], 'slots' => $slots];
    }

    public function add(string $title, string $nzb, string $category): string
    {
        $result = $this->call('addfile', ['nzbname' => $title, 'cat' => $category === '' ? '*' : $category], $nzb);
        $ids = $result['nzo_ids'] ?? null;
        if (($result['status'] ?? null) !== true || !is_array($ids) || count($ids) !== 1
            || !is_string($ids[0]) || strlen($ids[0]) > 200 || !preg_match('/^[a-zA-Z0-9_-]+$/D', $ids[0])) {
            throw new RuntimeException('SABnzbd heeft geen geldig download-ID bevestigd.');
        }
        return $ids[0];
    }

    /** @return array<string, mixed> */
    public function call(string $mode, array $parameters = [], ?string $nzb = null): array
    {
        $endpoint = Endpoint::resolve($this->url, $this->internalAllowed);
        $fields = ['mode' => $mode, 'apikey' => $this->key, 'output' => 'json'] + $parameters;
        if ($nzb !== null) {
            $fields['nzbfile'] = new \CURLStringFile($nzb, 'nnspotty.nzb', 'application/x-nzb');
        }
        $response = HttpTransport::post($endpoint, $nzb === null ? http_build_query($fields) : $fields, 'SABnzbd');
        if ($response['status'] !== 200) {
            throw new RuntimeException('SABnzbd gaf HTTP ' . $response['status'] . '; redirects worden niet gevolgd.');
        }
        $data = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('SABnzbd gaf geen JSON-object.');
        }
        if (($data['status'] ?? null) === false || isset($data['error'])) {
            // Do not relay remote error text: it can contain credentials or active markup.
            throw new SabRejected('SABnzbd heeft de aanvraag geweigerd. Controleer API-key, rechten en categorie.');
        }
        return $data;
    }
}
