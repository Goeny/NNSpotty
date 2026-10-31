<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class NzbgetSettings
{
    public static function render(array $settings, string $csrf, bool $central, bool $admin): string
    {
        $scope = $central ? 'central' : 'personal';
        $heading = $central ? 'Centrale NZBGet-koppeling (alleen admins)' : 'Mijn NZBGet-koppeling';
        $checked = $settings['enabled'] ? ' checked' : '';
        $stored = $settings['control_password'] !== '';
        $passwordHelp = $stored ? 'Wachtwoord opgeslagen; leeg laten om te behouden.'
            : 'ControlPassword, of RestrictedPassword/AddPassword voor beperktere rechten. Een AddUsername kan alleen verzenden, niet de wachtrij tonen.';
        [$buttons, $delete] = DownloaderSettings::actions($scope, 'nzbget', $stored, $csrf);
        $html = '<section class="card my-4" id="downloads-nzbget-' . $scope . '"><div class="card-header">' . $heading . '</div><div class="card-body">'
            . '<p>' . DownloaderSettings::notice($admin) . '</p><form method="post" action="/downloader">'
            . $csrf . '<input type="hidden" name="connection" value="' . $scope . '"><input type="hidden" name="client" value="nzbget"><input type="hidden" name="action" value="save">'
            . '<label class="form-label" for="nzbget-url-' . $scope . '">NZBGet basisadres</label><input class="form-control mb-3" type="url" name="url" id="nzbget-url-' . $scope . '" value="' . h($settings['url']) . '" placeholder="https://nzbget.example.nl:6789" maxlength="1000" required>'
            . '<label class="form-label" for="nzbget-user-' . $scope . '">NZBGet gebruikersnaam</label><input class="form-control mb-3" id="nzbget-user-' . $scope . '" name="control_username" value="' . h($settings['control_username']) . '" autocomplete="off" maxlength="100" required>'
            . '<label class="form-label" for="nzbget-password-' . $scope . '">NZBGet wachtwoord</label><input class="form-control" type="password" id="nzbget-password-' . $scope . '" name="control_password" value="" autocomplete="new-password" maxlength="255">'
            . '<p class="form-text">' . $passwordHelp . '</p><div class="form-check form-switch my-3"><input class="form-check-input" type="checkbox" id="nzbget-enabled-' . $scope . '" name="enabled" value="1"' . $checked . '><label class="form-check-label" for="nzbget-enabled-' . $scope . '">Koppeling inschakelen</label></div>'
            . $buttons . $delete
            . '<p class="form-text mt-3">Gebruik het basisadres zonder /jsonrpc en zonder inloggegevens in de URL; NNSpotty gebruikt HTTP Basic-authenticatie. NZBGet 16 of nieuwer is vereist. De admin bepaalt de NZBGet-categorietoewijzing; de namen moeten op jouw NZBGet-host bestaan.</p></div></section>';
        if ($central) {
            $html .= DownloaderSettings::mapping('nzbget', $settings['mappings'], $csrf);
        }
        return $html;
    }
}
