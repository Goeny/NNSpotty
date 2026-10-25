<?php

declare(strict_types=1);

namespace NnSpotty\System;

use Closure;
use RuntimeException;

final class Releases
{
    public function __construct(private readonly Process $process, private readonly ?Closure $fetch = null) {}

    /** @return array{provider: string, api: string, web: string} */
    public static function origin(string $remote): array
    {
        if (preg_match('~^git@([^:]+):(.+)$~D', $remote, $match)) $remote = 'https://' . $match[1] . '/' . $match[2];
        $url = parse_url($remote);
        if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https', 'ssh'], true)
            || empty($url['host']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new RuntimeException('Origin moet een GitHub- of GitLab-URL zonder token/wachtwoord zijn.');
        }
        if (isset($url['user']) && !(($url['scheme'] ?? '') === 'ssh' && $url['user'] === 'git')) {
            throw new RuntimeException('Bewaar origin-credentials in de Git credential helper, niet in de URL.');
        }
        $path = preg_replace('~\.git$~', '', trim($url['path'] ?? '', '/'));
        if (!$path || preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)+$~D', $path) !== 1
            || in_array('..', explode('/', $path), true)) throw new RuntimeException('Ongeldig repositorypad.');
        $host = strtolower($url['host']);
        $scheme = $url['scheme'] === 'ssh' ? 'https' : $url['scheme'];
        $base = $scheme . '://' . $host . (isset($url['port']) && $url['scheme'] !== 'ssh' ? ':' . $url['port'] : '');
        if ($host === 'github.com') {
            if (substr_count($path, '/') !== 1 || $scheme !== 'https') throw new RuntimeException('GitHub vereist HTTPS en owner/repository.');
            return ['provider' => 'github', 'api' => 'https://api.github.com/repos/' . $path . '/releases?per_page=100',
                'web' => 'https://github.com/' . $path];
        }
        return ['provider' => 'gitlab', 'api' => $base . '/api/v4/projects/' . rawurlencode($path) . '/releases?per_page=100',
            'web' => $base . '/' . $path];
    }

    public function latest(string $root, ?string $tag = null): ?array
    {
        $origin = self::origin($this->process->require(['git', 'config', '--get', 'remote.origin.url'], $root));
        $all = [];
        for ($page = 1; $page <= 10; $page++) {
            $payload = $this->fetch !== null ? ($this->fetch)($origin['api'] . '&page=' . $page)
                : self::request($origin['api'] . '&page=' . $page);
            if (!is_array($payload) || !array_is_list($payload)) throw new RuntimeException('Release-API gaf geen lijst terug.');
            $all = [...$all, ...$payload];
            if (count($payload) < 100) {
                if ($tag !== null) $all = array_values(array_filter($all, static fn ($release): bool => is_array($release) && ($release['tag_name'] ?? null) === $tag));
                return self::select($all, $origin['provider'], $origin['web']);
            }
        }
        throw new RuntimeException('Releasecontrole overschrijdt de limiet van 1000 releases.');
    }

    public static function select(array $releases, string $provider, string $web): ?array
    {
        $latest = null;
        foreach ($releases as $release) {
            if (!is_array($release) || !is_string($release['tag_name'] ?? null)) throw new RuntimeException('Ongeldig releasebericht.');
            if (($release['draft'] ?? false) || ($release['prerelease'] ?? false) || ($release['upcoming_release'] ?? false)) continue;
            $tag = $release['tag_name'];
            if (preg_match('/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $tag, $match) !== 1) continue;
            $date = $release[$provider === 'github' ? 'published_at' : 'released_at'] ?? null;
            if (!is_string($date) || strtotime($date) === false) throw new RuntimeException('Releasedatum ontbreekt of is ongeldig.');
            if (strtotime($date) > time()) continue;
            if ($latest === null || version_compare($match[1], $latest['version'], '>')) {
                $latest = ['tag' => $tag, 'version' => $match[1], 'name' => is_string($release['name'] ?? null) ? $release['name'] : $tag,
                    'url' => $web . ($provider === 'github' ? '/releases/tag/' : '/-/releases/') . rawurlencode($tag),
                    'published_at' => $date];
            }
        }
        return $latest;
    }

    private static function request(string $url): array
    {
        $curl = curl_init($url);
        if ($curl === false) throw new RuntimeException('Kan releasecontrole niet starten.');
        $body = '';
        try {
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'NNSpotty-release-updater',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                }]);
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) {
                throw new RuntimeException('Releasecontrole mislukt (HTTP ' . $status . '). Controleer bereikbaarheid, API-toegang en rate limits; geen branchupdate uitgevoerd.');
            }
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('Release-API gaf ongeldige JSON terug.');
            return $decoded;
        } finally {
            curl_close($curl);
        }
    }
}
