<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Downloader\ConnectionRepository;
use NnSpotty\Downloader\Targets;

final class DownloaderSettings
{
    /** @param array{targets: list<array{id: string, label: string, client: string, enabled: bool}>, default: string} $overview */
    public static function defaults(array $overview, string $csrf): string
    {
        $enabled = array_values(array_filter($overview['targets'], static fn (array $target): bool => $target['enabled']));
        $html = '<section class="card my-4" id="download-default"><div class="card-header">Standaard downloadclient</div><div class="card-body">';
        if (count($enabled) < 2) {
            $current = $enabled === [] ? 'Er is nog geen downloadclient ingeschakeld. Stel hieronder SABnzbd of NZBGet in.'
                : h($enabled[0]['label']) . ' is je enige ingeschakelde koppeling en wordt automatisch gebruikt.';
            return $html . '<p class="mb-0">' . $current . ' Met meerdere ingeschakelde koppelingen kies je hier de standaard; bij verzenden kun je altijd eenmalig een andere koppeling kiezen.</p></div></section>';
        }
        $html .= '<form method="post" action="/downloader" class="row g-2 align-items-end">' . $csrf
            . '<input type="hidden" name="action" value="default"><div class="col-12 col-md-8"><label class="form-label" for="download-default-target">Verzend NZB\'s standaard naar</label>'
            . '<select class="form-select" id="download-default-target" name="target">';
        foreach ($enabled as $target) {
            $html .= '<option value="' . h($target['id']) . '"' . ($target['id'] === $overview['default'] ? ' selected' : '') . '>' . h($target['label']) . '</option>';
        }
        return $html . '</select></div><div class="col-12 col-md-4"><button class="btn btn-primary w-100" type="submit">Standaard opslaan</button></div></form>'
            . '<p class="form-text mt-2 mb-0">De verzendknop gebruikt deze koppeling. Via het pijltje naast de knop kies je eenmalig een andere.</p></div></section>';
    }

    /** @param array<string, string> $mappings */
    public static function mapping(string $client, array $mappings, string $csrf): string
    {
        $label = Targets::CLIENTS[$client];
        $empty = $client === 'nzbget' ? 'Leeg verstuurt zonder categorie (NZBGet-standaard).' : 'Leeg gebruikt de standaardcategorie van SABnzbd.';
        $html = '<section class="card my-4"><div class="card-header">' . $label . ' categorietoewijzing</div><form class="card-body" method="post" action="/downloader">'
            . $csrf . '<input type="hidden" name="connection" value="central"><input type="hidden" name="client" value="' . $client . '"><input type="hidden" name="action" value="mapping">'
            . '<p>Exacte ' . $label . '-categorienaam per spottype. ' . $empty . ' Deze mapping geldt ook voor persoonlijke ' . $label . '-koppelingen; er is geen automatische fallback naar een andere categorie bij een fout.</p><div class="row g-3">';
        $prefix = $client === 'nzbget' ? 'nzbget' : 'sab';
        foreach (ConnectionRepository::TYPES as $index => $type) {
            $html .= '<div class="col-12 col-md-6"><label class="form-label" for="' . $prefix . '-category-' . $index . '">' . h($type)
                . '</label><input class="form-control" id="' . $prefix . '-category-' . $index . '" name="category_' . $index . '" value="' . h($mappings[$type] ?? '') . '" maxlength="100"></div>';
        }
        return $html . '</div><button class="btn btn-primary mt-3" type="submit">' . $label . '-categorieen opslaan</button></form></section>';
    }

    public static function notice(bool $admin): string
    {
        return $admin ? 'Als admin mag je ook interne hosts gebruiken. TLS-certificaten worden altijd gecontroleerd.'
            : 'Alleen publiek bereikbare hosts. Localhost, LAN, private en gereserveerde IP-adressen zijn niet toegestaan, ook niet via DNS of redirects. Gebruik HTTPS om je inloggegevens te beschermen.';
    }

    public static function actions(string $scope, string $client, bool $stored, string $csrf): array
    {
        $label = Targets::CLIENTS[$client];
        $buttons = '<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">' . $label . ' opslaan</button>'
            . '<button class="btn btn-outline-secondary" type="button" data-download-test="' . $scope . '" data-download-client="' . $client . '">Opgeslagen verbinding testen</button>'
            . '<button class="btn btn-outline-secondary" type="button" data-download-open="' . $scope . ':' . $client . '">Wachtrij bekijken</button></div><div class="mt-3" role="status" data-download-test-result></div></form>';
        $delete = '';
        if ($stored) {
            $delete = '<details class="mt-3"><summary>Koppeling verwijderen</summary><p>Dit verwijdert de opgeslagen host en inloggegevens, niet de bestaande downloads in ' . $label . ' of de verzendhistorie.</p>'
                . '<form method="post" action="/downloader">' . $csrf . '<input type="hidden" name="connection" value="' . $scope . '"><input type="hidden" name="client" value="' . $client . '"><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger" type="submit">Koppeling definitief verwijderen</button></form></details>';
        }
        return [$buttons, $delete];
    }
}
