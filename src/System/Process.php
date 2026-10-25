<?php

declare(strict_types=1);

namespace NnSpotty\System;

use RuntimeException;

final class Process
{
    /** @param list<string> $command
     *  @return array{code: int, output: string}
     */
    public function run(array $command, string $root, int $timeout = 120): array
    {
        if (!function_exists('proc_open')) throw new RuntimeException('proc_open is vereist voor de updater.');
        $log = tmpfile();
        if ($log === false) throw new RuntimeException('Kan proceslog niet openen.');
        try {
            $environment = array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0', 'GIT_PAGER' => 'cat', 'COMPOSER_NO_INTERACTION' => '1']);
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, $root, $environment,
                ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('Kan updateproces niet starten.');
            fclose($pipes[0]);
            $started = microtime(true);
            $failure = null;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) - $started > $timeout || fstat($log)['size'] > 10 * 1024 * 1024) {
                    $failure = 'Updateproces overschrijdt tijd- of uitvoerlimiet. Controleer de processtatus voordat je opnieuw probeert.';
                    proc_terminate($process);
                    break;
                }
                usleep(100000);
            } while (true);
            $closed = proc_close($process);
            if ($failure !== null) throw new RuntimeException($failure);
            if (fstat($log)['size'] > 10 * 1024 * 1024) throw new RuntimeException('Updateproces overschrijdt de uitvoerlimiet.');
            rewind($log);
            $output = stream_get_contents($log);
            if ($output === false) throw new RuntimeException('Kan procesuitvoer niet lezen.');
            return ['code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closed, 'output' => trim($output)];
        } finally {
            fclose($log);
        }
    }

    public function require(array $command, string $root, int $timeout = 120): string
    {
        $result = $this->run($command, $root, $timeout);
        if ($result['code'] !== 0) throw new RuntimeException('Proces mislukt (' . $command[0] . '): ' . $result['output']);
        return $result['output'];
    }
}
