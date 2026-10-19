<?php

declare(strict_types=1);

use NnSpotty\Usenet\SpotnetParser;

$boundarySettings = $state->getUsenetSettings();
try {
    foreach (['old' => 4, 'recent' => 7, 'mixed' => 7, 'empty' => 7, 'invalid' => 7, 'invalid-date' => 7, 'boundary' => 7] as $scenario => $expectedBatches) {
        $boundaryPipes = [];
        $boundaryServer = proc_open([PHP_BINARY, __DIR__ . '/nntp-server.php', '--date-boundary=' . $scenario],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $boundaryPipes);
        if (!is_resource($boundaryServer)) throw new RuntimeException('Kon datumgrens-NNTP-fixture niet starten.');
        try {
            $boundaryConfig = $boundarySettings;
            $boundaryConfig['port'] = (int) trim(fgets($boundaryPipes[1]));
            $boundaryConfig['explicit_groups'] = 'spots:date.spots,comments:date.comments';
            $boundaryConfig['retention'] = 0;
            $boundaryConfig['retrieve_after'] = gmdate('Y-m-d');
            $state->saveUsenetSettings($boundaryConfig);
            foreach ([
                'all' => ['all', null, false, false],
                'days' => ['days', 30, false, false],
                'comments-only' => ['days', 30, true, false],
                'refresh-spots' => ['days', 30, false, true],
            ] as $boundaryLabel => [$boundaryMode, $boundaryDays, $onlyComments, $refresh]) {
                $log = [];
                $service->run($boundaryMode, $boundaryDays, true, $logger, commentsOnly: $onlyComments, refreshSpots: $refresh);
                $boundaryGroups = $onlyComments ? ['date.comments'] : ($refresh ? ['date.spots'] : ['date.spots', 'date.comments']);
                foreach ($boundaryGroups as $boundaryGroup) {
                    $batches = array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, $boundaryGroup . ' batch ')));
                    check(count($batches) === $expectedBatches
                        && str_contains($batches[0], '1751-2000')
                        && str_contains($batches[$expectedBatches - 1], $expectedBatches === 4 ? '1001-1250' : '251-500'),
                        $boundaryLabel . ' ' . $scenario . ' ' . $boundaryGroup . ': exact drie volledig oude batches stoppen verdere XOVER-ranges');
                    check(count(array_filter($log, static fn (string $line): bool => str_starts_with($line, $boundaryGroup . ': datumgrens '))) === 1,
                        $boundaryLabel . ' ' . $scenario . ': datumgrensstop wordt expliciet gemeld');
                    check((int) $pdo->query("SELECT last_article FROM retrieval_cursors WHERE group_name = 'spotnet:"
                        . ($boundaryGroup === 'date.spots' ? 'spots' : 'comments') . ':' . $boundaryGroup . "'")->fetchColumn() === 2000,
                        'Geslaagde datumgrensstop bewaart groepseinde als incrementele cursor');
                }
            }
            if ($scenario === 'old') {
                $log = [];
                $service->run('since-last', null, true, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_contains($line, ' batch '))) === 0,
                    'Incrementele run na datumgrensstop scant oude historie niet opnieuw');
                $boundaryConfig['retrieve_after'] = '';
                $state->saveUsenetSettings($boundaryConfig);
                $log = [];
                $service->run('all', null, true, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'date.spots batch '))) === 8
                    && count(array_filter($log, static fn (string $line): bool => str_contains($line, ': datumgrens '))) === 0,
                    'Zonder datumgrens wordt de volledige historie gescand');
                $log = [];
                $service->run('days', 30, true, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'date.spots batch '))) === 4,
                    'Ook alleen --days begrenst de scan op drie oude batches');
                $boundaryConfig['retention'] = 30;
                $state->saveUsenetSettings($boundaryConfig);
                $log = [];
                $service->run('all', null, true, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'date.spots batch '))) === 4,
                    'Ook alleen retentie begrenst de scan op drie oude batches');
                $expiredBoundarySpot = SpotnetParser::spot('boundary-expired@example.invalid', Fixtures::headers('boundary-expired@example.invalid'));
                $expiredBoundarySpot['posted_at'] = '2009-01-01 12:00:00';
                $spots->saveSpot($expiredBoundarySpot, 'date.spots');
                $log = [];
                $boundaryRun = $service->run('all', null, false, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_contains($line, ': datumgrens '))) === 2
                    && count(array_filter($log, static fn (string $line): bool => str_contains($line, ' batch '))) === 0,
                    'Datumgrensstop wordt ook zonder verbose voor iedere groep gemeld');
                check($boundaryRun['status'] === 'completed' && $spots->getSpot($expiredBoundarySpot['id']) === null
                    && str_contains($boundaryRun['message'], 'spots ouder dan de retentie verwijderd'),
                    'Vroegtijdige datumgrensstop voltooit normale retentiecleanup en runstatus');
                $boundaryConfig['max_articles_per_group'] = 1000;
                $state->saveUsenetSettings($boundaryConfig);
                $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name IN ('spotnet:spots:date.spots', 'spotnet:comments:date.comments')");
                $log = [];
                $service->run('since-last', null, true, $logger);
                check(count(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'date.spots batch '))) === 4
                    && count(array_filter($log, static fn (string $line): bool => str_contains($line, ': datumgrens '))) === 0,
                    'Incrementele oplopende scan stopt niet na drie oude batches');
            }
        } finally {
            proc_terminate($boundaryServer);
            foreach ($boundaryPipes as $boundaryPipe) fclose($boundaryPipe);
            proc_close($boundaryServer);
        }
    }
} finally {
    $state->saveUsenetSettings($boundarySettings);
    $pdo->exec("DELETE FROM retrieval_cursors WHERE group_name IN ('spotnet:spots:date.spots', 'spotnet:comments:date.comments')");
    $pdo->exec("DELETE FROM spotnet_comments WHERE message_id LIKE 'boundary-%@example.invalid'");
    $pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'boundary-%@example.invalid'");
}
