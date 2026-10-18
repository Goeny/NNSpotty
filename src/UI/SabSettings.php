<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Downloader\SabRepository;

final class SabSettings
{
    public static function render(array $settings, string $csrf, bool $central, bool $admin): string
    {
        $scope = $central ? 'central' : 'personal';
        $heading = $central ? 'Centrale SABnzbd-koppeling (alleen admins)' : 'Mijn SABnzbd-koppeling';
        $checked = $settings['enabled'] ? ' checked' : '';
        $keyHelp = $settings['api_key'] !== '' ? 'Key opgeslagen; leeg laten om te behouden.' : 'Volledige API-key, niet de beperkte NZB-key.';
        $notice = $admin ? 'Als admin mag je ook interne hosts gebruiken. TLS-certificaten worden altijd gecontroleerd.'
            : 'Alleen publiek bereikbare hosts. Localhost, LAN, private en gereserveerde IP-adressen zijn niet toegestaan, ook niet via DNS of redirects. Gebruik HTTPS om je key te beschermen.';
        $html = '<section class="card my-4" id="downloads"><div class="card-header">' . $heading . '</div><div class="card-body">'
            . '<p>' . $notice . '</p><form method="post" action="/downloader" data-sab-settings>'
            . $csrf . '<input type="hidden" name="connection" value="' . $scope . '"><input type="hidden" name="action" value="save">'
            . '<label class="form-label" for="sab-url-' . $scope . '">SABnzbd basisadres</label><input class="form-control mb-3" type="url" name="url" id="sab-url-' . $scope . '" value="' . h($settings['url']) . '" placeholder="https://sab.example.nl/sabnzbd" maxlength="1000" required>'
            . '<label class="form-label" for="sab-key-' . $scope . '">SABnzbd API-key</label><input class="form-control" type="password" id="sab-key-' . $scope . '" name="api_key" value="" autocomplete="new-password" maxlength="128">'
            . '<p class="form-text">' . $keyHelp . '</p><div class="form-check form-switch my-3"><input class="form-check-input" type="checkbox" id="sab-enabled-' . $scope . '" name="enabled" value="1"' . $checked . '><label class="form-check-label" for="sab-enabled-' . $scope . '">Koppeling inschakelen</label></div>'
            . '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">SABnzbd opslaan</button><button class="btn btn-outline-secondary" type="button" data-sab-test="' . $scope . '">Opgeslagen verbinding testen</button>'
            . '<button class="btn btn-outline-secondary" type="button" data-sab-open="' . $scope . '">Wachtrij bekijken</button></div><div class="mt-3" role="status" data-sab-test-result></div></form>';
        if ($settings['api_key'] !== '') {
            $html .= '<details class="mt-3"><summary>Koppeling verwijderen</summary><p>Dit verwijdert de opgeslagen host en key, niet de bestaande downloads in SABnzbd of de verzendhistorie.</p>'
                . '<form method="post" action="/downloader">' . $csrf . '<input type="hidden" name="connection" value="' . $scope . '"><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger" type="submit">Koppeling definitief verwijderen</button></form></details>';
        }
        $html .= '<p class="form-text mt-3">De admin bepaalt de categorietoewijzing voor alle koppelingen. De ingestelde categorienamen moeten ook op jouw SABnzbd-host bestaan. De volledige externe wachtrij van deze koppeling wordt getoond, inclusief downloads die buiten NNSpotty zijn gestart.</p></div></section>';
        if ($central) {
            $html .= '<section class="card my-4"><div class="card-header">SABnzbd categorietoewijzing</div><form class="card-body" method="post" action="/downloader">'
                . $csrf . '<input type="hidden" name="connection" value="central"><input type="hidden" name="action" value="mapping">'
                . '<p>Exacte SABnzbd-categorienaam per spottype. Leeg gebruikt de standaardcategorie van SABnzbd. Deze mapping geldt ook voor persoonlijke koppelingen; er is geen automatische fallback naar een andere categorie bij een fout.</p><div class="row g-3">';
            foreach (SabRepository::TYPES as $index => $type) {
                $html .= '<div class="col-12 col-md-6"><label class="form-label" for="sab-category-' . $index . '">' . h($type)
                    . '</label><input class="form-control" id="sab-category-' . $index . '" name="category_' . $index . '" value="' . h($settings['mappings'][$type] ?? '') . '" maxlength="100"></div>';
            }
            $html .= '</div><button class="btn btn-primary mt-3" type="submit">Categorieen opslaan</button></form></section>';
        }
        return $html;
    }
}
