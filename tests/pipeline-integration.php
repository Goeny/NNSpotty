<?php

declare(strict_types=1);

use NnSpotty\Usenet\NntpClientFactory;
use NnSpotty\Usenet\PipelineConnection;

function withPipelineServer(array $options, callable $test): mixed
{
    $pipes = [];
    $log = tmpfile();
    if ($log === false) throw new RuntimeException('Kon pipeline fixturelog niet openen.');
    $command = [PHP_BINARY, __DIR__ . '/pipeline-server.php'];
    foreach ($options as $key => $value) $command[] = '--' . $key . '=' . $value;
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], $log], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Kon pipeline TCP-fixture niet starten.');
    try {
        $port = (int) trim(fgets($pipes[1]));
        check($port > 0, 'Echte pipeline NNTP-fixture bereikbaar');
        $result = $test($port);
        $trace = json_decode(trim(stream_get_contents($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
        return [$result, $trace];
    } finally {
        proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        fclose($log);
    }
}

$pipelineSettings = $state->getUsenetSettings();
check($pipelineSettings['pipeline_depth'] === 32, 'Bestaande installaties krijgen pipeline-diepte 32 zonder schemawijziging');
foreach ([0, 129, '32', 1.5] as $invalidDepth) {
    $invalidSettings = $pipelineSettings;
    $invalidSettings['pipeline_depth'] = $invalidDepth;
    rejects(static fn () => $state->saveUsenetSettings($invalidSettings), 'Opslag weigert ongeldige pipeline-diepte');
    check($state->getUsenetSettings() === $pipelineSettings, 'Ongeldige pipeline-diepte wijzigt opgeslagen instellingen niet');
}
$timings = [];
foreach ([true, false] as $head) {
    foreach ([1, 32, 128] as $depth) {
        [$measurement, $trace] = withPipelineServer(['depth' => $depth, 'count' => 131, 'delay-ms' => 20],
            static function (int $port) use ($head, $depth): array {
                $client = NntpClientFactory::connectFromSettings(['server' => '127.0.0.1', 'port' => $port,
                    'username' => '', 'password' => '', 'encryption' => 'none']);
                try {
                    $connection = $client->getConnection();
                    if (!$connection instanceof PipelineConnection) throw new LogicException('Onverwachte verbinding.');
                    $ids = array_map(static fn (int $n): string => 'pipe-' . $n . '@example.invalid', range(1, 131));
                    $start = hrtime(true);
                    $results = iterator_to_array($connection->articles($ids, $head, $depth));
                    $seconds = (hrtime(true) - $start) / 1e9;
                    $group = $client->group('pipeline.spots');
                    check((int) $group['last'] === 131, 'Normaal php-nntp commando na volledig pipelinevenster leest juiste volgende antwoord');
                    return ['seconds' => $seconds, 'results' => $results];
                } finally {
                    $client->disconnect();
                }
            });
        $windows = array_fill(0, intdiv(131, $depth), $depth);
        if (131 % $depth !== 0) $windows[] = 131 % $depth;
        check(count($measurement['results']) === 131 && $trace['windows'] === $windows,
            'Echte TCP-server wacht op volledig venster: ' . ($head ? 'HEAD' : 'ARTICLE') . ' diepte ' . $depth);
        $timings[$head ? 'HEAD' : 'ARTICLE'][$depth] = $measurement['seconds'];
    }
}
foreach ($timings as $verb => $measurements) {
    check($measurements[32] < $measurements[1] * 0.5 && $measurements[128] < $measurements[1] * 0.5,
        $verb . ' pipelining is minstens tweemaal sneller bij synthetische 20ms-vensterlatentie');
    echo 'BENCHMARK ' . $verb . ': ' . json_encode($measurements, JSON_THROW_ON_ERROR) . ' seconden voor 131 artikelen; synthetische 20ms per venster.' . PHP_EOL;
}
try {
    foreach ([1, 32, 128] as $depth) {
        $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid'");
        $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name LIKE 'spotnet:spots:pipeline.%'");
        [$result, $trace] = withPipelineServer(['depth' => $depth, 'count' => 260, 'missing-at' => 3],
            function (int $port) use ($pipelineSettings, $state, $service, $depth): array {
                $config = array_merge($pipelineSettings, ['server' => '127.0.0.1', 'port' => $port, 'username' => '', 'password' => '',
                    'encryption' => 'none', 'pipeline_depth' => $depth, 'explicit_groups' => 'spots:pipeline.spots',
                    'max_articles_per_group' => 5000, 'retrieve_after' => null, 'retention' => 30]);
                $state->saveUsenetSettings($config);
                return $service->run('since-last', null, true, static function (string $line): void {});
            });
        check($result['inserted'] === 259 && $trace['served'] === 260
            && (int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:pipeline.spots'")->fetchColumn() === 260,
            'Retrieve slaat beide XOVER-batches correct op ondanks 430 en bewaart pas na verwerking cursor bij diepte ' . $depth);
        $ids = $pdo->query("SELECT message_id FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid' ORDER BY message_id")->fetchAll(PDO::FETCH_COLUMN);
        check(count($ids) === 259 && !in_array('pipe-3@example.invalid', $ids, true)
            && in_array('pipe-260@example.invalid', $ids, true), 'Geen verkeerde antwoordkoppeling over ontbrekend artikel of venstergrens');
    }
    foreach (['fail-at', 'disconnect-at'] as $failure) {
        $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid'");
        $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name = 'spotnet:spots:pipeline.spots'");
        withPipelineServer(['depth' => 32, 'count' => 260, $failure => 35],
            function (int $port) use ($state, $service, $pipelineSettings): void {
                $state->saveUsenetSettings(array_merge($pipelineSettings, ['server' => '127.0.0.1', 'port' => $port,
                    'username' => '', 'password' => '', 'encryption' => 'none', 'pipeline_depth' => 32,
                    'explicit_groups' => 'spots:pipeline.spots', 'retrieve_after' => null, 'retention' => 30]));
                try {
                    $service->run('since-last', null, false, static function (string $line): void {});
                    check(false, 'Transportfout moet retrieve laten falen');
                } catch (\Rvdv\Nntp\Exception\RuntimeException $exception) {
                    check(true, 'Pipeline transportfout faalt expliciet');
                }
            });
        check((int) $pdo->query("SELECT COUNT(*) FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid'")->fetchColumn() === 34
            && $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:pipeline.spots'")->fetchColumn() === false
            && $pdo->query('SELECT status FROM retrieval_runs ORDER BY id DESC LIMIT 1')->fetchColumn() === 'failed',
            'Fout midden in pipeline bewaart verwerkte spots, registreert mislukking en schuift cursor niet vooruit');
        [$resume, $trace] = withPipelineServer(['depth' => 32, 'count' => 260, 'skip-first' => 34],
            function (int $port) use ($state, $service): array {
                $settings = $state->getUsenetSettings();
                $settings['port'] = $port;
                $state->saveUsenetSettings($settings);
                return $service->run('since-last', null, false, static function (string $line): void {});
            });
        check($resume['inserted'] === 226 && $trace['served'] === 226
            && !in_array('HEAD <pipe-34@example.invalid>', $trace['trace'], true)
            && (int) $pdo->query("SELECT COUNT(*) FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid'")->fetchColumn() === 260
            && (int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:spots:pipeline.spots'")->fetchColumn() === 260,
            'Hervatten na pipelinefout slaat reeds opgeslagen spots voor transport over en voltooit ontbrekende artikelen zonder gaten');
    }
    [$refresh, $trace] = withPipelineServer(['depth' => 32, 'count' => 260],
        function (int $port) use ($state, $service): array {
            $settings = $state->getUsenetSettings();
            $settings['port'] = $port;
            $state->saveUsenetSettings($settings);
            return $service->run('all', null, false, static function (string $line): void {}, refreshSpots: true);
        });
    check($refresh['refreshed'] === 260 && $refresh['inserted'] === 0 && $trace['served'] === 260,
        'Metadata-refresh haalt ook bestaande spots gepipelined opnieuw op over meerdere XOVER-batches');
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id LIKE 'pipe-%@example.invalid'");
    [$comments, $trace] = withPipelineServer(['depth' => 32, 'count' => 260, 'missing-at' => 3],
        function (int $port) use ($state, $service): array {
            $settings = $state->getUsenetSettings();
            $settings['port'] = $port;
            $settings['explicit_groups'] = 'spots:pipeline.spots,comments:pipeline.comments';
            $state->saveUsenetSettings($settings);
            return $service->run('days', 30, false, static function (string $line): void {}, commentsOnly: true);
        });
    check($comments['inserted'] === 259 && $trace['served'] === 260
        && count(array_filter($trace['trace'], static fn (string $command): bool => str_starts_with($command, 'HEAD '))) === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM spotnet_comments WHERE message_id LIKE 'pipe-%@example.invalid'")->fetchColumn() === 259,
        'Gerichte reactierun haalt ARTICLE gepipelined op met correcte opslag ondanks ontbrekend artikel');
} finally {
    $state->saveUsenetSettings($pipelineSettings);
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'pipe-%@example.invalid'");
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id LIKE 'pipe-%@example.invalid'");
    $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name LIKE 'spotnet:%:pipeline.%'");
}
