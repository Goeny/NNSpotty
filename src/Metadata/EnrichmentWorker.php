<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use NnSpotty\Music\Client as MusicClient;
use NnSpotty\Music\Repository as MusicRepository;
use NnSpotty\Music\Service as MusicService;
use NnSpotty\State\AppStateRepository;
use Throwable;

final class EnrichmentWorker
{
    public function __construct(private readonly \PDO $pdo, private readonly string $publicAppUrl)
    {
    }

    /** @return array{processed: int, matched: int, done: int, retried: int, failed: int, skipped: int} */
    public function run(?int $limit = null, ?callable $logger = null): array
    {
        $operations = new Operations($this->pdo);
        if (!$operations->acquire()) {
            if ($logger !== null) $logger('Metadata-run overgeslagen: er is al een run actief.');
            return ['processed' => 0, 'matched' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 1];
        }
        $completed = false;
        try {
            $operations->begin(0);
            $result = $this->runBatch($operations, $limit, $logger);
            $completed = true;
            return $result;
        } finally {
            $operations->finish($completed);
        }
    }

    private function runBatch(Operations $operations, ?int $limit, ?callable $logger): array
    {
        $log = $logger ?? static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
        $repo = new EnrichmentRepository($this->pdo);
        $settings = $repo->settings();
        if (!$settings['enabled']) {
            $log('Metadata-cron staat uit (enabled=0).');
            return ['processed' => 0, 'matched' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        }
        $claimLimit = $limit === null ? $settings['batch_size'] : max(1, min(500, $limit));
        $policy = $operations->policy();
        $revision = hash('sha256', 'matching-v1:' . (new MatchRules($this->pdo))->revision());
        $operations->seedBulk();
        $backfill = (new AppStateRepository($this->pdo))->metadataBackfillSettings();
        if ($backfill['enabled']) {
            $seeded = $repo->enqueueBackfill($claimLimit, $backfill['oldest_date']);
            if ($seeded > 0) {
                $log('Metadata-backfill: ' . $seeded . ' oudere spots aan de queue toegevoegd.');
            }
        }
        $jobs = $repo->claim($claimLimit, $revision);
        if ($jobs === []) {
            $log('Geen metadata-jobs klaar voor verwerking.');
            return ['processed' => 0, 'matched' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $videoRepo = new Repository($this->pdo);
        $videoService = new Service($videoRepo, new Client($videoRepo), new TmdbClient($videoRepo));
        $musicRepo = new MusicRepository($this->pdo);
        $musicService = new MusicService($musicRepo, new MusicClient($musicRepo, $this->publicAppUrl));
        $fetchLogs = new FetchLogRepository($this->pdo);
        $stats = ['processed' => 0, 'matched' => 0, 'done' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($jobs as $job) {
            $stats['processed']++;
            $spot = $job['spot'];
            $operations->progress($stats['processed'] - 1, count($jobs), $spot['title']);
            try {
                $result = $job['media_kind'] === 'music' ? $musicService->lookup($spot, true) : $videoService->lookup($spot, true);
                $fetchLogs->record($job['media_kind'], 'cron', null, 'cron_run', ['id' => $spot['id'], 'title' => $spot['title']], $result);
                $repo->syncFromResult($spot, $job['media_kind'], $result);
                $state = (string) ($result['state'] ?? 'error');
                if ($state === 'matched') {
                    $stats['matched']++;
                    $repo->recordOutcome($job['id'], $state, $revision, 0);
                    $stats['done']++;
                    continue;
                }
                if (in_array($state, ['unsupported', 'unmatched', 'ambiguous', 'blocked'], true)) {
                    $repo->recordOutcome($job['id'], $state, $revision, $policy[$state . '_days'] ?? 0);
                    $stats['done']++;
                    continue;
                }
                $repo->deferError($job['id'], $job['attempts'], $settings['max_retries'], $policy['error_days'], self::message($result), $revision);
                $stats['retried']++;
            } catch (Throwable $exception) {
                $fetchLogs->record($job['media_kind'], 'cron', null, 'cron_run', ['id' => $spot['id'], 'title' => $spot['title']], [
                    'state' => 'error',
                    'message' => $exception->getMessage(),
                ]);
                $repo->deferError($job['id'], $job['attempts'], $settings['max_retries'], $policy['error_days'], $exception->getMessage(), $revision);
                $stats['retried']++;
                $log('Job ' . $job['id'] . ' tijdelijk mislukt: ' . $exception->getMessage());
            }
        }
        $operations->progress($stats['processed'], count($jobs), '');
        return $stats;
    }

    /** @param array<string, mixed> $result */
    private static function message(array $result): string
    {
        $message = (string) ($result['message'] ?? '');
        if ($message !== '') {
            return $message;
        }
        $state = (string) ($result['state'] ?? 'error');
        return 'Metadatastatus: ' . $state;
    }

}
