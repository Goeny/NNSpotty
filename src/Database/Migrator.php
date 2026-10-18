<?php

declare(strict_types=1);

namespace NnSpotty\Database;

use PDO;

final class Migrator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(): void
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
                if ($name === '20261018_0008_category_backfill') {
                    (new \NnSpotty\Usenet\SpotRepository($this->pdo))->rebuildCategories();
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

        return [
            'post_comments_deleted' => $commentsStatement->rowCount(),
            'retrieval_runs_deleted' => $runsStatement->rowCount(),
            'login_attempts_deleted' => $loginAttemptsStatement->rowCount(),
            'audit_logs_deleted' => $auditLogsStatement->rowCount(),
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
        ];
    }
}
