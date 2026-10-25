<?php

declare(strict_types=1);

namespace NnSpotty\Users;

final class Permissions
{
    public const LABELS = [
        'comments.publish' => 'Reacties publiceren',
        'comments.load' => 'Reacties van Usenet laden',
        'nzb.download' => 'NZB downloaden',
        'nzb.forward' => 'NZB doorsturen',
        'api.use' => 'Newznab API gebruiken',
        'metadata.manage' => 'Metadata corrigeren',
        'spotters.manage' => 'Spotterlijsten beheren',
        'retrieve.run' => 'Handmatige retrieve starten',
        'audit.view' => 'Auditlog bekijken',
        'statistics.view' => 'Statistieken bekijken',
        'users.manage' => 'Gebruikers beheren',
        'groups.manage' => 'Groepen en rechten beheren',
        'settings.manage' => 'Systeeminstellingen beheren',
    ];

    public static function allows(?array $user, string $permission): bool
    {
        return $user !== null && ($user['active'] ?? true) && !($user['must_change_password'] ?? false)
            && (($user['is_admin'] ?? false) || in_array($permission, $user['permissions'] ?? [], true));
    }
}
