<?php

declare(strict_types=1);

$slowNntpProcess = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php', '--slow-image'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $slowNntpPipes);
if (!is_resource($slowNntpProcess)) {
    throw new RuntimeException('Trage NNTP-testserver kon niet starten.');
}
$secondHttpProcess = null;
$imageSocket = null;
$savedConcurrencySettings = $state->getUsenetSettings();
try {
    stream_set_timeout($slowNntpPipes[1], 5);
    $slowNntpPort = (int) trim((string) fgets($slowNntpPipes[1]));
    check($slowNntpPort > 0, 'Trage NNTP-testserver is bereikbaar');
    $slowSettings = $savedConcurrencySettings;
    $slowSettings['port'] = $slowNntpPort;
    $state->saveUsenetSettings($slowSettings);
    $pdo->prepare('DELETE FROM spotnet_images WHERE spot_id = :id')->execute(['id' => $spotId]);

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($listener === false) throw new RuntimeException($error, $errno);
    $secondHttpPort = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
    fclose($listener);
    $secondHttpProcess = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $secondHttpPort, '-t', __DIR__ . '/../public'], [
        ['pipe', 'r'], $httpLog, $httpLog,
    ], $secondHttpPipes, dirname(__DIR__), $environment);
    if (!is_resource($secondHttpProcess)) {
        throw new RuntimeException('Tweede HTTP-testserver kon niet starten.');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $secondHttpPort, $errno, $error, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    check($ready, 'Tweede HTTP-worker is bereikbaar voor echte gelijktijdigheid');
    $imageSocket = stream_socket_client('tcp://127.0.0.1:' . $secondHttpPort, $errno, $error, 5);
    if ($imageSocket === false) throw new RuntimeException($error, $errno);
    stream_set_timeout($imageSocket, 10);
    fwrite($imageSocket, "GET /spots/image?key=$spotId HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: $cookie\r\nConnection: close\r\n\r\n");
    check(trim((string) fgets($slowNntpPipes[1])) === 'IMAGE_STARTED', 'Afbeelding wacht daadwerkelijk op trage NNTP-provider');
    $started = hrtime(true);
    $parallelBatch = httpFixture($httpPort, '/spots?batch=1', $cookie);
    $parallelStatus = httpFixture($httpPort, '/retrieve/status', $cookie);
    $seconds = (hrtime(true) - $started) / 1000000000;
    $read = [$imageSocket];
    $write = $except = [];
    $imageStillPending = stream_select($read, $write, $except, 0) === 0;
    $imageBytes = stream_get_contents($imageSocket);
    check($parallelBatch['status'] === 200 && $parallelStatus['status'] === 200
        && $seconds < 1.5 && $imageStillPending && str_ends_with($imageBytes, Fixtures::image()),
        sprintf('Trage afbeelding blokkeert dezelfde sessie niet: batch + status %.3f s tijdens 3 s NNTP-wachttijd', $seconds));
} finally {
    $state->saveUsenetSettings($savedConcurrencySettings);
    if (is_resource($imageSocket)) fclose($imageSocket);
    if (is_resource($secondHttpProcess)) {
        proc_terminate($secondHttpProcess);
        foreach ($secondHttpPipes as $pipe) fclose($pipe);
        proc_close($secondHttpProcess);
    }
    proc_terminate($slowNntpProcess);
    foreach ($slowNntpPipes as $pipe) fclose($pipe);
    proc_close($slowNntpProcess);
}
