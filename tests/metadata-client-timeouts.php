<?php

declare(strict_types=1);

namespace {
    // Standalone regression test: real clients/repositories, simulated time and HTTP, no provider calls.
    require __DIR__ . '/../src/Metadata/Gateway.php';
    require __DIR__ . '/../src/Metadata/Repository.php';
    require __DIR__ . '/../src/Metadata/Client.php';
    require __DIR__ . '/../src/Metadata/TmdbClient.php';
    require __DIR__ . '/../src/Music/Gateway.php';
    require __DIR__ . '/../src/Music/Repository.php';
    require __DIR__ . '/../src/Music/Client.php';

    final class TimeoutStatement extends PDOStatement
    {
        public function __construct(private readonly string $sql) {}
        public function execute(?array $params = null): bool { return true; }
        public function fetchColumn(int $column = 0): mixed
        {
            if (str_contains($this->sql, 'GET_LOCK') || str_contains($this->sql, 'RELEASE_LOCK')
                || str_contains($this->sql, 'SELECT enabled FROM music_settings')) return 1;
            return str_contains($this->sql, 'TIMESTAMPDIFF') ? null : false;
        }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return ['enabled' => true, 'api_key' => 'fixture', 'pin' => '', 'language' => 'eng',
                'token' => 'fixture-token', 'token_until' => gmdate('Y-m-d H:i:s', time() + 3600)];
        }
    }

    final class TimeoutPdo extends PDO
    {
        public int $claims = 0;
        public function __construct() {}
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_contains($query, 'GET_LOCK')) $this->claims++;
            return new TimeoutStatement($query);
        }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            return new TimeoutStatement($query);
        }
        public function exec(string $statement): int|false { return 1; }
    }

    final class TimeoutHttp
    {
        public static float $clock = 0;
        public static array $requests = [];
        public static function time(): float { return self::$clock += 30; }
        public static function options(object $handle, array $options): bool
        {
            if ($options[CURLOPT_CONNECTTIMEOUT_MS] !== 3000 || $options[CURLOPT_TIMEOUT_MS] !== 8000) {
                throw new RuntimeException('Each request must have its full, bounded network timeout.');
            }
            self::$requests[] = $handle->url;
            $handle->options = $options;
            return true;
        }
        public static function execute(object $handle): bool
        {
            $body = str_contains($handle->url, 'thetvdb.com')
                ? '{"status":"success","data":{"fixture":true}}' : '{"fixture":true}';
            $handle->options[CURLOPT_WRITEFUNCTION]($handle, $body);
            return true;
        }
    }
}

namespace NnSpotty\Metadata {
    function microtime(bool $asFloat = false): float { return \TimeoutHttp::time(); }
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { return \TimeoutHttp::options($handle, $options); }
    function curl_exec(object $handle): bool { return \TimeoutHttp::execute($handle); }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace NnSpotty\Music {
    function microtime(bool $asFloat = false): float { return \TimeoutHttp::time(); }
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { return \TimeoutHttp::options($handle, $options); }
    function curl_exec(object $handle): bool { return \TimeoutHttp::execute($handle); }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace {
    $pdo = new TimeoutPdo();
    $video = new NnSpotty\Metadata\Repository($pdo);
    $clients = [
        [new NnSpotty\Metadata\Client($video), '/search'],
        [new NnSpotty\Metadata\TmdbClient($video), '/search/movie'],
        [new NnSpotty\Music\Client(new NnSpotty\Music\Repository($pdo), 'https://example.invalid'), 'artist'],
    ];
    foreach ($clients as [$client, $path]) {
        // Reuse the same clients across a batch that has already lasted more than 22 seconds.
        foreach ([30, 60, 120] as $elapsed) {
            TimeoutHttp::$clock = $elapsed;
            $result = $client instanceof NnSpotty\Music\Client ? $client->get($path, null) : $client->get($path);
            if (($result['fixture'] ?? false) !== true) throw new RuntimeException('Unexpected client result.');
        }
    }
    if (count(TimeoutHttp::$requests) !== 9 || $pdo->claims !== 9) {
        throw new RuntimeException('Every request must pass through provider pacing.');
    }
    echo "PASS: all three reused clients retain per-request timeouts beyond 22 seconds; pacing remains active.\n";
}
