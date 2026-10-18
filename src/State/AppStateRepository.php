<?php

declare(strict_types=1);

namespace NnSpotty\State;

use PDO;

final class AppStateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   retention: int,
     *   server: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   explicit_groups: string,
     *   group_pattern: string,
     *   discover_max_groups: int,
     *   max_articles_per_group: int
     * }
     */
    public function getUsenetSettings(): array
    {
        $settings = $this->decodeSetting('admin.usenet_settings');

        return [
            'retention' => (int) ($settings['retention'] ?? 3000),
            'server' => (string) ($settings['server'] ?? ''),
            'port' => (int) ($settings['port'] ?? 119),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'encryption' => (string) ($settings['encryption'] ?? 'none'),
            'explicit_groups' => (string) ($settings['explicit_groups'] ?? \NnSpotty\Usenet\SpotnetParser::DEFAULT_GROUPS),
            'group_pattern' => (string) ($settings['group_pattern'] ?? ''),
            'discover_max_groups' => (int) ($settings['discover_max_groups'] ?? 250),
            'max_articles_per_group' => (int) ($settings['max_articles_per_group'] ?? 5000),
        ];
    }

    /**
     * @param array{
     *   retention: int,
     *   server: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   explicit_groups: string,
     *   group_pattern: string,
     *   discover_max_groups: int,
     *   max_articles_per_group: int
     * } $settings
     */
    public function saveUsenetSettings(array $settings): void
    {
        $this->saveSetting('admin.usenet_settings', $settings);
    }

    /**
     * @return array{theme: string, items_per_page: int, show_erotica: bool}
     */
    public function getPreferences(string $username): array
    {
        $statement = $this->pdo->prepare('SELECT theme, items_per_page, show_erotica FROM user_preferences WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $prefs = $statement->fetch();
        if (!is_array($prefs)) {
            $prefs = [];
        }

        return [
            'theme' => (string) ($prefs['theme'] ?? 'default'),
            'items_per_page' => (int) ($prefs['items_per_page'] ?? 50),
            'show_erotica' => (bool) ($prefs['show_erotica'] ?? false),
        ];
    }

    /**
     * @param array{theme: string, items_per_page: int, show_erotica?: bool} $preferences
     */
    public function savePreferences(string $username, array $preferences): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_preferences (username, theme, items_per_page, show_erotica, updated_at)
             VALUES (:username, :theme, :items_per_page, :show_erotica, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                theme = VALUES(theme),
                items_per_page = VALUES(items_per_page),
                show_erotica = VALUES(show_erotica),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'username' => $username,
            'theme' => $preferences['theme'],
            'items_per_page' => $preferences['items_per_page'],
            'show_erotica' => (int) ($preferences['show_erotica'] ?? false),
        ]);
    }

    public function eroticaAllowed(): bool
    {
        return ($this->decodeSetting('admin.content_settings')['allow_erotica'] ?? false) === true;
    }

    public function setEroticaAllowed(bool $allowed): void
    {
        $this->saveSetting('admin.content_settings', ['allow_erotica' => $allowed]);
    }

    public function canViewErotica(string $username): bool
    {
        return $this->eroticaAllowed() && $this->getPreferences($username)['show_erotica'];
    }

    /**
     * @return array{whitelist: string[], blacklist: string[]}
     */
    public function getSpotterLists(): array
    {
        $statement = $this->pdo->query('SELECT list_type, spotter FROM spotter_lists ORDER BY spotter ASC');
        $rows = $statement->fetchAll();
        $whitelist = [];
        $blacklist = [];

        foreach ($rows as $row) {
            $type = (string) ($row['list_type'] ?? '');
            $spotter = (string) ($row['spotter'] ?? '');
            if ($spotter === '') {
                continue;
            }
            if ($type === 'whitelist') {
                $whitelist[] = $spotter;
            } elseif ($type === 'blacklist') {
                $blacklist[] = $spotter;
            }
        }

        return [
            'whitelist' => array_values(array_unique($whitelist)),
            'blacklist' => array_values(array_unique($blacklist)),
        ];
    }

    public function addSpotter(string $listType, string $spotter): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO spotter_lists (list_type, spotter, created_at)
             VALUES (:list_type, :spotter, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'list_type' => $listType,
            'spotter' => $spotter,
        ]);
    }

    /**
     * @return array<int, array{post_id: string, username: string, comment: string, created_at: string}>
     */
    public function getComments(bool $showErotica = true): array
    {
        $statement = $this->pdo->query(
            'SELECT p.post_id, p.username, p.comment, DATE_FORMAT(p.created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at
             FROM post_comments p'
             . ($showErotica ? '' : ' LEFT JOIN spotnet_spots s ON s.id = p.post_id WHERE s.is_erotica = 0')
             . ' ORDER BY p.created_at ASC'
        );
        $comments = $statement->fetchAll();

        return array_values(array_filter($comments, static function ($entry): bool {
            return is_array($entry)
                && isset($entry['post_id'], $entry['username'], $entry['comment'], $entry['created_at'])
                && is_string($entry['post_id'])
                && is_string($entry['username'])
                && is_string($entry['comment'])
                && is_string($entry['created_at']);
        }));
    }

    public function addComment(string $postId, string $username, string $comment): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO post_comments (post_id, username, comment, created_at)
             VALUES (:post_id, :username, :comment, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'post_id' => $postId,
            'username' => $username,
            'comment' => $comment,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSetting(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT `value` FROM app_settings WHERE `key` = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $row = $statement->fetch();
        if (!is_array($row) || !isset($row['value']) || !is_string($row['value'])) {
            return [];
        }

        $decoded = json_decode($row['value'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Instelling ' . $key . ' bevat ongeldige JSON data.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function saveSetting(string $key, array $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Kon JSON setting niet serialiseren.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`, updated_at)
             VALUES (:key, :value, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                `value` = VALUES(`value`),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'key' => $key,
            'value' => $encoded,
        ]);
    }
}
