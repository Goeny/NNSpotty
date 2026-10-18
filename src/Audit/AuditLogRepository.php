<?php

declare(strict_types=1);

namespace NnSpotty\Audit;

use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(string $actorUsername, string $action, string $targetType, string $targetId, array $details, string $ipAddress): void
    {
        $encodedDetails = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encodedDetails === false) {
            throw new \RuntimeException('Audit details konden niet worden ge-encode naar JSON.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit_logs (actor_username, action, target_type, target_id, details_json, ip_address, created_at)
             VALUES (:actor_username, :action, :target_type, :target_id, :details_json, :ip_address, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'actor_username' => $actorUsername,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details_json' => $encodedDetails,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @return array<int, array{actor_username: string, action: string, target_type: string, target_id: string, details_json: string, ip_address: string, created_at: string}>
     */
    public function recent(int $limit = 50): array
    {
        $safeLimit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            'SELECT actor_username, action, target_type, target_id, details_json, ip_address, DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at
             FROM admin_audit_logs
             ORDER BY id DESC
             LIMIT ' . $safeLimit
        );
        $rows = $statement->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'actor_username' => (string) $row['actor_username'],
                'action' => (string) $row['action'],
                'target_type' => (string) $row['target_type'],
                'target_id' => (string) $row['target_id'],
                'details_json' => (string) $row['details_json'],
                'ip_address' => (string) $row['ip_address'],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }
}
