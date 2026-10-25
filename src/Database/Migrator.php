<?php

declare(strict_types=1);

namespace NnSpotty\Database;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param (callable(string): void)|null $progress Receives progress lines for long data migrations. */
    public function migrate(?callable $progress = null): void
    {
        $this->ensureMigrationsTable();
        $executed = $this->executedMigrations();

        foreach ($this->migrations() as $name => $sqlStatements) {
            if (in_array($name, $executed, true)) {
                continue;
            }

            try {
                foreach ($sqlStatements as $sql) {
                    $this->pdo->exec($sql);
                }
                // Earlier hooks run before the overview read model exists; migration 0024 builds it in one pass.
                if ($name === '20261018_0008_category_backfill') {
                    (new \NnSpotty\Usenet\SpotRepository($this->pdo))->rebuildCategories(false);
                }
                if ($name === '20261018_0011_newznab_nzb') {
                    (new \NnSpotty\Usenet\SpotRepository($this->pdo))->rebuildCategories(false);
                }
                if ($name === '20261023_0024_spot_browse_index') {
                    (new \NnSpotty\Usenet\SpotRepository($this->pdo))->rebuildBrowseIndex($progress);
                }
                $insert = $this->pdo->prepare('INSERT INTO migrations (name, executed_at) VALUES (:name, UTC_TIMESTAMP())');
                $insert->execute(['name' => $name]);
            } catch (\Throwable $throwable) {
                throw $throwable;
            }
        }
    }

    /**
     * @return array<int, array{name: string, executed: bool}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        $executed = $this->executedMigrations();
        $status = [];

        foreach (array_keys($this->migrations()) as $name) {
            $status[] = [
                'name' => $name,
                'executed' => in_array($name, $executed, true),
            ];
        }

        return $status;
    }

    /**
     * @return array{ping: string, server_time_utc: string}
     */
    public function health(): array
    {
        $statement = $this->pdo->query('SELECT 1 AS ping, UTC_TIMESTAMP() AS server_time_utc');
        $row = $statement->fetch();

        if (!is_array($row) || !isset($row['ping'], $row['server_time_utc'])) {
            throw new \RuntimeException('Database health check gaf een ongeldig resultaat terug.');
        }

        return [
            'ping' => (string) $row['ping'],
            'server_time_utc' => (string) $row['server_time_utc'],
        ];
    }

    public function cleanup(int $days): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Cleanup days moet minimaal 1 zijn.');
        }

        $commentsStatement = $this->pdo->prepare('DELETE FROM post_comments WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)');
        $commentsStatement->execute();

        $runsStatement = $this->pdo->prepare('DELETE FROM retrieval_runs WHERE completed_at IS NOT NULL AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)');
        $runsStatement->execute();
        $loginAttemptsStatement = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)');
        $loginAttemptsStatement->execute();
        $auditLogsStatement = $this->pdo->prepare('DELETE FROM admin_audit_logs WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)');
        $auditLogsStatement->execute();
        $metadataStatement = $this->pdo->prepare('DELETE FROM tvdb_cache WHERE expires_at < UTC_TIMESTAMP()');
        $metadataStatement->execute();
        $musicStatement = $this->pdo->prepare('DELETE FROM music_cache WHERE expires_at < UTC_TIMESTAMP()');
        $musicStatement->execute();
        (new \NnSpotty\Usenet\SpotRepository($this->pdo))->refreshBrowseCounts();

        return [
            'post_comments_deleted' => $commentsStatement->rowCount(),
            'retrieval_runs_deleted' => $runsStatement->rowCount(),
            'login_attempts_deleted' => $loginAttemptsStatement->rowCount(),
            'audit_logs_deleted' => $auditLogsStatement->rowCount(),
            'tvdb_cache_deleted' => $metadataStatement->rowCount(),
            'music_cache_deleted' => $musicStatement->rowCount(),
        ];
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                executed_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * @return string[]
     */
    private function executedMigrations(): array
    {
        $statement = $this->pdo->query('SELECT name FROM migrations');
        $rows = $statement->fetchAll();

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * @return array<string, string[]>
     */
    private function migrations(): array
    {
        return [
            '20261017_0001_core_tables' => [
                'CREATE TABLE IF NOT EXISTS app_settings (
                    `key` VARCHAR(191) PRIMARY KEY,
                    `value` TEXT NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS user_preferences (
                    username VARCHAR(191) PRIMARY KEY,
                    theme VARCHAR(64) NOT NULL,
                    items_per_page INT NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spotter_lists (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    list_type VARCHAR(32) NOT NULL,
                    spotter VARCHAR(191) NOT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_list_spotter (list_type, spotter)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS post_comments (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    post_id VARCHAR(191) NOT NULL,
                    username VARCHAR(191) NOT NULL,
                    comment TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_post_comments_post_id (post_id),
                    INDEX idx_post_comments_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS app_users (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(191) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    is_admin TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS retrieval_runs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    mode VARCHAR(32) NOT NULL,
                    days INT NULL,
                    status VARCHAR(32) NOT NULL,
                    message TEXT NOT NULL,
                    started_at DATETIME NOT NULL,
                    completed_at DATETIME NOT NULL,
                    INDEX idx_retrieval_runs_completed_at (completed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261017_0002_login_attempts' => [
                'CREATE TABLE IF NOT EXISTS login_attempts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(191) NOT NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    success TINYINT(1) NOT NULL DEFAULT 0,
                    attempted_at DATETIME NOT NULL,
                    INDEX idx_login_attempts_attempted_at (attempted_at),
                    INDEX idx_login_attempts_ip (ip_address),
                    INDEX idx_login_attempts_username (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261017_0003_admin_audit_logs' => [
                'CREATE TABLE IF NOT EXISTS admin_audit_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    actor_username VARCHAR(191) NOT NULL,
                    action VARCHAR(128) NOT NULL,
                    target_type VARCHAR(128) NOT NULL,
                    target_id VARCHAR(191) NOT NULL,
                    details_json JSON NOT NULL,
                    ip_address VARCHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_admin_audit_logs_created_at (created_at),
                    INDEX idx_admin_audit_logs_actor (actor_username),
                    INDEX idx_admin_audit_logs_action (action)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261017_0004_usenet_posts' => [
                'CREATE TABLE IF NOT EXISTS usenet_posts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    group_name VARCHAR(191) NOT NULL,
                    category VARCHAR(128) NOT NULL,
                    article_number BIGINT UNSIGNED NOT NULL,
                    message_id VARCHAR(512) NOT NULL,
                    subject TEXT NOT NULL,
                    from_header VARCHAR(512) NOT NULL,
                    posted_at DATETIME NULL,
                    references_header TEXT NULL,
                    bytes BIGINT UNSIGNED NULL,
                    lines_count INT NULL,
                    raw_overview JSON NOT NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY uniq_usenet_posts_message_id (message_id(191)),
                    INDEX idx_usenet_posts_group_article (group_name, article_number),
                    INDEX idx_usenet_posts_category_posted_at (category, posted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS retrieval_cursors (
                    group_name VARCHAR(191) PRIMARY KEY,
                    last_article BIGINT UNSIGNED NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261017_0005_release_index' => [
                'ALTER TABLE usenet_posts ADD COLUMN IF NOT EXISTS release_key CHAR(40) NULL AFTER subject',
                'ALTER TABLE usenet_posts ADD COLUMN IF NOT EXISTS release_title VARCHAR(255) NULL AFTER release_key',
                'ALTER TABLE usenet_posts ADD INDEX IF NOT EXISTS idx_usenet_posts_release_key (release_key)',
                'CREATE TABLE IF NOT EXISTS spot_releases (
                    release_key CHAR(40) PRIMARY KEY,
                    release_title VARCHAR(255) NOT NULL,
                    category VARCHAR(128) NOT NULL,
                    group_name VARCHAR(191) NOT NULL,
                    poster VARCHAR(512) NOT NULL,
                    total_parts INT UNSIGNED NOT NULL DEFAULT 0,
                    total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    first_posted_at DATETIME NULL,
                    last_posted_at DATETIME NULL,
                    last_message_id VARCHAR(512) NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_spot_releases_category_last (category, last_posted_at),
                    INDEX idx_spot_releases_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0006_spotnet' => [
                'CREATE TABLE IF NOT EXISTS spotnet_spots (
                    id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    message_id VARCHAR(250) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                    group_name VARCHAR(191) NOT NULL,
                    title TEXT NOT NULL,
                    description MEDIUMTEXT NOT NULL,
                    poster TEXT NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    subcategories JSON NOT NULL,
                    size BIGINT UNSIGNED NOT NULL,
                    posted_at DATETIME NOT NULL,
                    website TEXT NOT NULL,
                    tag TEXT NOT NULL,
                    image_url TEXT NOT NULL,
                    image_segments JSON NOT NULL,
                    nzb_segments JSON NOT NULL,
                    full_xml MEDIUMTEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_spotnet_spots_date (posted_at, id),
                    INDEX idx_spotnet_spots_category_date (category, posted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spotnet_comments (
                    id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    message_id VARCHAR(250) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    poster TEXT NOT NULL,
                    body MEDIUMTEXT NOT NULL,
                    posted_at DATETIME NOT NULL,
                    INDEX idx_spotnet_comments_spot_date (spot_id, posted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spotnet_images (
                    spot_id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    mime_type VARCHAR(32) NOT NULL,
                    image_data MEDIUMBLOB NOT NULL,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'UPDATE app_settings
                 SET `value` = JSON_SET(`value`, \'$.explicit_groups\', \'spots:free.pt,comments:free.usenet\'), updated_at = UTC_TIMESTAMP()
                 WHERE `key` = \'admin.usenet_settings\' AND JSON_VALID(`value`)
                   AND JSON_UNQUOTE(JSON_EXTRACT(`value`, \'$.explicit_groups\')) = \'spots:free.pt,comments:free.ptd\'',
            ],
            '20261018_0007_categories_visibility' => [
                'ALTER TABLE user_preferences ADD COLUMN IF NOT EXISTS show_erotica TINYINT(1) NOT NULL DEFAULT 0',
                'ALTER TABLE spotnet_spots ADD COLUMN IF NOT EXISTS browse_type VARCHAR(32) NOT NULL DEFAULT \'Overig\'',
                'ALTER TABLE spotnet_spots ADD COLUMN IF NOT EXISTS is_erotica TINYINT(1) NOT NULL DEFAULT 1',
                'ALTER TABLE spotnet_spots ADD INDEX IF NOT EXISTS idx_spotnet_visibility (is_erotica, browse_type, posted_at)',
                'CREATE TABLE IF NOT EXISTS spotnet_spot_categories (
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    code VARCHAR(16) CHARACTER SET ascii NOT NULL,
                    PRIMARY KEY (spot_id, code),
                    INDEX idx_spotnet_category_code (code, spot_id),
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0008_category_backfill' => [],
            '20261018_0009_user_spot_columns' => [
                'CREATE TABLE IF NOT EXISTS user_spot_columns (
                    username VARCHAR(191) NOT NULL,
                    head_category TINYINT UNSIGNED NOT NULL,
                    columns_json JSON NOT NULL,
                    PRIMARY KEY (username, head_category),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0010_spot_details_comments' => [
                'ALTER TABLE spotnet_spots ADD COLUMN IF NOT EXISTS newsreader VARCHAR(512) NOT NULL DEFAULT \'\'',
                'UPDATE spotnet_comments
                 SET spot_id = SHA2(CONCAT(SUBSTRING_INDEX(SUBSTRING_INDEX(message_id, \'@\', 1), \'.\', 1),
                     \'@\', SUBSTRING_INDEX(message_id, \'@\', -1)), 256)
                 WHERE LOCATE(\'.\', SUBSTRING_INDEX(message_id, \'@\', 1)) > 1',
            ],
            '20261018_0011_newznab_nzb' => [
                'CREATE TABLE IF NOT EXISTS spotnet_nzbs (
                    spot_id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    nzb_data MEDIUMBLOB NOT NULL,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spotnet_newznab_categories (
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    category_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (spot_id, category_id),
                    INDEX idx_newznab_category (category_id, spot_id),
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS user_api_keys (
                    username VARCHAR(191) PRIMARY KEY,
                    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
                    created_at DATETIME NOT NULL,
                    window_start DATETIME NOT NULL,
                    request_count INT UNSIGNED NOT NULL DEFAULT 0,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0012_sabnzbd' => [
                'CREATE TABLE IF NOT EXISTS sabnzbd_connections (
                    scope VARCHAR(200) PRIMARY KEY,
                    username VARCHAR(191) NULL UNIQUE,
                    url VARCHAR(1000) NOT NULL,
                    api_key VARCHAR(128) NOT NULL,
                    enabled BOOLEAN NOT NULL DEFAULT 0,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS sabnzbd_category_mapping (
                    type VARCHAR(50) PRIMARY KEY,
                    category VARCHAR(100) NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS sabnzbd_downloads (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    scope VARCHAR(200) NOT NULL,
                    username VARCHAR(191) NOT NULL,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    title TEXT NOT NULL,
                    category VARCHAR(100) NOT NULL,
                    token CHAR(32) CHARACTER SET ascii NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    nzo_id VARCHAR(200) NULL,
                    created_at DATETIME NOT NULL,
                    UNIQUE KEY idx_sab_token (scope, token),
                    INDEX idx_sab_history (scope, id),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0013_tvdb' => [
                'CREATE TABLE IF NOT EXISTS tvdb_settings (
                    id TINYINT PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT 0,
                    api_key VARCHAR(255) NOT NULL, pin VARCHAR(255) NOT NULL,
                    language VARCHAR(3) NOT NULL, token TEXT NULL, token_until DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS tvdb_preferences (
                    username VARCHAR(191) PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT 0,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS tvdb_cache (
                    cache_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    data_json MEDIUMTEXT NOT NULL, expires_at DATETIME NOT NULL,
                    INDEX idx_tvdb_expiry (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS tvdb_matches (
                    spot_id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    input_hash CHAR(64) CHARACTER SET ascii NOT NULL,
                    choice_json TEXT NULL, result_json MEDIUMTEXT NULL, expires_at DATETIME NULL,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261018_0014_browse_visits' => [
                'CREATE TABLE IF NOT EXISTS user_browse_visits (
                    username VARCHAR(191) PRIMARY KEY,
                    last_visit_at DATETIME(6) NOT NULL,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'ALTER TABLE spotnet_spots MODIFY COLUMN created_at DATETIME(6) NOT NULL',
                'ALTER TABLE spotnet_spots ADD INDEX IF NOT EXISTS idx_spotnet_created_at (created_at)',
            ],
            '20261019_0015_browse_performance' => [
                'ALTER TABLE spotnet_spots
                    ADD INDEX IF NOT EXISTS idx_spotnet_visible_date (is_erotica, posted_at, id),
                    ADD INDEX IF NOT EXISTS idx_spotnet_type_date (browse_type, posted_at, id)',
            ],
            '20261019_0016_spotter_keys' => [
                'ALTER TABLE spotnet_spots
                    ADD COLUMN IF NOT EXISTS spotter_key CHAR(64) CHARACTER SET ascii NULL,
                    ADD COLUMN IF NOT EXISTS signature_state VARCHAR(16) NOT NULL DEFAULT \'missing\'',
                'CREATE TABLE IF NOT EXISTS spotter_keys (
                    list_type VARCHAR(16) NOT NULL,
                    key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
                    display_name VARCHAR(191) NOT NULL,
                    PRIMARY KEY (key_hash, list_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spotter_list_settings (
                    id TINYINT PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0,
                    updated_at DATETIME NULL, attempted_at DATETIME NULL, last_error TEXT NULL, last_warning TEXT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'INSERT IGNORE INTO spotter_list_settings (id) VALUES (1)',
            ],
            '20261019_0017_music_metadata' => [
                'CREATE TABLE IF NOT EXISTS music_settings (
                    id TINYINT PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT 0,
                    last_request_at DATETIME(6) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'INSERT IGNORE INTO music_settings (id) VALUES (1)',
                'CREATE TABLE IF NOT EXISTS music_preferences (
                    username VARCHAR(191) PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT 0,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS music_cache (
                    cache_key CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    data_json MEDIUMTEXT NOT NULL, expires_at DATETIME NOT NULL,
                    INDEX idx_music_expiry (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS music_matches (
                    spot_id CHAR(64) CHARACTER SET ascii PRIMARY KEY,
                    input_hash CHAR(64) CHARACTER SET ascii NOT NULL,
                    choice_json TEXT NULL, result_json MEDIUMTEXT NULL, expires_at DATETIME NULL,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261019_0018_music_fanart' => [
                'ALTER TABLE music_settings
                    ADD COLUMN IF NOT EXISTS fanart_enabled BOOLEAN NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS fanart_key VARCHAR(255) NOT NULL DEFAULT \'\'',
                'ALTER TABLE music_preferences ADD COLUMN IF NOT EXISTS fanart_enabled BOOLEAN NOT NULL DEFAULT 0',
            ],
            '20261019_0019_favorites' => [
                'CREATE TABLE IF NOT EXISTS spot_favorites (
                    username VARCHAR(191) NOT NULL,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    PRIMARY KEY (username, spot_id),
                    INDEX idx_favorite_spot (spot_id),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261020_0020_comment_publication' => [
                'CREATE TABLE IF NOT EXISTS comment_signing_keys (
                    scope VARCHAR(191) CHARACTER SET ascii PRIMARY KEY,
                    username VARCHAR(191) NULL,
                    private_key TEXT NOT NULL,
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS comment_publications (
                    username VARCHAR(191) NOT NULL,
                    request_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    message_id VARCHAR(250) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    body TEXT NOT NULL,
                    status VARCHAR(16) NOT NULL,
                    error_message VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (username, request_id),
                    UNIQUE KEY uq_comment_message (message_id),
                    INDEX idx_comment_rate (username, created_at),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE,
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            ],
            '20261020_0021_accounts' => [
                'ALTER TABLE app_users
                    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1,
                    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                    ADD COLUMN IF NOT EXISTS auth_version INT NOT NULL DEFAULT 1,
                    ADD COLUMN IF NOT EXISTS display_name VARCHAR(80) NOT NULL DEFAULT \'\',
                    ADD COLUMN IF NOT EXISTS signature VARCHAR(200) NOT NULL DEFAULT \'\',
                    ADD COLUMN IF NOT EXISTS email VARCHAR(254) NOT NULL DEFAULT \'\',
                    ADD COLUMN IF NOT EXISTS avatar_data MEDIUMBLOB NULL,
                    ADD COLUMN IF NOT EXISTS avatar_mime VARCHAR(64) NULL',
                'CREATE TABLE IF NOT EXISTS user_groups (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(80) NOT NULL UNIQUE,
                    system_key VARCHAR(20) NULL UNIQUE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS group_permissions (
                    group_id INT UNSIGNED NOT NULL,
                    permission VARCHAR(64) CHARACTER SET ascii NOT NULL,
                    PRIMARY KEY (group_id, permission),
                    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS user_group_memberships (
                    username VARCHAR(191) NOT NULL,
                    group_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (username, group_id),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE,
                    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'INSERT IGNORE INTO user_groups (name, system_key) VALUES
                    (\'Beheerders\', \'admins\'), (\'Moderators\', \'moderators\'), (\'Gebruikers\', \'users\')',
                'INSERT IGNORE INTO user_group_memberships (username, group_id)
                    SELECT u.username, g.id FROM app_users u JOIN user_groups g
                    ON g.system_key = IF(u.is_admin = 1, \'admins\', \'users\')',
                'INSERT IGNORE INTO group_permissions (group_id, permission)
                    SELECT g.id, p.permission FROM user_groups g JOIN (
                        SELECT \'comments.publish\' AS permission UNION ALL SELECT \'nzb.download\'
                        UNION ALL SELECT \'nzb.forward\' UNION ALL SELECT \'api.use\'
                    ) p ON g.system_key IN (\'users\', \'moderators\')',
                'INSERT IGNORE INTO group_permissions (group_id, permission)
                    SELECT g.id, p.permission FROM user_groups g JOIN (
                        SELECT \'metadata.manage\' AS permission UNION ALL SELECT \'spotters.manage\'
                        UNION ALL SELECT \'retrieve.run\' UNION ALL SELECT \'audit.view\'
                    ) p ON g.system_key = \'moderators\'',
                'ALTER TABLE comment_publications
                    ADD COLUMN IF NOT EXISTS poster VARCHAR(191) NULL,
                    ADD COLUMN IF NOT EXISTS source_body TEXT NULL',
            ],
            '20261021_0022_lazy_comments' => [
                'ALTER TABLE spotnet_comments
                    MODIFY body MEDIUMTEXT NULL,
                    ADD COLUMN IF NOT EXISTS body_status ENUM(\'loaded\', \'pending\', \'unavailable\', \'invalid\') NOT NULL DEFAULT \'loaded\',
                    ADD INDEX IF NOT EXISTS idx_spotnet_comments_date (posted_at)',
                'INSERT IGNORE INTO group_permissions (group_id, permission)
                    SELECT id, \'comments.load\' FROM user_groups',
            ],
            '20261022_0023_tmdb_metadata' => [
                'CREATE TABLE IF NOT EXISTS tmdb_settings (
                    id TINYINT PRIMARY KEY, enabled BOOLEAN NOT NULL DEFAULT 0,
                    token TEXT NOT NULL, language VARCHAR(5) NOT NULL DEFAULT \'nl-NL\'
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS metadata_providers (
                    media_type VARCHAR(10) CHARACTER SET ascii PRIMARY KEY,
                    provider VARCHAR(10) CHARACTER SET ascii NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'INSERT IGNORE INTO metadata_providers (media_type, provider) VALUES (\'movie\', \'tmdb\'), (\'series\', \'tvdb\')',
            ],
            // Narrow overview read model; filled and given its FULLTEXT index by the hook in migrate().
            '20261023_0024_spot_browse_index' => [
                'CREATE TABLE IF NOT EXISTS spot_combos (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    signature CHAR(40) CHARACTER SET ascii NOT NULL UNIQUE,
                    category VARCHAR(32) NOT NULL,
                    browse_type VARCHAR(32) NOT NULL,
                    is_erotica TINYINT(1) NOT NULL,
                    spot_count INT NOT NULL DEFAULT 0,
                    INDEX idx_combo_scope (category, browse_type, is_erotica)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spot_combo_codes (
                    combo_id INT UNSIGNED NOT NULL,
                    code VARCHAR(16) CHARACTER SET ascii NOT NULL,
                    PRIMARY KEY (combo_id, code),
                    INDEX idx_combo_code (code, combo_id),
                    FOREIGN KEY (combo_id) REFERENCES spot_combos(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spot_browse (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
                    combo_id INT UNSIGNED NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    browse_type VARCHAR(32) NOT NULL,
                    is_erotica TINYINT(1) NOT NULL,
                    posted_at DATETIME NOT NULL,
                    created_at DATETIME(6) NOT NULL,
                    title TEXT NOT NULL,
                    INDEX idx_browse_date (posted_at, id, is_erotica, combo_id),
                    INDEX idx_browse_category (category, posted_at, id, is_erotica, combo_id),
                    INDEX idx_browse_type (browse_type, posted_at, id, is_erotica, combo_id),
                    INDEX idx_browse_combo (combo_id, posted_at, id),
                    INDEX idx_browse_created (created_at, combo_id),
                    FOREIGN KEY (spot_id) REFERENCES spotnet_spots(id) ON DELETE CASCADE,
                    FOREIGN KEY (combo_id) REFERENCES spot_combos(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS spot_filter_cache (
                    cache_key CHAR(40) CHARACTER SET ascii PRIMARY KEY,
                    codes TEXT NOT NULL,
                    expires_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                // Superseded by spot_browse; idx_spotnet_spots_date and idx_spotnet_visible_date stay for retention and Newznab.
                'ALTER TABLE spotnet_spots
                    DROP INDEX IF EXISTS idx_spotnet_visibility,
                    DROP INDEX IF EXISTS idx_spotnet_type_date,
                    DROP INDEX IF EXISTS idx_spotnet_spots_category_date,
                    DROP INDEX IF EXISTS idx_spotnet_created_at',
            ],
            '20261025_0025_activity' => [
                'CREATE TABLE IF NOT EXISTS activity_history (
                    username VARCHAR(191) NOT NULL,
                    spot_id CHAR(64) CHARACTER SET ascii NOT NULL,
                    kind VARCHAR(10) CHARACTER SET ascii NOT NULL,
                    source VARCHAR(16) CHARACTER SET ascii NOT NULL,
                    title TEXT NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    is_erotica TINYINT(1) NOT NULL,
                    first_at DATETIME(6) NOT NULL,
                    last_at DATETIME(6) NOT NULL,
                    action_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
                    PRIMARY KEY (username, spot_id, kind, source),
                    INDEX idx_activity_history (username, kind, last_at, spot_id),
                    FOREIGN KEY (username) REFERENCES app_users(username) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'CREATE TABLE IF NOT EXISTS activity_daily (
                    day DATE NOT NULL,
                    kind VARCHAR(10) CHARACTER SET ascii NOT NULL,
                    source VARCHAR(16) CHARACTER SET ascii NOT NULL,
                    category VARCHAR(32) NOT NULL,
                    is_erotica TINYINT(1) NOT NULL,
                    action_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (day, kind, source, category, is_erotica)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
                'INSERT IGNORE INTO activity_history
                    (username, spot_id, kind, source, title, category, is_erotica, first_at, last_at, action_count)
                    SELECT d.username, d.spot_id, \'download\', \'sabnzbd\', MAX(d.title),
                        MAX(s.browse_type), MAX(s.is_erotica),
                        MIN(d.created_at), MAX(d.created_at), COUNT(*)
                    FROM sabnzbd_downloads d JOIN app_users u ON u.username = d.username
                    JOIN spotnet_spots s ON s.id = d.spot_id
                    WHERE d.status = \'sent\' GROUP BY d.username, d.spot_id',
                'INSERT IGNORE INTO activity_daily (day, kind, source, category, is_erotica, action_count)
                    SELECT DATE(d.created_at), \'download\', \'sabnzbd\', s.browse_type,
                        s.is_erotica, COUNT(*)
                    FROM sabnzbd_downloads d JOIN app_users u ON u.username = d.username
                    JOIN spotnet_spots s ON s.id = d.spot_id
                    WHERE d.status = \'sent\'
                    GROUP BY DATE(d.created_at), s.browse_type, s.is_erotica',
            ],
        ];
    }
}
