<?php

declare(strict_types=1);

require __DIR__ . '/Fixtures.php';

$options = getopt('', ['depth:', 'count:', 'delay-ms:', 'fail-at:', 'disconnect-at:', 'missing-at:', 'skip-first:']);
$depth = (int) ($options['depth'] ?? 32);
$count = (int) ($options['count'] ?? 131);
$delay = (int) ($options['delay-ms'] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) throw new RuntimeException($error, $errno);
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . PHP_EOL;
flush();
$socket = stream_socket_accept($server, 15);
if ($socket === false) throw new RuntimeException('Pipeline fixture kreeg geen verbinding.');
stream_set_timeout($socket, 5);
fwrite($socket, "200 Pipeline fixture\r\n");
$queued = [];
$windows = [];
$served = 0;
$remaining = $count;
$group = '';
$trace = [];
function pipelineMultiline($socket, string $status, string $body): void
{
    $wire = $status . "\r\n";
    foreach (explode("\r\n", $body) as $line) $wire .= (str_starts_with($line, '.') ? '.' : '') . $line . "\r\n";
    $wire .= ".\r\n";
    while ($wire !== '') {
        $written = fwrite($socket, $wire);
        if ($written === false || $written === 0) throw new RuntimeException('Fixture write afgebroken.');
        $wire = substr($wire, $written);
    }
}
while (($line = fgets($socket)) !== false) {
    $command = rtrim($line, "\r\n");
    $trace[] = $command;
    if ($command === 'LIST OVERVIEW.FMT') {
        pipelineMultiline($socket, '215 Overview', "Subject:\r\nFrom:\r\nDate:\r\nMessage-ID:\r\nReferences:\r\n:bytes\r\n:lines");
    } elseif (str_starts_with($command, 'GROUP ')) {
        $group = substr($command, 6);
        fwrite($socket, "211 $count 1 $count $group\r\n");
    } elseif (preg_match('/^XOVER (\d+)-(\d+)$/', $command, $match)) {
        $rows = [];
        for ($n = (int) $match[1]; $n <= min($count, (int) $match[2]); $n++) {
            $id = 'pipe-' . $n . '@example.invalid';
            $rows[] = $n . "\tPipeline fixture\tPoster\t" . gmdate('r') . "\t<$id>\t\t123\t5";
        }
        $remaining = max(0, min($count, (int) $match[2]) - max((int) $match[1] - 1, (int) ($options['skip-first'] ?? 0)));
        pipelineMultiline($socket, '224 Overview', implode("\r\n", $rows));
    } elseif (preg_match('/^(HEAD|ARTICLE) <([^>]+)>$/', $command, $match)) {
        $queued[] = [$match[1], $match[2]];
        if (count($queued) < min($depth, $remaining)) continue;
        $windows[] = count($queued);
        if ($delay > 0) usleep($delay * 1000);
        foreach ($queued as [$verb, $id]) {
            $served++;
            $remaining--;
            if ($served === (int) ($options['disconnect-at'] ?? 0)) break 2;
            if ($served === (int) ($options['fail-at'] ?? 0)) {
                fwrite($socket, "503 Synthetic transport error\r\n");
                break 2;
            }
            if ($served === (int) ($options['missing-at'] ?? 0)) {
                fwrite($socket, "430 Missing article\r\n");
                continue;
            }
            $body = $verb === 'HEAD' ? Fixtures::headers($id) : Fixtures::comment('<spot-one@example.invalid>');
            pipelineMultiline($socket, ($verb === 'HEAD' ? '221' : '220') . " 0 <$id> follows", $body);
        }
        $queued = [];
    } else {
        fwrite($socket, "500 Unexpected fixture command\r\n");
    }
}
fclose($socket);
fclose($server);
echo json_encode(['windows' => $windows, 'served' => $served, 'trace' => $trace], JSON_THROW_ON_ERROR) . PHP_EOL;
