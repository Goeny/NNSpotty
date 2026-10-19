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

    /** @return array{running: bool, last_run_at: ?string, last_status: ?string} */
    public function status(): array
    {
        $lock = $this->pdo->prepare('SELECT IS_USED_LOCK(:lock_name) IS NOT NULL');
        $lock->execute(['lock_name' => $this->lockName()]);
        $running = (bool) $lock->fetchColumn();
        $last = $this->pdo->query(
            "SELECT status, DATE_FORMAT(completed_at, '%Y-%m-%dT%H:%i:%sZ') AS completed_at
             FROM retrieval_runs WHERE status IN ('completed', 'failed') ORDER BY id DESC LIMIT 1"
        )->fetch();

        return [
            'running' => $running,
            'last_run_at' => is_array($last) ? $last['completed_at'] : null,
            'last_status' => is_array($last) ? $last['status'] : null,
        ];
    }

    /** @return array{mode: string, days: ?int, status: string, message: string, inserted: int, refreshed: int, groups: int} */
    public function run(string $mode, ?int $days, bool $verbose = false, ?callable $logger = null, bool $commentsOnly = false, bool $refreshSpots = false): array
    {
        if (!in_array($mode, ['all', 'days', 'since-last'], true) || ($mode === 'days' && ($days === null || $days < 1))) {
            throw new \InvalidArgumentException('Gebruik --all, --since-last of --days=<positief aantal>.');
        }
        $log = $logger ?? static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
        };
        $settings = $this->state->getUsenetSettings();
        $groups = SpotnetParser::groups($settings['explicit_groups']);
        if ($commentsOnly && $refreshSpots) {
            throw new \InvalidArgumentException('--comments-only en --refresh-spots kunnen niet samen worden gebruikt.');
        }
        if ($refreshSpots && $mode === 'since-last') {
            throw new \InvalidArgumentException('Gebruik --refresh-spots met --days=<n> of --all om bestaande spots opnieuw te lezen.');
        }
        if ($commentsOnly) {
            $groups = array_filter($groups, static fn (string $role): bool => $role === 'comments');
            if ($groups === [] || !$this->state->spotContentSettings()['retrieve_comments']) {
                throw new \InvalidArgumentException('Configureer een reactiegroep en schakel Usenet-reacties indexeren in via admin.');
            }
        } elseif ($refreshSpots) {
            $groups = array_filter($groups, static fn (string $role): bool => $role === 'spots');
        }
        if (!$this->state->spotContentSettings()['retrieve_comments']) {
            $groups = array_filter($groups, static fn (string $role): bool => $role !== 'comments');
            $log('Reacties ophalen is uitgeschakeld; reactiegroepen en hun cursors blijven ongewijzigd.');
        }
        if ($settings['max_articles_per_group'] < 100) {
            throw new \InvalidArgumentException('Max artikelen per groep moet minimaal 100 zijn.');
        }
        $lockName = $this->lockName();
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $lock->execute(['lock_name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \DomainException('Er is al een retrieve actief.');
        }

        $client = null;
        $runId = null;
        $inserted = 0;
        $refreshed = 0;
        $processedGroups = 0;
        $skipped = 0;
        try {
            $runId = $this->startRun($mode, $days);
            $client = NntpClientFactory::connectFromSettings($settings);
            $format = $client->overviewFormat();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $retentionCutoff = $settings['retention'] > 0
                ? $now->modify('-' . $settings['retention'] . ' days')
                : null;
            $cutoffs = [];
            if ($retentionCutoff !== null) {
                $cutoffs[] = $retentionCutoff;
            }
            if ($days !== null) {
                $cutoffs[] = $now->modify('-' . $days . ' days');
            }
            $configuredStartDate = $this->configuredStartDate($settings['retrieve_after']);
            if ($configuredStartDate !== null) {
                $cutoffs[] = $configuredStartDate;
            }
            $cutoff = null;
            foreach ($cutoffs as $candidate) {
                if ($cutoff === null || $candidate > $cutoff) {
                    $cutoff = $candidate;
                }
            }
            $repository = new SpotRepository($this->pdo);
            foreach ($groups as $groupName => $role) {
                $group = $client->group($groupName);
                $first = (int) $group['first'];
                $last = (int) $group['last'];
                $cursorKey = 'spotnet:' . $role . ':' . $groupName;
                $cursor = $mode === 'since-last' ? $this->cursor($cursorKey) : null;
                // Date-filtered backfills scan every range because article numbers do not encode dates.
                $start = $cursor !== null ? max($first, $cursor + 1) : $first;
                $end = $mode === 'since-last'
                    ? min($last, $start + $settings['max_articles_per_group'] - 1)
                    : $last;
                $newestFirst = in_array($mode, ['days', 'all'], true);
                if ($verbose) {
                    $log($role . ' ' . $groupName . ': ' . $start . '-' . $end
                        . ($newestFirst ? ' (hoogste artikelnummers eerst)' : ' (laagste artikelnummers eerst)')
                        . '; datumgrens=' . ($cutoff?->format('Y-m-d H:i:s') ?? 'geen') . ' UTC');
                }
                for (
                    $position = $newestFirst ? $end : $start;
                    $newestFirst ? $position >= $start : $position <= $end;
                    $position += $newestFirst ? -250 : 250
                ) {
                    $from = $newestFirst ? max($start, $position - 249) : $position;
                    $to = $newestFirst ? $position : min($end, $position + 249);
                    $rows = $client->sendCommand(new SpotnetOverviewCommand($from, $to, $format));
                    if (!is_array($rows)) {
                        throw new \RuntimeException('NNTP XOVER retourneerde geen overzicht.');
                    }
                    $counts = ['te_oud' => 0, 'bestaand' => 0, 'geen_spot' => 0, 'ongeldig' => 0, 'verdwenen' => 0, 'opgeslagen' => 0, 'vernieuwd' => 0];
                    $oldestDate = null;
                    $newestDate = null;
                    foreach ($rows as $row) {
                        $overview = array_change_key_case($row, CASE_LOWER);
                        $number = (int) ($overview['number'] ?? 0);
                        if (isset($overview['_overview_warning'])) {
                            $log($groupName . ' artikel ' . $number . ' waarschuwing: ' . $overview['_overview_warning']);
                        }
                        try {
                            if (isset($overview['_overview_error'])) {
                                throw new UnexpectedValueException($overview['_overview_error']);
                            }
                            $messageId = SpotnetParser::messageId((string) ($overview['message-id'] ?? ''));
                            $date = trim((string) ($overview['date'] ?? ''));
                            if ($date === '') {
                                throw new UnexpectedValueException('XOVER bericht mist een datum.');
                            }
                            try {
                                $postedAt = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'));
                            } catch (\Exception $exception) {
                                throw new UnexpectedValueException('Ongeldige XOVER datum.', 0, $exception);
                            }
                            $dateLabel = $postedAt->format('Y-m-d H:i:s');
                            $oldestDate = $oldestDate === null ? $dateLabel : min($oldestDate, $dateLabel);
                            $newestDate = $newestDate === null ? $dateLabel : max($newestDate, $dateLabel);
                            if ($cutoff !== null && $postedAt < $cutoff) {
                                $counts['te_oud']++;
                                continue;
                            }
                            $exists = $repository->exists(hash('sha256', $messageId), $role);
                            if (!$refreshSpots && $exists) {
                                $counts['bestaand']++;
                                continue;
                            }
                            if ($role === 'spots') {
                                $spot = SpotnetParser::spot($messageId, $client->head('<' . $messageId . '>'));
                                if ($spot === null) {
                                    $skipped++;
                                    $counts['geen_spot']++;
                                    continue;
                                }
                                $repository->saveSpot($spot, $groupName, $refreshSpots);
                            } else {
                                $article = $client->sendCommand(new \Rvdv\Nntp\Command\ArticleCommand('<' . $messageId . '>'));
                                if (!is_string($article)) {
                                    throw new \RuntimeException('NNTP ARTICLE retourneerde geen tekst.');
                                }
                                $repository->saveComment(SpotnetParser::comment($messageId, $article));
                            }
                            if ($exists) {
                                $refreshed++;
                                $counts['vernieuwd']++;
                            } else {
                                $inserted++;
                                $counts['opgeslagen']++;
                            }
                        } catch (UnexpectedValueException $exception) {
                            $skipped++;
                            $counts['ongeldig']++;
                            $log($groupName . ' artikel ' . $number . ' overgeslagen: ' . $exception->getMessage());
                        } catch (NntpException $exception) {
                            if (!in_array($exception->getCode(), [423, 430], true)) {
                                throw $exception;
                            }
                            $skipped++;
                            $counts['verdwenen']++;
                            $log($groupName . ' artikel ' . $number . ' niet meer beschikbaar: ' . $exception->getMessage());
                        }
                    }
                    // Backfills do not move the incremental cursor past unprocessed articles.
                    if ($mode === 'since-last') {
                        $this->saveCursor($cursorKey, $to);
                    }
                    if ($verbose) {
                        $statistics = [];
                        foreach ($counts as $reason => $count) {
                            $statistics[] = $reason . '=' . $count;
                        }
                        $log($groupName . ' batch ' . $from . '-' . $to . ': ' . count($rows)
                            . ' headers; datums=' . ($oldestDate ?? 'onbekend') . '..' . ($newestDate ?? 'onbekend')
                            . ' UTC; ' . implode(' ', $statistics) . '; totaal opgeslagen=' . $inserted);
                    }
                }
                if ($mode !== 'since-last' && ($this->cursor($cursorKey) ?? 0) < $last) {
                    $this->saveCursor($cursorKey, $last);
                }
                $processedGroups++;
            }
            $expiredDeleted = $retentionCutoff !== null
                ? $repository->deleteExpiredSpots($retentionCutoff->format('Y-m-d H:i:s'))
                : 0;
            $message = 'Spotnet retrieve voltooid: ' . $inserted . ' nieuwe spots/reacties opgeslagen; '
                . $refreshed . ' spots vernieuwd; ' . $skipped . ' berichten overgeslagen.';
            if ($expiredDeleted > 0) {
                $message .= ' ' . $expiredDeleted . ' spots ouder dan de retentie verwijderd.';
            }
            $this->finishRun($runId, 'completed', $message);
            return ['mode' => $mode, 'days' => $days, 'status' => 'completed', 'message' => $message, 'inserted' => $inserted, 'refreshed' => $refreshed, 'groups' => $processedGroups];
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->finishRun($runId, 'failed', 'Spotnet retrieve mislukt: ' . $exception->getMessage());
            }
            throw $exception;
        } finally {
            try {
                if ($client !== null) {
                    $client->disconnect();
                }
            } finally {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => $lockName]);
                $released = $release->fetchColumn();
                if ((int) $released !== 1) {
                    throw new \RuntimeException('De retrieve-lock kon niet worden vrijgegeven.');
                }
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

    private function configuredStartDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new UnexpectedValueException('De ingestelde retrieve-begindatum is ongeldig; sla de Usenet-instellingen opnieuw op.');
        }

        return $date;
    }

    private function lockName(): string
    {
        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new \RuntimeException('Geen database geselecteerd voor retrieve-status.');
        }

        return 'nns:' . substr(hash('sha256', $database), 0, 60);
    }
}
