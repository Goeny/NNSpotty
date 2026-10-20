<?php

declare(strict_types=1);

namespace NnSpotty\Users;

use NnSpotty\UI\SpotLayout;

final class View
{
    public static function profile(array $user, string $csrf): string
    {
        $name = h($user['display_name']);
        $signature = h($user['signature']);
        $email = h($user['email']);
        return <<<HTML
<section class="card my-4"><div class="card-header">Persoonlijk profiel</div><div class="card-body">
<p>Je loginnaam blijft vast. Je publieke naam hoeft niet uniek te zijn en bewijst geen identiteit. Wijzigingen gelden alleen voor nieuwe reacties.</p>
<form method="post" action="/account/profile">{$csrf}<input type="hidden" name="action" value="profile">
<label class="form-label" for="display_name">Publieke naam</label><input class="form-control mb-3" id="display_name" name="display_name" maxlength="80" value="{$name}" required>
<label class="form-label" for="signature">Handtekening</label><textarea class="form-control" id="signature" name="signature" maxlength="200" rows="3">{$signature}</textarea>
<p class="form-text">Maximaal 200 tekens / 3 regels; altijd onder nieuwe reacties gepubliceerd. Usenet-tekenset: gebruik emoticoncodes in plaats van emoji.</p>
<label class="form-label" for="profile_email">Priv&eacute;-e-mailadres (optioneel)</label><input class="form-control" id="profile_email" name="email" type="email" maxlength="254" value="{$email}">
<p class="form-text">Niet gepubliceerd. Alleen na jouw expliciete keuze voor Gravatar gebruikt; geen e-mailreset.</p>
<button class="btn btn-primary" type="submit">Profiel opslaan</button></form>
<hr><h2 class="h5">Profielfoto</h2>
<form method="post" action="/account/profile" enctype="multipart/form-data">{$csrf}<input type="hidden" name="action" value="avatar">
<label class="form-label" for="avatar_source">Bron</label><select class="form-select mb-3" id="avatar_source" name="source">
<option value="upload">Upload</option><option value="url">HTTPS-afbeeldings-URL</option><option value="gravatar">Gravatar</option><option value="remove">Profielfoto verwijderen</option></select>
<label class="form-label" for="avatar">Afbeelding uploaden</label><input class="form-control mb-3" id="avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
<label class="form-label" for="avatar_url">HTTPS-afbeeldings-URL</label><input class="form-control mb-3" id="avatar_url" name="avatar_url" type="url" placeholder="https://">
<div class="form-check"><input class="form-check-input" id="gravatar_consent" name="gravatar_consent" type="checkbox" value="1"><label class="form-check-label" for="gravatar_consent">Bij Gravatar: ik geef toestemming mijn e-mailhash met Gravatar te delen.</label></div>
<p class="form-text">Alleen de gekozen bron wordt gebruikt. Maximaal 2 MiB en 4096 &times; 4096 pixels. Wordt lokaal verwerkt en in de database opgeslagen; geen SVG. Profielfoto is lokaal, niet automatisch zichtbaar in Spotnet/Spotweb.</p>
<button class="btn btn-primary" type="submit">Profielfoto bijwerken</button></form>
<hr><a class="btn btn-outline-secondary" href="/account/password">Wachtwoord wijzigen</a>
</div></section>
HTML;
    }

    public static function password(array $user, string $csrf, ?string $status): string
    {
        $notice = $user['must_change_password'] ? '<div class="alert alert-warning">Wijzig eerst je tijdelijke wachtwoord. Andere functies en API zijn tot die tijd geblokkeerd.</div>' : '';
        $notice .= $status === null ? '' : '<div class="alert alert-info" role="alert">' . h($status) . '</div>';
        $form = '<div class="container"><section class="card my-4"><div class="card-header">Wachtwoord wijzigen</div><div class="card-body">' . $notice
            . '<form method="post" action="/account/profile">' . $csrf . '<input type="hidden" name="action" value="password">'
            . '<label class="form-label" for="current_password">Huidig wachtwoord</label><input class="form-control mb-3" id="current_password" name="current_password" type="password" autocomplete="current-password" required>'
            . '<label class="form-label" for="new_password">Nieuw wachtwoord (12-72 bytes)</label><input class="form-control mb-3" id="new_password" name="new_password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>'
            . '<label class="form-label" for="password_confirm">Herhaal nieuw wachtwoord</label><input class="form-control mb-3" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>'
            . '<button class="btn btn-primary" type="submit">Wachtwoord wijzigen</button></form></div></section></div>';
        return SpotLayout::render('Wachtwoord', $user['username'], (bool) $user['is_admin'], $csrf, $form);
    }

