<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use DateTimeImmutable;
use DateTimeZone;
use NnSpotty\State\AppStateRepository;
use PDO;
use Rvdv\Nntp\Exception\RuntimeException as NntpException;
use Throwable;
use UnexpectedValueException;

final class RetrieveService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AppStateRepository $state
    ) {
    }

    /** @return array{mode: string, days: ?int, status: string, message: string, inserted: int, groups: int} */
    public function run(string $mode, ?int $days, bool $verbose = false, ?callable $logger = null): array
    {
        if (!in_array($mode, ['all', 'days', 'since-last'], true) || ($mode === 'days' && ($days === null || $days < 1))) {
            throw new \InvalidArgumentException('Gebruik --all, --since-last of --days=<positief aantal>.');
        }
        $log = $logger ?? static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
        };
        $settings = $this->state->getUsenetSettings();
        $groups = SpotnetParser::groups($settings['explicit_groups']);
        if ($settings['max_articles_per_group'] < 100) {
            throw new \InvalidArgumentException('Max artikelen per groep moet minimaal 100 zijn.');
        }
        $runId = $this->startRun($mode, $days);
        $client = null;
        $inserted = 0;
        $processedGroups = 0;
        $skipped = 0;
        try {
            $client = NntpClientFactory::connectFromSettings($settings);
            $format = $client->overviewFormat();
            $effectiveDays = $mode === 'all' ? null : ($days ?? ($settings['retention'] > 0 ? $settings['retention'] : null));
            $cutoff = $effectiveDays !== null
                ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $effectiveDays . ' days')
                : null;
            $repository = new SpotRepository($this->pdo);
            foreach ($groups as $groupName => $role) {
                $group = $client->group($groupName);
                $first = (int) $group['first'];
                $last = (int) $group['last'];
                $cursorKey = 'spotnet:' . $role . ':' . $groupName;
                $cursor = $mode === 'since-last' ? $this->cursor($cursorKey) : null;
                // A days window must scan the group: article numbers do not encode dates.
                $start = $cursor !== null ? max($first, $cursor + 1) : $first;
                $end = $mode === 'since-last'
                    ? min($last, $start + $settings['max_articles_per_group'] - 1)
                    : $last;
                if ($verbose) {
                    $log($role . ' ' . $groupName . ': ' . $start . '-' . $end);
                }
                for ($from = $start; $from <= $end; $from += 250) {
                    $to = min($end, $from + 249);
                    $rows = $client->sendCommand(new SpotnetOverviewCommand($from, $to, $format));
                    if (!is_array($rows)) {
                        throw new \RuntimeException('NNTP XOVER retourneerde geen overzicht.');
                    }
                    foreach ($rows as $row) {
                        $overview = array_change_key_case($row, CASE_LOWER);
                        $number = (int) ($overview['number'] ?? 0);
                        if ($number < $from || $number > $to) {
                            throw new UnexpectedValueException('XOVER retourneert een ongeldig artikelnummer.');
                        }
                        try {
                            $messageId = SpotnetParser::messageId((string) ($overview['message-id'] ?? ''));
                            if ($cutoff !== null) {
                                $date = trim((string) ($overview['date'] ?? ''));
                                if ($date === '') {
                                    throw new UnexpectedValueException('XOVER bericht mist een datum.');
                                }
                                try {
                                    $postedAt = new DateTimeImmutable($date);
                                } catch (\Exception $exception) {
                                    throw new UnexpectedValueException('Ongeldige XOVER datum.', 0, $exception);
                                }
                                if ($postedAt < $cutoff) {
                                    continue;
                                }
                            }
                            if ($repository->exists(hash('sha256', $messageId), $role)) {
                                continue;
                            }
                            if ($role === 'spots') {
                                $spot = SpotnetParser::spot($messageId, $client->head('<' . $messageId . '>'));
                                if ($spot === null) {
                                    $skipped++;
                                    continue;
                                }
                                $repository->saveSpot($spot, $groupName);
                            } else {
                                $article = $client->sendCommand(new \Rvdv\Nntp\Command\ArticleCommand('<' . $messageId . '>'));
                                if (!is_string($article)) {
                                    throw new \RuntimeException('NNTP ARTICLE retourneerde geen tekst.');
                                }
                                $repository->saveComment(SpotnetParser::comment($messageId, $article));
                            }
                            $inserted++;
                        } catch (UnexpectedValueException $exception) {
                            $skipped++;
                            $log($groupName . ' artikel ' . $number . ' overgeslagen: ' . $exception->getMessage());
                        } catch (NntpException $exception) {
                            if (!in_array($exception->getCode(), [423, 430], true)) {
                                throw $exception;
                            }
                            $skipped++;
                            $log($groupName . ' artikel ' . $number . ' niet meer beschikbaar: ' . $exception->getMessage());
                        }
                    }
                    // Backfills do not move the incremental cursor past unprocessed articles.
                    if ($mode === 'since-last') {
                        $this->saveCursor($cursorKey, $to);
                    }
                    if ($verbose) {
                        $log($groupName . ' batch ' . $from . '-' . $to . ': ' . count($rows) . ' headers, totaal opgeslagen=' . $inserted);
                    }
                }
                if ($mode !== 'since-last' && ($this->cursor($cursorKey) ?? 0) < $last) {
                    $this->saveCursor($cursorKey, $last);
                }
                $processedGroups++;
            }
            $message = 'Spotnet retrieve voltooid: ' . $inserted . ' spots/reacties opgeslagen; ' . $skipped . ' berichten overgeslagen.';
            $this->finishRun($runId, 'completed', $message);
            return ['mode' => $mode, 'days' => $days, 'status' => 'completed', 'message' => $message, 'inserted' => $inserted, 'groups' => $processedGroups];
        } catch (Throwable $exception) {
            $this->finishRun($runId, 'failed', 'Spotnet retrieve mislukt: ' . $exception->getMessage());
            throw $exception;
        } finally {
            if ($client !== null) {
                $client->disconnect();
            }
        }
    }

    private function startRun(string $mode, ?int $days): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retrieval_runs (mode, days, status, message, started_at, completed_at)
             VALUES (:mode, :days, \'running\', \'Spotnet retrieve gestart.\', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute(['mode' => $mode, 'days' => $days]);
        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $id, string $status, string $message): void
    {
        $statement = $this->pdo->prepare('UPDATE retrieval_runs SET status = :status, message = :message, completed_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['id' => $id, 'status' => $status, 'message' => $message]);
    }

    private function cursor(string $group): ?int
    {
        $statement = $this->pdo->prepare('SELECT last_article FROM retrieval_cursors WHERE group_name = :group_name');
        $statement->execute(['group_name' => $group]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    private function saveCursor(string $group, int $last): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retrieval_cursors (group_name, last_article, updated_at) VALUES (:group_name, :last_article, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_article = GREATEST(last_article, VALUES(last_article)), updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute(['group_name' => $group, 'last_article' => $last]);
    }
}
