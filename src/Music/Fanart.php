<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use RuntimeException;

final class Fanart
{
    public function __construct(private readonly Repository $repo, private readonly ?FanartGateway $api = null) {}

    public static function imageUrl(mixed $url): string
    {
        if (!is_string($url) || preg_match('~^https?://assets\.fanart\.tv/fanart/[a-zA-Z0-9/_-]+\.jpg$~D', $url) !== 1) return '';
        $url = preg_replace('~^http://~', 'https://', $url);
        return $url;
    }

    public static function select(array $data, string $artistId): string
    {
        if (($data['mbid_id'] ?? '') !== $artistId) throw new RuntimeException('Fanart.tv gaf een afwijkende artiestidentiteit.');
        $rows = $data['artistbackground'] ?? [];
        if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('Fanart.tv gaf ongeldige artiestachtergronden.');
        $best = '';
        $likes = -1;
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Fanart.tv gaf een ongeldige achtergrond.');
            $url = self::imageUrl($row['url'] ?? null);
            if ($url === '') throw new RuntimeException('Fanart.tv gaf een ongeldige achtergrond-URL.');
            $votes = filter_var($row['likes'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($votes === false) throw new RuntimeException('Fanart.tv gaf een ongeldig aantal stemmen.');
            if ($votes > $likes) {
                $likes = $votes;
                $best = $url;
            }
        }
        return $best;
    }

    public static function cacheKey(string $artistId, string $key): string
    {
        return 'fanart:artist-v1:' . hash('sha256', $key) . ':' . $artistId;
    }

    public function enrich(array $metadata): array
    {
        unset($metadata['fanart'], $metadata['fanart_warning']);
        $id = $metadata['artist_id'] ?? '';
        if (!is_string($id) || !Release::mbid($id) || $id === '89ad4ac3-39f7-470e-963a-56509c546377') return $metadata;
        $settings = $this->repo->fanartSettings();
        if (!$this->repo->enabled() || !$settings['enabled'] || $settings['api_key'] === '') return $metadata;
        $key = self::cacheKey($id, $settings['api_key']);
        $cached = $this->repo->cached($key);
        if ($cached === null && $this->api !== null) {
            if (!$this->repo->lock($key)) return $metadata + ['fanart_warning' => 'Artiest-fanart wordt al opgehaald; probeer later opnieuw.'];
            try {
                $cached = $this->repo->cached($key);
                if ($cached === null) {
                    $data = $this->api->get($id);
                    $url = $data === null ? '' : self::select($data, $id);
                    $cached = ['url' => $url, 'error' => false];
                    $this->repo->cache($key, $cached, $url === '' ? 86400 : 604800);
                }
            } catch (\PDOException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                error_log('Fanart.tv: ' . $exception->getMessage());
                $cached = ['url' => '', 'error' => true];
                $this->repo->cache($key, $cached, 300);
            } finally {
                $this->repo->unlock($key);
            }
        }
        if ($cached['error'] ?? false) return $metadata + ['fanart_warning' => 'Artiest-fanart kon niet worden opgehaald bij fanart.tv. Probeer over vijf minuten opnieuw; muziekmetadata blijft beschikbaar.'];
        $url = self::imageUrl($cached['url'] ?? null);
        return $url === '' ? $metadata : $metadata + ['fanart' => $url];
    }
}