    public static function manage(array $user, string $csrf, array $pageData, array $groups, ?array $target,
        array $memberships, array $audit, ?string $status, string $search, int $page, string $theme, string $spotterSettings = ''): string
    {
        $html = '<div class="container-fluid"><h1 class="h3">Gebruikers en groepen</h1>';
        if ($status !== null) $html .= '<div class="alert alert-info" role="alert">' . h($status) . '</div>';
        $html .= '<nav class="nav nav-pills gap-2 settings-navigation" aria-label="Accountbeheer" data-account-navigation>';
        foreach (['users.manage' => ['accounts', 'Gebruikers'], 'groups.manage' => ['groups', 'Groepen en rechten'],
            'spotters.manage' => ['spotters', 'Spotterbeheer'], 'audit.view' => ['audit', 'Auditlog']] as $permission => [$anchor, $label]) {
            if (Permissions::allows($user, $permission)) $html .= '<a class="nav-link" href="#' . $anchor . '">' . $label . '</a>';
        }
        $html .= '</nav>';
        if (Permissions::allows($user, 'users.manage')) {
            $html .= '<div data-account-panel="accounts" id="accounts"><section class="card my-4"><div class="card-header">Gebruikers</div><div class="card-body">'
                . '<form method="get" action="/manage" class="d-flex gap-2"><input class="form-control" name="q" aria-label="Zoek loginnaam" maxlength="100" value="' . h($search) . '"><button class="btn btn-primary">Zoeken</button></form>'
                . '<p class="form-text">Totaal: ' . $pageData['total'] . '</p><div class="table-responsive"><table class="table"><thead><tr><th>Loginnaam</th><th>Publieke naam</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach ($pageData['rows'] as $row) {
                $html .= '<tr><td>' . h($row['username']) . '</td><td>' . h($row['display_name']) . '</td><td>'
                    . ($row['active'] ? 'Actief' : 'Inactief') . '</td><td><a class="btn btn-sm btn-outline-secondary" href="/manage?user=' . rawurlencode($row['username']) . '">Bewerken</a></td></tr>';
            }
            $html .= '</tbody></table></div><nav aria-label="Gebruikerspagina">';
            if ($page > 1) $html .= '<a class="btn btn-outline-secondary me-2" href="/manage?' . h(http_build_query(['q' => $search, 'page' => $page - 1])) . '">Vorige</a>';
            if ($page * 50 < $pageData['total']) $html .= '<a class="btn btn-outline-secondary" href="/manage?' . h(http_build_query(['q' => $search, 'page' => $page + 1])) . '">Volgende</a>';
            $html .= '</nav><hr><h2 class="h5">Gebruiker aanmaken</h2><form method="post" action="/manage">' . $csrf
                . '<input type="hidden" name="action" value="create"><label class="form-label" for="create_username">Loginnaam</label><input class="form-control mb-3" id="create_username" name="username" maxlength="191" required>'
                . '<label class="form-label" for="create_password">Tijdelijk wachtwoord (12-72 bytes)</label><input class="form-control mb-3" id="create_password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>'
                . '<p class="form-text">Nieuwe gebruiker start in Gebruikers. Het tijdelijke wachtwoord moet bij eerste login gewijzigd worden; deel het via een veilig kanaal.</p>'
                . '<button class="btn btn-primary">Gebruiker aanmaken</button></form></div></section>';
        }
        if ($target !== null) {
            $name = h($target['username']);
            $html .= '<section class="card my-4"><div class="card-header">Bewerken: ' . $name . '</div><div class="card-body">'
                . '<form method="post" action="/manage">' . $csrf . '<input type="hidden" name="action" value="save"><input type="hidden" name="username" value="' . $name . '">'
                . '<label class="form-label" for="edit_name">Publieke naam</label><input class="form-control mb-3" id="edit_name" name="display_name" value="' . h($target['display_name']) . '" maxlength="80" required>'
                . '<label class="form-label" for="edit_signature">Handtekening</label><textarea class="form-control mb-3" id="edit_signature" name="signature" maxlength="200" rows="3">' . h($target['signature']) . '</textarea>'
                . '<label class="form-label" for="edit_email">Priv&eacute;-e-mailadres</label><input class="form-control mb-3" id="edit_email" name="email" type="email" value="' . h($target['email']) . '">'
                . '<div class="form-check mb-3"><input class="form-check-input" id="edit_active" name="active" type="checkbox" value="1"' . ($target['active'] ? ' checked' : '') . '><label class="form-check-label" for="edit_active">Actief</label></div>';
            if (Permissions::allows($user, 'groups.manage')) {
                foreach ($groups as $group) {
                    if ($group['system_key'] === 'admins' && !$user['is_admin']) continue;
                    $id = (int) $group['id'];
                    $checked = in_array($id, array_map('intval', array_column($memberships, 'id')), true);
                    $html .= self::checkbox('group_' . $id, $group['name'], $checked);
                }
            }
            $html .= '<button class="btn btn-primary my-3">Account opslaan</button></form><hr>'
                . '<form method="post" action="/manage">' . $csrf . '<input type="hidden" name="action" value="reset-password"><input type="hidden" name="username" value="' . $name . '">'
                . '<label class="form-label" for="reset_password">Nieuw tijdelijk wachtwoord</label><input class="form-control mb-3" id="reset_password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><button class="btn btn-warning">Wachtwoord resetten en sessies intrekken</button></form><hr>'
                . self::deleteForm('/manage', $csrf, 'delete', 'username', $target['username'], 'Gebruiker definitief verwijderen',
                    'Profiel, voorkeuren, persoonlijke sleutels en gekoppelde gegevens worden verwijderd. Bestaande Usenet-publicaties blijven bestaan.')
                . '</div></section>';
        }
        if (Permissions::allows($user, 'users.manage')) $html .= '</div>';
        if (Permissions::allows($user, 'groups.manage')) {
            $html .= '<div data-account-panel="groups" id="groups">';
            foreach (array_merge($groups, [['id' => null, 'name' => '', 'system_key' => null, 'permissions' => []]]) as $group) {
                $html .= '<section class="card my-4"><div class="card-header">' . ($group['id'] === null ? 'Groep aanmaken' : h($group['name'])) . '</div><div class="card-body">';
                if ($group['system_key'] === 'admins') {
                    $html .= '<p>Beschermde groep: alle rechten. Laatste actieve beheerder blijft behouden.</p></div></section>';
                    continue;
                }
                $id = $group['id'] === null ? 'new' : (string) $group['id'];
                $html .= '<form method="post" action="/manage">' . $csrf . '<input type="hidden" name="action" value="group-save">'
                    . ($id === 'new' ? '' : '<input type="hidden" name="group_id" value="' . h($id) . '">')
                    . '<label class="form-label" for="group_name_' . h($id) . '">Groepsnaam</label><input class="form-control mb-3" id="group_name_' . h($id) . '" name="name" maxlength="80" value="' . h($group['name']) . '" required>';
                foreach (Permissions::LABELS as $key => $label) {
                    $html .= self::checkbox('permission_' . str_replace('.', '_', $key), $label, in_array($key, $group['permissions'], true), $id);
                }
                $html .= '<button class="btn btn-primary my-3">Groep opslaan</button></form>';
                if ($group['id'] !== null && $group['system_key'] === null) {
                    $html .= self::deleteForm('/manage', $csrf, 'group-delete', 'group_id', $id, 'Groep verwijderen', 'Alleen lege eigen groepen kunnen worden verwijderd.');
                }
                $html .= '</div></section>';
            }
            $html .= '</div>';
        }
        if (Permissions::allows($user, 'spotters.manage')) {
            $html .= '<div data-account-panel="spotters" id="spotters">' . $spotterSettings . '</div>';
        }
        if (Permissions::allows($user, 'audit.view')) {
            $html .= '<div data-account-panel="audit" id="audit"><section class="card my-4"><div class="card-header">Auditlog (laatste 50)</div><div class="card-body"><p>Alleen acties en doelen; configuratiepayloads worden hier niet getoond.</p><div class="table-responsive"><table class="table"><thead><tr><th>Tijd UTC</th><th>Actor</th><th>Actie</th><th>Doel</th></tr></thead><tbody>';
            foreach ($audit as $row) $html .= '<tr><td>' . h($row['created_at']) . '</td><td>' . h($row['actor_username']) . '</td><td>' . h($row['action']) . '</td><td>' . h($row['target_id']) . '</td></tr>';
            $html .= '</tbody></table></div></div></section></div>';
        }
        $html .= '</div>';
        return SpotLayout::render('Gebruikers en groepen', $user['username'], (bool) $user['is_admin'], $csrf, $html, theme: $theme);
    }

    private static function checkbox(string $name, string $label, bool $checked, string $suffix = ''): string
    {
        return '<div class="form-check"><input class="form-check-input" id="' . h($name . $suffix) . '" type="checkbox" name="' . h($name)
            . '" value="1"' . ($checked ? ' checked' : '') . '><label class="form-check-label" for="' . h($name . $suffix) . '">' . h($label) . '</label></div>';
    }

    private static function deleteForm(string $url, string $csrf, string $action, string $key, string $value, string $label, string $warning): string
    {
        $id = 'confirm-' . substr(hash('sha256', $action . $value), 0, 12);
        $form = '<form method="post" action="' . h($url) . '">' . $csrf . '<input type="hidden" name="action" value="' . h($action)
            . '"><input type="hidden" name="' . h($key) . '" value="' . h($value) . '"><input type="hidden" name="confirm" value="1">'
            . '<button class="btn btn-danger" type="submit">' . h($label) . '</button></form>';
        return '<button class="btn btn-outline-danger" type="button" data-confirm-dialog="' . $id . '">' . h($label) . '</button>'
            . '<dialog class="key-confirm card p-4" id="' . $id . '"><h2 class="h5">' . h($label) . '?</h2><p>' . h($warning) . '</p>'
            . $form . '<button class="btn btn-secondary mt-2" type="button" data-close-confirm>Annuleren</button></dialog>'
            . '<noscript><div class="alert alert-warning">' . h($warning) . $form . '</div></noscript>';
    }
}
