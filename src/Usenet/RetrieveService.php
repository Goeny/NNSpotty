<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use DateTimeImmutable;
use DateTimeZone;
use NnSpotty\State\AppStateRepository;
use PDO;
use Throwable;

final class RetrieveService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AppStateRepository $state
    )
    {
    }

    /**
     * @return array{mode: string, days: int|null, status: string, message: string, inserted: int, groups: int}
     */
    public function run(string $mode, ?int $days, bool $verbose = false, ?callable $logger = null): array
    {
        $log = static function (string $_message): void {
        };
        if ($verbose) {
            $log = is_callable($logger) ? $logger : static function (string $message): void {
                fwrite(STDOUT, '[verbose] ' . $message . PHP_EOL);
            };
        }

        if (!in_array($mode, ['all', 'days', 'since-last'], true)) {
            throw new \InvalidArgumentException('Ongeldige retrieve mode.');
        }

        if ($mode === 'days' && ($days === null || $days < 1)) {
            throw new \InvalidArgumentException('Bij mode days moet --days minimaal 1 zijn.');
        }

        $runId = $this->startRun($mode, $days);
        $totalInserted = 0;
        $processedGroups = 0;

        $settings = $this->state->getUsenetSettings();
        if (trim($settings['server']) === '') {
            throw new \RuntimeException('Usenet settings ontbreken. Stel deze eerst in via het admin panel.');
        }
        $log('Retrieve start: mode=' . $mode . ' days=' . ($days !== null ? (string) $days : 'null'));
        $log('Server=' . $settings['server'] . ':' . (string) $settings['port'] . ' encryption=' . $settings['encryption']);

        $client = NntpClientFactory::connectFromSettings($settings);
        $groups = $this->normalizeGroups($this->resolveGroups($client, $settings));
        if ($groups === []) {
            throw new \RuntimeException('Geen bruikbare newsgroups gevonden voor retrieve.');
        }
        $log('Groepen resolved: ' . (string) count($groups));

        $overviewFormat = $client->overviewFormat();
        $retentionDays = max(0, (int) ($settings['retention'] ?? 0));
        $effectiveDays = $days;
        if ($effectiveDays === null && $retentionDays > 0) {
            $effectiveDays = $retentionDays;
        }
        $daysCutoff = $effectiveDays !== null ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . $effectiveDays . ' days') : null;
        $maxArticlesPerGroup = max(100, (int) ($settings['max_articles_per_group'] ?? 5000));
        $log('Effective days=' . ($effectiveDays !== null ? (string) $effectiveDays : 'none') . ' maxArticlesPerGroup=' . (string) $maxArticlesPerGroup);

        $failedGroups = 0;
        try {
            foreach ($groups as $groupNameKey => $categoryValue) {
                if (is_string($groupNameKey) && $groupNameKey !== '' && is_string($categoryValue)) {
                    $groupName = $groupNameKey;
                    $category = $categoryValue !== '' ? $categoryValue : $this->deriveCategoryFromGroup($groupName);
                } elseif (is_int($groupNameKey) && is_string($categoryValue) && $categoryValue !== '') {
                    $groupName = $categoryValue;
                    $category = $this->deriveCategoryFromGroup($groupName);
                } else {
                    continue;
                }

                $log('Groep start: ' . $groupName . ' (category=' . $category . ')');
                try {
                    $groupInserted = $this->retrieveGroup(
                        groupName: $groupName,
                        category: $category,
                        mode: $mode,
                        daysCutoff: $daysCutoff,
                        maxArticlesPerGroup: $maxArticlesPerGroup,
                        overviewFormat: $overviewFormat,
                        client: $client,
                        logger: $log
                    );
                    $processedGroups++;
                    $totalInserted += $groupInserted;
                    $log('Groep klaar: ' . $groupName . ' inserted=' . (string) $groupInserted);
                } catch (Throwable $groupThrowable) {
                    $failedGroups++;
                    $log('Groep fout: ' . $groupName . ' reden=' . $groupThrowable->getMessage());
                    continue;
                }
            }

            $message = 'Retrieve voltooid: ' . $totalInserted . ' nieuwe posts uit ' . $processedGroups . ' groepen';
            if ($failedGroups > 0) {
                $message .= ' (' . $failedGroups . ' groepen overgeslagen door errors)';
            }
            $message .= '.';
            $this->finishRun($runId, 'completed', $message);
            $log($message);

            return [
                'mode' => $mode,
                'days' => $days,
                'status' => 'completed',
                'message' => $message,
                'inserted' => $totalInserted,
                'groups' => $processedGroups,
            ];
        } catch (Throwable $throwable) {
            $message = 'Retrieve mislukt: ' . $throwable->getMessage();
            $this->finishRun($runId, 'failed', $message);
            $log($message);
            throw $throwable;
        } finally {
            $client->disconnect();
            $log('NNTP connectie gesloten.');
        }
    }

    /**
     * @param array<string, bool> $overviewFormat
     * @param callable(string): void $logger
     */
    private function retrieveGroup(
        string $groupName,
        string $category,
        string $mode,
        ?DateTimeImmutable $daysCutoff,
        int $maxArticlesPerGroup,
        array $overviewFormat,
        \Rvdv\Nntp\Client $client,
        callable $logger
    ): int {
        $group = $client->group($groupName);
        $first = (int) $group['first'];
        $last = (int) $group['last'];
        $logger('Group info: first=' . (string) $first . ' last=' . (string) $last);

        if ($last < $first) {
            $logger('Group overgeslagen: last < first');
            return 0;
        }

        $cursor = $this->cursorForGroup($groupName);
        $start = $first;

        if ($mode === 'since-last' && $cursor !== null) {
            $start = max($first, $cursor + 1);
        } elseif ($mode === 'days') {
            $start = max($first, $last - $maxArticlesPerGroup + 1);
        }
        $logger('Range: start=' . (string) $start . ' end=' . (string) $last . ' cursor=' . ($cursor !== null ? (string) $cursor : 'none'));

        if ($start > $last) {
            $logger('Group overgeslagen: start > last');
            return 0;
        }

        $inserted = 0;
        $batchSize = 500;
        $current = $start;

        while ($current <= $last) {
            $batchTo = min($last, $current + $batchSize - 1);
            $articles = $this->safeXover($client, $current, $batchTo, $overviewFormat);
            $batchInserted = 0;
            foreach ($articles as $articleNumber => $article) {
                if (!is_array($article)) {
                    continue;
                }

                $postedAt = $this->parsePostedAt($this->overviewValue($article, ['date']));
                if ($mode === 'days' && $daysCutoff !== null && $postedAt !== null && $postedAt < $daysCutoff) {
                    continue;
                }

                if ($this->insertPost($groupName, $category, (int) $articleNumber, $article, $postedAt)) {
                    $inserted++;
                    $batchInserted++;
                }
            }
            $logger('Batch ' . (string) $current . '-' . (string) $batchTo . ': rows=' . (string) count($articles) . ' inserted=' . (string) $batchInserted);
            $current = $batchTo + 1;
        }

        $this->saveCursor($groupName, $last);
        $logger('Cursor opgeslagen: ' . $groupName . ' -> ' . (string) $last);

        return $inserted;
    }

    /**
     * @param array<string, mixed> $article
     */
    private function insertPost(string $groupName, string $category, int $articleNumber, array $article, ?DateTimeImmutable $postedAt): bool
    {
        $messageId = trim($this->overviewValue($article, ['message-id', 'message_id', 'messageid', ':message-id', 'message-id:']));
        if ($messageId === '') {
            return false;
        }
        $subject = $this->overviewValue($article, ['subject']);
        $release = SpotSubjectParser::parse($subject, $groupName);

        $rawOverview = json_encode($article, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($rawOverview === false) {
            throw new \RuntimeException('Kon raw overview niet serialiseren voor artikel ' . $messageId);
        }

        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO usenet_posts (
                group_name, category, article_number, message_id, subject, release_key, release_title, from_header, posted_at, references_header, bytes, lines_count, raw_overview, created_at
            ) VALUES (
                :group_name, :category, :article_number, :message_id, :subject, :release_key, :release_title, :from_header, :posted_at, :references_header, :bytes, :lines_count, :raw_overview, UTC_TIMESTAMP()
            )'
        );
        $bytes = $this->toNullableInt($this->overviewValue($article, ['bytes']));
        $fromHeader = $this->overviewValue($article, ['from']);
        $statement->execute([
            'group_name' => $groupName,
            'category' => $category,
            'article_number' => $articleNumber,
            'message_id' => $messageId,
            'subject' => $subject,
            'release_key' => $release['release_key'],
            'release_title' => $release['release_title'],
            'from_header' => $fromHeader,
            'posted_at' => $postedAt?->format('Y-m-d H:i:s'),
            'references_header' => $this->overviewValue($article, ['references']),
            'bytes' => $bytes,
            'lines_count' => $this->toNullableInt($this->overviewValue($article, ['lines'])),
            'raw_overview' => $rawOverview,
        ]);

        if ($statement->rowCount() <= 0) {
            return false;
        }

        $this->indexRelease(
            releaseKey: $release['release_key'],
            releaseTitle: $release['release_title'],
            category: $category,
            groupName: $groupName,
            poster: $fromHeader,
            bytes: $bytes,
            postedAt: $postedAt,
            messageId: $messageId
        );

        return true;
    }

    private function indexRelease(
        string $releaseKey,
        string $releaseTitle,
        string $category,
        string $groupName,
        string $poster,
        ?int $bytes,
        ?DateTimeImmutable $postedAt,
        string $messageId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO spot_releases (
                release_key, release_title, category, group_name, poster,
                total_parts, total_bytes, first_posted_at, last_posted_at,
                last_message_id, created_at, updated_at
            ) VALUES (
                :release_key, :release_title, :category, :group_name, :poster,
                1, :total_bytes, :first_posted_at, :last_posted_at,
                :last_message_id, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                release_title = CASE
                    WHEN CHAR_LENGTH(VALUES(release_title)) > CHAR_LENGTH(release_title) THEN VALUES(release_title)
                    ELSE release_title
                END,
                category = VALUES(category),
                group_name = VALUES(group_name),
                poster = VALUES(poster),
                total_parts = total_parts + 1,
                total_bytes = total_bytes + VALUES(total_bytes),
                first_posted_at = CASE
                    WHEN first_posted_at IS NULL THEN VALUES(first_posted_at)
                    WHEN VALUES(first_posted_at) IS NULL THEN first_posted_at
                    ELSE LEAST(first_posted_at, VALUES(first_posted_at))
                END,
                last_posted_at = CASE
                    WHEN last_posted_at IS NULL THEN VALUES(last_posted_at)
                    WHEN VALUES(last_posted_at) IS NULL THEN last_posted_at
                    ELSE GREATEST(last_posted_at, VALUES(last_posted_at))
                END,
                last_message_id = VALUES(last_message_id),
                updated_at = UTC_TIMESTAMP()'
        );
        $postedAtValue = $postedAt?->format('Y-m-d H:i:s');
        $statement->execute([
            'release_key' => $releaseKey,
            'release_title' => $releaseTitle,
            'category' => $category,
            'group_name' => $groupName,
            'poster' => $poster,
            'total_bytes' => max(0, (int) ($bytes ?? 0)),
            'first_posted_at' => $postedAtValue,
            'last_posted_at' => $postedAtValue,
            'last_message_id' => $messageId,
        ]);
    }

    private function startRun(string $mode, ?int $days): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retrieval_runs (mode, days, status, message, started_at, completed_at)
             VALUES (:mode, :days, :status, :message, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'mode' => $mode,
            'days' => $days,
            'status' => 'running',
            'message' => 'Retrieve gestart.',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $runId, string $status, string $message): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE retrieval_runs
             SET status = :status, message = :message, completed_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $runId,
            'status' => $status,
            'message' => $message,
        ]);
    }

    private function cursorForGroup(string $groupName): ?int
    {
        $statement = $this->pdo->prepare('SELECT last_article FROM retrieval_cursors WHERE group_name = :group_name LIMIT 1');
        $statement->execute(['group_name' => $groupName]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return (int) $row['last_article'];
    }

    private function saveCursor(string $groupName, int $lastArticle): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retrieval_cursors (group_name, last_article, updated_at)
             VALUES (:group_name, :last_article, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                last_article = VALUES(last_article),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'group_name' => $groupName,
            'last_article' => $lastArticle,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function parseGroups(string $raw): array
    {
        $entries = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
        $groups = [];

        foreach ($entries as $entry) {
            if (str_contains($entry, ':')) {
                [$category, $groupName] = array_map('trim', explode(':', $entry, 2));
            } else {
                $category = 'general';
                $groupName = $entry;
            }

            if ($groupName === '') {
                continue;
            }

            $groups[$groupName] = $category !== '' ? $category : 'general';
        }

        return $groups;
    }

    /**
     * @param array{explicit_groups: string, group_pattern: string, discover_max_groups: int} $settings
     * @return array<string, string>
     */
    private function resolveGroups(\Rvdv\Nntp\Client $client, array $settings): array
    {
        $configured = $this->parseGroups((string) ($settings['explicit_groups'] ?? ''));
        if ($configured !== []) {
            return $configured;
        }

        $pattern = trim((string) ($settings['group_pattern'] ?? ''));
        $maxGroups = max(0, (int) ($settings['discover_max_groups'] ?? 0));
        $allGroups = $client->listGroups();
        $resolved = [];

        foreach ($allGroups as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                continue;
            }

            $groupName = trim($entry['name']);
            if ($groupName === '') {
                continue;
            }

            if ($pattern !== '' && !fnmatch($pattern, $groupName)) {
                continue;
            }

            $resolved[$groupName] = $this->deriveCategoryFromGroup($groupName);

            if ($maxGroups > 0 && count($resolved) >= $maxGroups) {
                break;
            }
        }

        return $resolved;
    }

    private function deriveCategoryFromGroup(string $groupName): string
    {
        $parts = array_values(array_filter(explode('.', $groupName), static fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            return 'general';
        }

        if (count($parts) >= 2) {
            return strtolower($parts[0] . '.' . $parts[1]);
        }

        return strtolower($parts[0]);
    }

    private function parsePostedAt(mixed $dateValue): ?DateTimeImmutable
    {
        if (!is_string($dateValue) || trim($dateValue) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($dateValue))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $groups
     * @return array<string, string>
     */
    private function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $key => $value) {
            if (is_string($key) && is_string($value) && trim($key) !== '') {
                $groupName = trim($key);
                if (!$this->isAcceptableGroupName($groupName)) {
                    continue;
                }
                $category = trim($value) !== '' ? trim($value) : $this->deriveCategoryFromGroup($groupName);
                $normalized[$groupName] = $category;
                continue;
            }

            if (is_int($key) && is_string($value) && trim($value) !== '') {
                $groupName = trim($value);
                if (!$this->isAcceptableGroupName($groupName)) {
                    continue;
                }
                $normalized[$groupName] = $this->deriveCategoryFromGroup($groupName);
                continue;
            }

            if (is_array($value) && isset($value['name']) && is_string($value['name']) && trim($value['name']) !== '') {
                $groupName = trim($value['name']);
                if (!$this->isAcceptableGroupName($groupName)) {
                    continue;
                }
                $normalized[$groupName] = $this->deriveCategoryFromGroup($groupName);
            }
        }

        return $normalized;
    }

    private function isAcceptableGroupName(string $groupName): bool
    {
        if ($groupName === '' || preg_match('/^\d+$/', $groupName) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, bool> $overviewFormat
     * @return array<int|string, array<string, mixed>>
     */
    private function safeXover(\Rvdv\Nntp\Client $client, int $from, int $to, array $overviewFormat): array
    {
        $previousErrorHandler = set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException($message, $severity);
        });

        try {
            $result = $client->xover($from, $to, $overviewFormat);
            if (!is_array($result)) {
                return [];
            }

            return $result;
        } catch (Throwable) {
            return [];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, mixed> $article
     * @param string[] $candidates
     */
    private function overviewValue(array $article, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (isset($article[$candidate])) {
                return trim((string) $article[$candidate]);
            }
        }

        foreach ($article as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized = strtolower(str_replace(['_', ':', ' '], '-', $key));
            foreach ($candidates as $candidate) {
                $candidateNormalized = strtolower(str_replace(['_', ':', ' '], '-', $candidate));
                if ($normalized === $candidateNormalized) {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }
}
