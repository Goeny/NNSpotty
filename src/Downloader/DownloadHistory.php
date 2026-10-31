<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use PDO;

/** Local send attempts for every download client, so uncertain uploads are never silently retried. */
final class DownloadHistory
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function history(string $scope, string $client, bool $adult): array
    {
        Targets::client($client);
        $select = $this->pdo->prepare('SELECT h.id, h.client, h.title, h.category, h.status, h.nzo_id, h.created_at
            FROM sabnzbd_downloads h JOIN spotnet_spots s ON s.id = h.spot_id
            WHERE h.scope = ? AND h.client = ?' . ($adult ? '' : ' AND s.is_erotica = 0') . ' ORDER BY h.id DESC LIMIT 30');
        $select->execute([$scope, $client]);
        return $select->fetchAll();
    }

    /** @return array<string,mixed> */
    public function begin(string $scope, string $client, string $username, array $spot, string $category, string $token): array
    {
        Targets::client($client);
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Ongeldige downloadbevestiging.');
        }
        $insert = $this->pdo->prepare("INSERT INTO sabnzbd_downloads (scope, client, username, spot_id, title, category, token, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'sending', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $insert->execute([$scope, $client, $username, $spot['id'], $spot['title'], $category, $token]);
        $fresh = $insert->rowCount() === 1;
        $select = $this->pdo->prepare('SELECT id, client, status, nzo_id, spot_id FROM sabnzbd_downloads WHERE scope = ? AND token = ?');
        $select->execute([$scope, $token]);
        $row = $select->fetch();
        if ($row['spot_id'] !== $spot['id'] || $row['client'] !== $client) {
            throw new InvalidArgumentException('Downloadbevestiging hoort bij een andere spot of downloadclient.');
        }
        return $row + ['fresh' => $fresh];
    }

    public function finish(int $id, string $status, ?string $remoteId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT username, client, spot_id, status FROM sabnzbd_downloads WHERE id = ? FOR UPDATE');
            $select->execute([$id]);
            $download = $select->fetch();
            if ($download === false) throw new InvalidArgumentException('Downloadpoging niet gevonden.');
            if ($download['status'] !== 'sent') {
                $this->pdo->prepare('UPDATE sabnzbd_downloads SET status = ?, nzo_id = ? WHERE id = ?')->execute([$status, $remoteId, $id]);
                if ($status === 'sent') {
                    $spot = (new \NnSpotty\Usenet\SpotRepository($this->pdo))->getSpot($download['spot_id'], true);
                    if ($spot === null) throw new \RuntimeException('Spot voor downloadhistorie niet gevonden.');
                    (new \NnSpotty\Activity\Repository($this->pdo))->record($download['username'], $spot, $download['client']);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }
}
