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
        $backfill = (new AppStateRepository($this->pdo))->metadataBackfillSettings();
        if ($backfill['enabled']) {
            $seeded = $repo->enqueueBackfill($claimLimit, $backfill['oldest_date']);
            if ($seeded > 0) {
                $log('Metadata-backfill: ' . $seeded . ' oudere spots aan de queue toegevoegd.');
            }
        }
        $jobs = $repo->claim($claimLimit);
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
            try {
                $result = $job['media_kind'] === 'music' ? $musicService->lookup($spot) : $videoService->lookup($spot);
                $fetchLogs->record($job['media_kind'], 'cron', null, 'cron_run', ['id' => $spot['id'], 'title' => $spot['title']], $result);
                $repo->syncFromResult($spot, $job['media_kind'], $result);
                $state = (string) ($result['state'] ?? 'error');
                if ($state === 'matched') {
                    $stats['matched']++;
                    $repo->complete($job['id'], 'done');
                    $stats['done']++;
                    continue;
                }
                if (in_array($state, ['unsupported', 'unmatched', 'ambiguous', 'blocked'], true)) {
                    $repo->complete($job['id'], 'done');
                    $stats['done']++;
                    continue;
                }
                if ($job['attempts'] >= $settings['max_retries']) {
                    $repo->complete($job['id'], 'failed', self::message($result));
                    $stats['failed']++;
                    continue;
                }
                $repo->complete($job['id'], 'failed', self::message($result), self::retryAt($job['attempts']));
                $stats['retried']++;
            } catch (Throwable $exception) {
                $fetchLogs->record($job['media_kind'], 'cron', null, 'cron_run', ['id' => $spot['id'], 'title' => $spot['title']], [
                    'state' => 'error',
                    'message' => $exception->getMessage(),
                ]);
                if ($job['attempts'] >= $settings['max_retries']) {
                    $repo->complete($job['id'], 'failed', $exception->getMessage());
                    $stats['failed']++;
                    $log('Job ' . $job['id'] . ' permanent mislukt: ' . $exception->getMessage());
                    continue;
                }
                $repo->complete($job['id'], 'failed', $exception->getMessage(), self::retryAt($job['attempts']));
                $stats['retried']++;
                $log('Job ' . $job['id'] . ' tijdelijk mislukt: ' . $exception->getMessage());
            }
        }
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

    private static function retryAt(int $attempt): string
    {
        $seconds = min(3600, (int) (60 * (2 ** max(0, $attempt - 1))));
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }
}
