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
        private readonly AppStateRepository $state,
        private readonly ?\Closure $fetchSpotterLists = null
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
        if ($settings['pipeline_depth'] < 1 || $settings['pipeline_depth'] > 128) {
            throw new \InvalidArgumentException('Pipeline-diepte moet tussen 1 en 128 liggen.');
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
            $moderation = new \NnSpotty\Moderation\SpotterLists($this->pdo);
            $moderationSettings = $moderation->settings();
            $spotterKeys = [];
            $moderationWarning = '';
            if ($moderationSettings['enabled'] && !$commentsOnly) {
                try {
                    if ($moderation->sync(fetch: $this->fetchSpotterLists)) $log('Spotlist whitelist en blacklist bijgewerkt.');
                } catch (\RuntimeException $exception) {
                    if ($exception instanceof \PDOException) throw $exception;
                    if ($moderation->settings()['updated_at'] === null) {
                        throw new \RuntimeException('Spotterlijsten ontbreken; synchroniseer ze voor de eerste retrieve. '
                            . $exception->getMessage(), 0, $exception);
                    }
                    $moderationWarning = ' Spotlist-update mislukt; laatst opgeslagen lijsten gebruikt: ' . $exception->getMessage();
                    $log('WAARSCHUWING:' . $moderationWarning);
                }
                $spotterKeys = $moderation->entries();
                if ($moderation->settings()['last_warning'] !== null) {
                    $log('WAARSCHUWING Spotlist: ' . $moderation->settings()['last_warning']);
                }
            }
            $blacklisted = 0;
            $client = NntpClientFactory::connectFromSettings($settings);
            $connection = $client->getConnection();
            if (!$connection instanceof PipelineConnection) throw new \LogicException('Retrieve vereist een pipelineverbinding.');
            if ($verbose) $log('NNTP pipeline-diepte: ' . $settings['pipeline_depth'] . ' (1 = uitgeschakeld).');
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
            $commentSettings = $this->state->commentRetrievalSettings();
            $lazyComments = $commentSettings['mode'] === 'lazy';
            $commentRetentionCutoff = $commentSettings['retention'] > 0
                ? $now->modify('-' . $commentSettings['retention'] . ' days')
                : null;
            if ($verbose && in_array('comments', $groups, true)) {
                $log('Reacties: ' . ($lazyComments ? 'alleen headers indexeren, tekst op aanvraag' : 'volledig vooraf ophalen')
                    . '; retentie=' . ($commentSettings['retention'] > 0 ? $commentSettings['retention'] . ' dagen' : 'gelijk aan spots') . '.');
            }
            foreach ($groups as $groupName => $role) {
                $groupCutoff = $cutoff;
                if ($role === 'comments' && $commentRetentionCutoff !== null && ($groupCutoff === null || $commentRetentionCutoff > $groupCutoff)) {
                    $groupCutoff = $commentRetentionCutoff;
                }
                $group = $client->group($groupName);
                $first = (int) $group['first'];
                $last = (int) $group['last'];
                $cursorKey = 'spotnet:' . $role . ':' . $groupName;
                $cursor = $mode === 'since-last' ? $this->cursor($cursorKey) : null;
                $start = $cursor !== null ? max($first, $cursor + 1) : $first;
                $end = $mode === 'since-last'
                    ? min($last, $start + $settings['max_articles_per_group'] - 1)
                    : $last;
                $newestFirst = in_array($mode, ['days', 'all'], true);
                $oldBatches = 0;
                if ($verbose) {
                    $log($role . ' ' . $groupName . ': ' . $start . '-' . $end
                        . ($newestFirst ? ' (hoogste artikelnummers eerst)' : ' (laagste artikelnummers eerst)')
                        . '; datumgrens=' . ($groupCutoff?->format('Y-m-d H:i:s') ?? 'geen') . ' UTC');
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
                    $counts = ['te_oud' => 0, 'bestaand' => 0, 'geen_spot' => 0, 'ongeldig' => 0, 'verdwenen' => 0, 'opgeslagen' => 0, 'vernieuwd' => 0, 'blacklist' => 0, 'onbevestigd' => 0, 'tekst_later' => 0];
                    $oldestDate = null;
                    $newestDate = null;
                    $candidates = [];
                    $pending = [];
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
                            if ($groupCutoff !== null && $postedAt < $groupCutoff) {
                                $counts['te_oud']++;
                                continue;
                            }
                            $candidates[hash('sha256', $messageId)] = [
                                'message_id' => $messageId, 'number' => $number, 'date' => $date,
                                'references' => (string) ($overview['references'] ?? ''), 'from' => (string) ($overview['from'] ?? ''),
                            ];
                        } catch (UnexpectedValueException $exception) {
                            $skipped++;
                            $counts['ongeldig']++;
                            $log($groupName . ' artikel ' . $number . ' overgeslagen: ' . $exception->getMessage());
                        }
                    }
                    // One lookup per batch instead of one query per header.
                    $known = $repository->existing(array_keys($candidates), $role);
                    $commentRows = [];
                    foreach ($candidates as $id => $candidate) {
                        $status = $known[$id] ?? null;
                        if ($role === 'spots' ? ($status !== null && !$refreshSpots) : ($status !== null && ($status !== 'pending' || $lazyComments))) {
                            $counts['bestaand']++;
                            continue;
                        }
                        if ($role === 'comments' && $lazyComments) {
                            try {
                                $stub = SpotnetParser::commentStub($candidate['message_id'], $candidate['references'], $candidate['from'], $candidate['date']);
                            } catch (UnexpectedValueException $exception) {
                                $skipped++;
                                $counts['ongeldig']++;
                                $log($groupName . ' artikel ' . $candidate['number'] . ' overgeslagen: ' . $exception->getMessage());
                                continue;
                            }
                            // Without a derivable spot reference the article itself must be read now.
                            if ($stub !== null) {
                                $commentRows[] = $stub;
                                $inserted++;
                                $counts['opgeslagen']++;
                                $counts['tekst_later']++;
                                continue;
                            }
                        }
                        $pending[] = ['message_id' => $candidate['message_id'], 'number' => $candidate['number'], 'exists' => $role === 'spots' && $status !== null];
                    }
                    $this->pdo->beginTransaction();
                    try {
                        foreach ($connection->articles(array_column($pending, 'message_id'), $role === 'spots', $settings['pipeline_depth']) as $index => $article) {
                            $messageId = $pending[$index]['message_id'];
                            $number = $pending[$index]['number'];
                            $exists = $pending[$index]['exists'];
                            try {
                                if ($article instanceof NntpException) throw $article;
                                if ($role === 'spots') {
                                    $spot = SpotnetParser::spot($messageId, $article);
                                    if ($spot === null) {
                                        $skipped++;
                                        $counts['geen_spot']++;
                                        continue;
                                    }
                                    if ($spot['signature_state'] !== 'verified') $counts['onbevestigd']++;
                                    if ($spot['spotter_key'] !== null && ($spotterKeys[$spot['spotter_key']] ?? null) === 'blacklist') {
                                        $skipped++;
                                        $blacklisted++;
                                        $counts['blacklist']++;
                                        continue;
                                    }
                                    $repository->saveSpot($spot, $groupName, $refreshSpots);
                                } else {
                                    $commentRows[] = SpotnetParser::comment($messageId, $article);
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
                        $repository->saveComments($commentRows);
                        // Backfills do not move the incremental cursor past unprocessed articles.
                        if ($mode === 'since-last') {
                            $this->saveCursor($cursorKey, $to);
                        }
                        $this->pdo->commit();
                    } catch (Throwable $exception) {
                        // A transport failure keeps already processed articles (without moving the cursor); database errors roll back.
                        if ($exception instanceof \PDOException) {
                            $this->pdo->rollBack();
                            throw $exception;
                        }
                        try {
                            $repository->saveComments($commentRows);
                            $this->pdo->commit();
                        } catch (Throwable) {
                            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                        }
                        throw $exception;
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
                    // One old article is not a reliable boundary: require three entirely old, nonempty batches.
                    $oldBatches = $newestFirst && $groupCutoff !== null && $rows !== [] && $counts['te_oud'] === count($rows)
                        ? $oldBatches + 1 : 0;
                    if ($oldBatches === 3) {
                        $log($groupName . ': datumgrens ' . $groupCutoff->format('Y-m-d H:i:s')
                            . ' UTC bereikt; gestopt na 3 opeenvolgende volledig oude batches. Oudere artikelranges worden niet gescand.');
                        break;
                    }
                }
                if ($mode !== 'since-last' && ($this->cursor($cursorKey) ?? 0) < $last) {
                    $this->saveCursor($cursorKey, $last);
                }
                $processedGroups++;
            }
            $expiredDeleted = 0;
            if ($retentionCutoff !== null) {
                do {
                    $deleted = $repository->deleteExpiredSpots($retentionCutoff->format('Y-m-d H:i:s'));
                    $expiredDeleted += $deleted;
                } while ($deleted > 0);
            }
            $expiredComments = 0;
            if ($commentRetentionCutoff !== null) {
                do {
                    $deleted = $repository->deleteExpiredComments($commentRetentionCutoff->format('Y-m-d H:i:s'));
                    $expiredComments += $deleted;
                } while ($deleted > 0);
            }
            $message = 'Spotnet retrieve voltooid: ' . $inserted . ' nieuwe spots/reacties opgeslagen; '
                . $refreshed . ' spots vernieuwd; ' . $skipped . ' berichten overgeslagen; '
                . $blacklisted . ' wegens blacklist.' . $moderationWarning;
            if ($expiredDeleted > 0) {
                $message .= ' ' . $expiredDeleted . ' spots ouder dan de retentie verwijderd.';
            }
            if ($expiredComments > 0) {
                $message .= ' ' . $expiredComments . ' reacties ouder dan de reactie-retentie verwijderd.';
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
