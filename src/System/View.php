<?php

declare(strict_types=1);

namespace NnSpotty\System;

final class View
{
    public static function render(array $status, string $csrf): string
    {
        $html = '<section class="activity-page"><h1 class="h3">Systeem bijwerken</h1><p>Geinstalleerd: <strong>'
            . h($status['current']) . ' &middot; ' . h($status['codename']) . '</strong></p>';
        if ($status['error'] !== null) $html .= '<div class="alert alert-danger" role="alert">' . h($status['error']) . '</div>';
        if ($status['recovery']) $html .= '<div class="alert alert-warning" role="alert">Een update is onderbroken of nog actief. '
            . 'Controleer het proces en var/update.log. Na herstel van de oorzaak kun je dezelfde release opnieuw installeren. '
            . 'Onderhoudsmodus blijft actief tot alle stappen zijn geslaagd.</div>';
        if ($status['release'] !== null) {
            $release = $status['release'];
            $html .= '<p>' . ($status['recovery'] ? 'Te herstellen release' : 'Laatste stabiele release') . ': <a href="' . h($release['url']) . '" target="_blank" rel="noopener noreferrer">'
                . h($release['name']) . ' (' . h($release['tag']) . ')</a></p>';
        } elseif ($status['error'] === null) {
            $html .= '<p class="alert alert-secondary">Er zijn nog geen gepubliceerde stabiele releases beschikbaar.</p>';
        }
        $html .= '<form method="post" action="/system/update" class="mb-4">' . $csrf
            . '<input type="hidden" name="action" value="check"><button class="btn btn-sm btn-outline-primary">Opnieuw controleren</button></form>'
            . '<div class="card"><div class="card-body"><h2 class="h5">Release installeren</h2>'
            . '<p>De updater volgt de gepubliceerde releases van de GitHub- of GitLab-origin van deze clone, niet de ontwikkelbranch. '
            . 'De exacte releasetag wordt opgehaald en uitgecheckt (detached HEAD). Daarna volgen Composer, migraties en database-/versiecontroles.</p>'
            . '<p>Vereist: een schone Git-werkmap, Git, dezelfde PHP CLI-versie als de webapp, Composer en schrijfrechten op de installatie en var. '
            . 'Composer-scripts en plugins worden niet uitgevoerd. Bewaar eerst een database- en bestandenback-up. '
            . 'Een fout na de codewisseling laat onderhoudsmodus actief; er is geen automatische database-rollback.</p>'
            . '<p class="small text-body-secondary">Het proces kan meerdere minuten duren. De webserver/proxy moet lange requests toestaan. '
            . 'Bij een verbroken verbinding kan de updater nog lopen: controleer eerst de status/log. Gebruik anders '
            . '<code>php bin/console system update</code>. Geef het recht Systeem bijwerken uitsluitend aan vertrouwde beheerders: dit voert servercode uit.</p>';
        if ($status['error'] === null && $status['release'] !== null && ($status['available'] || $status['recovery'])) {
            $html .= '<form method="post" action="/system/update" data-system-update>' . $csrf
                . '<input type="hidden" name="action" value="update"><input type="hidden" name="tag" value="' . h($status['release']['tag']) . '">'
                . '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="update-backup" name="backup" value="1" required>'
                . '<label class="form-check-label" for="update-backup">Ik heb een actuele back-up en wil deze release installeren.</label></div>'
                . '<button class="btn btn-primary" type="submit">Release installeren</button></form>';
        } elseif ($status['error'] === null && $status['release'] !== null) {
            $html .= '<p class="alert alert-success mb-0">Geen nieuwere release beschikbaar.</p>';
        }
        return $html . '<div class="alert mt-3" data-update-result role="status" hidden></div></div></div></section>';
    }
}
