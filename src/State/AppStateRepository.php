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
     *   retrieve_after: ?string,
     *   server: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   explicit_groups: string,
     *   group_pattern: string,
     *   discover_max_groups: int,
     *   max_articles_per_group: int,
     *   pipeline_depth: int
     * }
     */
    public function getUsenetSettings(): array
    {
        $settings = $this->decodeSetting('admin.usenet_settings');

        return [
            'retention' => (int) ($settings['retention'] ?? 3000),
            'retrieve_after' => isset($settings['retrieve_after']) && $settings['retrieve_after'] !== ''
                ? (string) $settings['retrieve_after']
                : null,
            'server' => (string) ($settings['server'] ?? ''),
            'port' => (int) ($settings['port'] ?? 119),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'encryption' => (string) ($settings['encryption'] ?? 'none'),
            'explicit_groups' => (string) ($settings['explicit_groups'] ?? \NnSpotty\Usenet\SpotnetParser::DEFAULT_GROUPS),
            'group_pattern' => (string) ($settings['group_pattern'] ?? ''),
            'discover_max_groups' => (int) ($settings['discover_max_groups'] ?? 250),
            'max_articles_per_group' => (int) ($settings['max_articles_per_group'] ?? 5000),
            'pipeline_depth' => (int) ($settings['pipeline_depth'] ?? 32),
        ];
    }

    /**
     * @param array{
     *   retention: int,
     *   retrieve_after: ?string,
     *   server: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   explicit_groups: string,
     *   group_pattern: string,
     *   discover_max_groups: int,
     *   max_articles_per_group: int,
     *   pipeline_depth?: int
     * } $settings
     */
    public function saveUsenetSettings(array $settings): void
    {
        $depth = $settings['pipeline_depth'] ?? 32;
        if (!is_int($depth) || $depth < 1 || $depth > 128) {
            throw new \InvalidArgumentException('Pipeline-diepte moet een geheel getal tussen 1 en 128 zijn.');
        }
        $settings['pipeline_depth'] = $depth;
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

    public function apiEnabled(): bool
    {
        $enabled = $this->decodeSetting('admin.newznab')['enabled'] ?? false;
        if (!is_bool($enabled)) {
            throw new \RuntimeException('Ongeldige opgeslagen Newznab-instelling.');
        }
        return $enabled;
    }

    public function setApiEnabled(bool $enabled): void
    {
        $this->saveSetting('admin.newznab', ['enabled' => $enabled]);
    }

    /** @return array{retrieve_comments: bool, show_comments: bool, images_enabled: bool} */
    public function spotContentSettings(): array
    {
        $stored = $this->decodeSetting('admin.spot_content');
        $settings = ['retrieve_comments' => true, 'show_comments' => true, 'images_enabled' => true];
        foreach ($settings as $key => $default) {
            if (array_key_exists($key, $stored)) {
                if (!is_bool($stored[$key])) {
                    throw new \RuntimeException('Ongeldige opgeslagen inhoudsinstelling: ' . $key . '.');
                }
                $settings[$key] = $stored[$key];
            }
        }
        return $settings;
    }

    /** @param array{retrieve_comments: bool, show_comments: bool, images_enabled: bool} $settings */
    public function saveSpotContentSettings(array $settings): void
    {
        if (count($settings) !== 3) {
            throw new \InvalidArgumentException('Onvolledige inhoudsinstellingen.');
        }
        foreach (['retrieve_comments', 'show_comments', 'images_enabled'] as $key) {
            if (!array_key_exists($key, $settings) || !is_bool($settings[$key])) {
                throw new \InvalidArgumentException('Ongeldige inhoudsinstelling: ' . $key . '.');
            }
        }
        $this->saveSetting('admin.spot_content', $settings);
    }

    /** @return array<int, list<string>> */
    public function globalSpotColumns(): array
    {
        $stored = $this->decodeSetting('admin.spot_columns');
        $columns = \NnSpotty\UI\SpotColumns::defaults();
        foreach ($columns as $head => $defaults) {
            if (isset($stored[$head])) {
                $columns[$head] = \NnSpotty\UI\SpotColumns::validate($stored[$head]);
            }
        }
        return $columns;
    }

    /** @param array<int, list<string>> $columns */
    public function saveGlobalSpotColumns(array $columns): void
    {
        $validated = [];
        foreach (\NnSpotty\Usenet\SpotCategories::HEADS as $head => $label) {
            $validated[$head] = \NnSpotty\UI\SpotColumns::validate($columns[$head] ?? []);
        }
        $this->saveSetting('admin.spot_columns', $validated);
    }

    /** @return array<int, list<string>> */
    public function personalSpotColumns(string $username): array
    {
        $statement = $this->pdo->prepare('SELECT head_category, columns_json FROM user_spot_columns WHERE username = :username');
        $statement->execute(['username' => $username]);
        $columns = [];
        foreach ($statement->fetchAll() as $row) {
            $columns[(int) $row['head_category']] = \NnSpotty\UI\SpotColumns::validate(json_decode($row['columns_json'], true, 512, JSON_THROW_ON_ERROR));
        }
        return $columns;
    }

    /** @param array<int, list<string>> $columns */
    public function savePersonalSpotColumns(string $username, array $columns): void
    {
        foreach ($columns as $head => $selection) {
            if (!array_key_exists($head, \NnSpotty\Usenet\SpotCategories::HEADS)) {
                throw new \InvalidArgumentException('Ongeldige hoofdcategorie.');
            }
            \NnSpotty\UI\SpotColumns::validate($selection);
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM user_spot_columns WHERE username = :username');
            $delete->execute(['username' => $username]);
            $insert = $this->pdo->prepare('INSERT INTO user_spot_columns (username, head_category, columns_json) VALUES (:username, :head, :columns)');
            foreach ($columns as $head => $selection) {
                $insert->execute(['username' => $username, 'head' => $head, 'columns' => json_encode(\NnSpotty\UI\SpotColumns::validate($selection), JSON_THROW_ON_ERROR)]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return list<string> */
    public function effectiveSpotColumns(string $username, ?string $category): array
    {
        $head = \NnSpotty\Usenet\SpotCategories::headForCategory($category);
        return $head === null ? ['cover', 'title', 'format', 'poster', 'size']
            : ($this->personalSpotColumns($username)[$head] ?? $this->globalSpotColumns()[$head]);
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
