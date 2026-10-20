<?php
declare(strict_types=1);

use NnSpotty\Users\UserRepository;
use NnSpotty\Users\Permissions;

$accountUsers = new UserRepository($pdo);
$accountUsers->create('account-admin', 'synthetic-admin-password', true);
$accountUsers->create('account-reader', 'synthetic-reader-password', false);
$accountGroups = $accountUsers->groups();
$accountGroupIds = [];
foreach ($accountGroups as $group) $accountGroupIds[$group['system_key']] = (int) $group['id'];
check($accountUsers->findByUsername('account-admin')['is_admin'] === 1
    && Permissions::allows($accountUsers->findByUsername('account-reader'), 'comments.publish'),
    'Nieuwe accounts krijgen bestaande admin- of gebruikersgroep');
rejects(static fn () => $accountUsers->delete('account-admin'), 'Laatste beheerder kan niet verwijderd worden');
rejects(static fn () => $accountUsers->manage('account-admin', false, [$accountGroupIds['admins']]), 'Laatste beheerder kan niet gedeactiveerd worden');
rejects(static fn () => $accountUsers->setAdmin('account-admin', false), 'Laatste beheerder kan niet gedegradeerd worden');
$accountUsers->manage('account-reader', true, [$accountGroupIds['users'], $accountGroupIds['moderators']]);
$moderatorAccount = $accountUsers->findByUsername('account-reader');
foreach (['metadata.manage', 'spotters.manage', 'retrieve.run', 'audit.view', 'comments.publish'] as $permission) {
    check(Permissions::allows($moderatorAccount, $permission), 'Moderator combineert rechten: ' . $permission);
}
foreach (['users.manage', 'groups.manage', 'settings.manage'] as $permission) {
    check(!Permissions::allows($moderatorAccount, $permission), 'Moderator mist beheerrecht: ' . $permission);
}
$accountUsers->saveGroup(null, 'Alleen downloaden', ['nzb.download']);
$customAccountGroup = (int) $pdo->query("SELECT id FROM user_groups WHERE name = 'Alleen downloaden'")->fetchColumn();
$accountUsers->manage('account-reader', true, [$customAccountGroup]);
check(Permissions::allows($accountUsers->findByUsername('account-reader'), 'nzb.download')
    && !Permissions::allows($accountUsers->findByUsername('account-reader'), 'comments.publish'),
    'Rechten volgen actuele groepsindeling');
rejects(static fn () => $accountUsers->deleteGroup($customAccountGroup), 'Groep met leden kan niet verwijderd worden');
rejects(static fn () => $accountUsers->saveGroup($accountGroupIds['admins'], 'Andere adminnaam', []), 'Beheerdersgroep is beschermd');
rejects(static fn () => $accountUsers->saveGroup(null, 'Andere groep', ['invalid']), 'Onbekende rechten worden afgewezen');
rejects(static fn () => $accountUsers->manage('account-reader', true, [999999]), 'Onbekende groepen worden afgewezen');
$accountVersion = $accountUsers->findByUsername('account-reader')['auth_version'];
$accountUsers->manage('account-reader', false, [$customAccountGroup]);
check($accountUsers->findByUsername('account-reader')['auth_version'] === $accountVersion + 1
    && !Permissions::allows($accountUsers->findByUsername('account-reader'), 'nzb.download'),
    'Deactiveren trekt rechten en bestaande sessieversie in');
$accountUsers->manage('account-reader', true, [$accountGroupIds['users']]);
rejects(static fn () => $accountUsers->manage('account-reader', false, [$accountGroupIds['moderators']],
    ['display_name' => '', 'signature' => '', 'email' => '']), 'Ongeldig beheerd profiel wordt expliciet geweigerd');
check($accountUsers->findByUsername('account-reader')['active']
    && array_column($accountUsers->memberships('account-reader'), 'system_key') === ['users'],
    'Beheerd profiel en status/groepen worden atomair opgeslagen of volledig teruggedraaid');
$accountUsers->deleteGroup($customAccountGroup);
$accountUsers->saveProfile('account-reader', 'Publieke naam', "Groeten\nLezer", 'private@example.invalid');
$accountUsers->saveProfile('account-admin', 'Publieke naam', '', '');
check($accountUsers->findByUsername('account-reader')['display_name'] === $accountUsers->findByUsername('account-admin')['display_name'],
    'Publieke namen hoeven niet uniek te zijn; login blijft eigen identiteit');
$accountKey = (new \NnSpotty\Newznab\ApiKeys($pdo))->rotate('account-reader');
check((new \NnSpotty\Newznab\ApiKeys($pdo))->authenticate($accountKey) === 'account-reader', 'Actief account kan API-key gebruiken');
$accountUsers->updatePassword('account-reader', 'synthetic-new-password', true);
check($accountUsers->findByUsername('account-reader')['must_change_password']
    && (new \NnSpotty\Newznab\ApiKeys($pdo))->authenticate($accountKey) === null,
    'Tijdelijk wachtwoord blokkeert API-toegang');
$accountUsers->delete('account-reader');
// Test-only cleanup: production repository correctly protects the remaining admin.
$pdo->exec("DELETE FROM app_users WHERE username = 'account-admin'");
