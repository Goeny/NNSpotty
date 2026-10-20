<?php

declare(strict_types=1);

namespace NnSpotty\Newznab;

use InvalidArgumentException;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\SpotNzbService;
use NnSpotty\Usenet\SpotRepository;
use PDO;
use Throwable;

final class Api
{
    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state, private readonly string $url)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
                return self::error(203, 'Alleen GET wordt ondersteund.', 405);
            }
            if (!$this->state->apiEnabled()) {
                return self::error(203, 'Newznab is door de beheerder uitgeschakeld.', 503);
            }
            $base = rtrim($this->url, '/');
            $url = parse_url($base);
            if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
                || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query'])
                || isset($url['fragment']) || ($url['path'] ?? '') !== '') {
                throw new \RuntimeException('APP_URL moet een absolute HTTP(S)-origin zonder subpad zijn.');
            }
            $type = $request->queryScalar('t') ?? 'search';
            $format = $request->queryScalar('o') ?? 'xml';
            if ($format !== 'xml') {
                return self::error(201, 'Alleen o=xml wordt ondersteund.');
            }
            $key = $request->queryScalar('apikey') ?? '';
            $keys = new ApiKeys($this->pdo);
            $username = $key === '' ? null : $keys->authenticate($key);
            if (($type !== 'caps' || $key !== '') && $username === null) {
                return self::error(100, 'Ongeldige of ontbrekende API-key.');
            }
            if ($username !== null && !$keys->allowRequest($username)) {
                return self::error(500, 'API-limiet bereikt: maximaal 120 aanvragen per minuut.', 429);
            }
            $adult = $username !== null && $this->state->canViewErotica($username);
            if ($type === 'caps') {
                return self::xml(self::caps($base, $adult));
            }
            if ($type === 'get') {
                if (!\NnSpotty\Users\Permissions::allows((new \NnSpotty\Users\UserRepository($this->pdo))->findByUsername($username), 'nzb.download')) {
                    return self::error(203, 'NZB-download niet toegestaan.', 403);
                }
                $id = $request->queryScalar('id') ?? '';
                if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
                    return self::error(201, 'Ongeldig spot-ID.');
                }
                $spot = (new SpotRepository($this->pdo))->getSpot($id, $adult);
                if ($spot === null || $spot['nzb_segments'] === []) {
                    return self::error(300, 'NZB niet beschikbaar.');
                }
                try {
                    return SpotNzbService::download((new SpotNzbService($this->pdo, $this->state))->load($spot), $id);
                } catch (Throwable $exception) {
                    error_log('Newznab NZB ' . $id . ': ' . $exception->getMessage());
                    return self::error(300, 'NZB ophalen mislukt. Controleer de serverlog.', 502);
                }
            }
            if (!in_array($type, ['search', 'tvsearch', 'movie'], true)) {
                return self::error(203, 'Functie niet beschikbaar.');
            }
            foreach (['rid', 'tvdbid', 'tvmazeid', 'imdbid', 'tmdbid', 'traktid', 'genre', 'artist', 'album', 'author', 'title'] as $unsupported) {
                if ($request->queryScalar($unsupported) !== null) {
                    return self::error(203, 'Niet ondersteunde zoekparameter: ' . $unsupported . '. Gebruik titelzoeken via q.');
                }
            }
            $result = (new Search($this->pdo))->run($request, $adult, $type);
            return self::xml(self::feed($base, $key, $result));
        } catch (InvalidArgumentException $exception) {
            return self::error(201, $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Newznab: ' . $exception->getMessage());
            return self::error(900, 'Interne API-fout. Controleer de serverlog.', 500);
        }
    }

    public static function error(int $code, string $message, int $status = 200): Response
    {
        return self::xml('<error code="' . $code . '" description="' . self::x($message) . '"/>', $status);
    }

    private static function xml(string $xml, int $status = 200): Response
    {
        return new Response($status, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ] + ($status === 429 ? ['Retry-After' => '60'] : [])
            + ($status === 405 ? ['Allow' => 'GET, HEAD'] : []));
    }

    private static function x(string $value): string
    {
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if ($value === null) {
            throw new \RuntimeException('API-tekst is geen geldige UTF-8.');
        }
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function caps(string $base, bool $adult): string
    {
        $xml = '<caps><server version="1.0" title="NNSpotty" url="' . self::x($base) . '"/>'
            . '<limits max="100" default="100"/><registration available="no" open="no"/>'
            . '<searching><search available="yes" supportedParams="q"/>'
            . '<tv-search available="yes" supportedParams="q,season,ep"/>'
            . '<movie-search available="yes" supportedParams="q,year"/>'
            . '<audio-search available="no"/><book-search available="no"/></searching><categories>';
        $definitions = Categories::definitions();
        foreach ($definitions as $id => $name) {
            if ($id % 1000 !== 0 || (!$adult && $id === 6000)) {
                continue;
            }
            $xml .= '<category id="' . $id . '" name="' . self::x($name) . '">';
            foreach ($definitions as $child => $childName) {
                if ($child > $id && $child < $id + 1000) {
                    $xml .= '<subcat id="' . $child . '" name="' . self::x(explode('/', $childName, 2)[1]) . '"/>';
                }
            }
            $xml .= '</category>';
        }
        return $xml . '</categories></caps>';
    }

    /** @param array{items: list<array<string, mixed>>, total: int, offset: int} $result */
    private static function feed(string $base, string $key, array $result): string
    {
        $xml = '<rss version="2.0" xmlns:newznab="http://www.newznab.com/DTD/2010/feeds/attributes/">'
            . '<channel><title>NNSpotty</title><description>NNSpotty Spotnet index</description><link>' . self::x($base) . '</link>'
            . '<language>nl-NL</language><newznab:response offset="' . $result['offset'] . '" total="' . $result['total'] . '"/>';
        foreach ($result['items'] as $item) {
            $download = $base . '/api?' . http_build_query(['t' => 'get', 'id' => $item['id'], 'apikey' => $key], '', '&', PHP_QUERY_RFC3986);
            $details = $base . '/spots/view?key=' . $item['id'];
            $xml .= '<item><title>' . self::x($item['title']) . '</title><guid isPermaLink="false">' . $item['id'] . '</guid>'
                . '<link>' . self::x($download) . '</link><comments>' . self::x($details . '#comments') . '</comments>'
                . '<pubDate>' . gmdate(DATE_RSS, strtotime($item['posted_at'] . ' UTC')) . '</pubDate>'
                . '<description>' . self::x(strip_tags($item['description'])) . '</description>'
                . '<enclosure url="' . self::x($download) . '" length="0" type="application/x-nzb"/>'
                . '<newznab:attr name="size" value="' . (int) $item['size'] . '"/>'
                . '<newznab:attr name="poster" value="' . self::x($item['poster']) . '"/>'
                . '<newznab:attr name="guid" value="' . $item['id'] . '"/>';
            foreach (Categories::forSpot($item['category'], $item['subcategories']) as $category) {
                $xml .= '<category>' . self::x(Categories::definitions()[$category]) . '</category>'
                    . '<newznab:attr name="category" value="' . $category . '"/>';
            }
            $xml .= '</item>';
        }
        return $xml . '</channel></rss>';
    }
}
