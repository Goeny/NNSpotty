<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SabSettings
{
    public static function render(array $settings, string $csrf, bool $central, bool $admin): string
    {
        $scope = $central ? 'central' : 'personal';
        $heading = $central ? 'Centrale SABnzbd-koppeling (alleen admins)' : 'Mijn SABnzbd-koppeling';
        $checked = $settings['enabled'] ? ' checked' : '';
        $keyHelp = $settings['api_key'] !== '' ? 'Key opgeslagen; leeg laten om te behouden.' : 'Volledige API-key, niet de beperkte NZB-key.';
        [$buttons, $delete] = DownloaderSettings::actions($scope, 'sabnzbd', $settings['api_key'] !== '', $csrf);
        $html = '<section class="card my-4" id="downloads-sabnzbd-' . $scope . '"><div class="card-header">' . $heading . '</div><div class="card-body">'
            . '<p>' . DownloaderSettings::notice($admin) . '</p><form method="post" action="/downloader">'
            . $csrf . '<input type="hidden" name="connection" value="' . $scope . '"><input type="hidden" name="client" value="sabnzbd"><input type="hidden" name="action" value="save">'
            . '<label class="form-label" for="sab-url-' . $scope . '">SABnzbd basisadres</label><input class="form-control mb-3" type="url" name="url" id="sab-url-' . $scope . '" value="' . h($settings['url']) . '" placeholder="https://sab.example.nl/sabnzbd" maxlength="1000" required>'
            . '<label class="form-label" for="sab-key-' . $scope . '">SABnzbd API-key</label><input class="form-control" type="password" id="sab-key-' . $scope . '" name="api_key" value="" autocomplete="new-password" maxlength="128">'
            . '<p class="form-text">' . $keyHelp . '</p><div class="form-check form-switch my-3"><input class="form-check-input" type="checkbox" id="sab-enabled-' . $scope . '" name="enabled" value="1"' . $checked . '><label class="form-check-label" for="sab-enabled-' . $scope . '">Koppeling inschakelen</label></div>'
            . $buttons . $delete
            . '<p class="form-text mt-3">De admin bepaalt de categorietoewijzing voor alle koppelingen. De ingestelde categorienamen moeten ook op jouw SABnzbd-host bestaan. De volledige externe wachtrij van deze koppeling wordt getoond, inclusief downloads die buiten NNSpotty zijn gestart.</p></div></section>';
        if ($central) {
            $html .= DownloaderSettings::mapping('sabnzbd', $settings['mappings'], $csrf);
        }
        return $html;
    }
}
