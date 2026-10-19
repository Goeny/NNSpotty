<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use NnSpotty\UI\SpotPresentation;

final class View
{
    public static function settings(Repository $repo, string $csrf, ?string $username = null): string
    {
        $settings = $repo->settings();
        $html = '<section class="card my-4" id="metadata"><div class="card-header">TheTVDB metadata</div><div class="card-body">';
        if ($username !== null) {
            $html .= '<p>Poster, fanart, synopsis en beschikbare cast/crew bij films en series. De beheerder beheert de centrale API-key en gedeelde matches. Bij openen van een detailpagina wordt de titel met TheTVDB gedeeld; afbeeldingen laden rechtstreeks van TheTVDB.</p>'
                . '<p>' . ($settings['enabled'] ? 'TVDB is globaal ingeschakeld.' : 'TVDB is globaal uitgeschakeld; je voorkeur blijft bewaard.') . '</p>'
                . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="preference">'
                . self::switch('enabled', 'TVDB-metadata voor mij ophalen en tonen', $repo->preference($username))
                . '<button class="btn btn-primary mt-3" type="submit">Metadatavoorkeur opslaan</button></form>';
        } else {
            $html .= '<p>Centrale API V4-toegang. Iedereen schakelt metadata zelf in onder Voorkeuren. Alleen admins corrigeren gedeelde matches. Gebruik een key die volgens jouw TheTVDB-licentie voor deze installatie mag worden gebruikt; sommige keys vereisen een abonnee-PIN.</p>'
                . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="settings">'
                . self::switch('enabled', 'TVDB-metadata globaal toestaan', (bool) $settings['enabled'])
                . '<label for="tvdb-key" class="form-label mt-3">TVDB API-key</label><input class="form-control" type="password" id="tvdb-key" name="api_key" autocomplete="new-password" maxlength="255">'
                . '<label for="tvdb-pin" class="form-label mt-3">Abonnee-PIN (indien vereist)</label><input class="form-control" type="password" id="tvdb-pin" name="pin" autocomplete="new-password" maxlength="255">'
                . '<p class="form-text">Lege velden behouden bestaande geheimen. API-key ' . ($settings['api_key'] === '' ? 'nog niet ingesteld' : 'opgeslagen')
                . '; PIN ' . ($settings['pin'] === '' ? 'niet ingesteld' : 'opgeslagen') . '. Keys/tokens worden herstelbaar in de database opgeslagen, niet teruggetoond. Bescherm backups.</p>'
                . self::switch('clear_pin', 'Opgeslagen PIN verwijderen', false)
                . '<label class="form-label mt-3" for="tvdb-language">Metadatataal</label><select class="form-select" id="tvdb-language" name="language">'
                . '<option value="nld"' . ($settings['language'] === 'nld' ? ' selected' : '') . '>Nederlands, daarna Engels</option>'
                . '<option value="eng"' . ($settings['language'] === 'eng' ? ' selected' : '') . '>Engels</option></select>'
                . '<button class="btn btn-primary mt-3" type="submit">TVDB-instellingen opslaan</button></form>'
                . '<form method="post" action="/metadata" class="mt-3">' . $csrf . '<input type="hidden" name="action" value="test">'
                . '<button class="btn btn-outline-secondary" type="submit">Opgeslagen TVDB-toegang testen</button></form>'
                . '<p class="form-text mt-3">Alleen overtuigende titels worden automatisch gekoppeld; films vereisen ook een overeenkomend jaar. Onduidelijke titels blijven origineel. Data wordt zeven dagen gecachet, zoekresultaten en ontbrekende matches een dag; fouten vijf minuten. Geen massale backfill tijdens retrieval.</p>';
        }
        return $html . '</div></section>';
    }

    private static function switch(string $name, string $label, bool $checked): string
    {
        return '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . $name . '" id="tvdb-' . $name
            . '" value="1"' . ($checked ? ' checked' : '') . '><label class="form-check-label" for="tvdb-' . $name . '">' . h($label) . '</label></div>';
    }

    public static function controls(array $spot, bool $admin, string $csrf): string
    {
        $release = Release::parse($spot);
        if ($release === null) return '';
        $context = $csrf . '<input type="hidden" name="id" value="' . h($spot['id']) . '">';
        $html = '<dialog id="metadata-drawer" class="download-drawer metadata-drawer" aria-labelledby="metadata-title" data-tvdb data-spot="' . h($spot['id']) . '">'
            . '<div class="download-toolbar"><h2 class="h5 mb-0" id="metadata-title">TVDB link</h2>'
            . '<button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-close aria-label="TVDB-paneel sluiten">Sluiten &times;</button></div>'
            . '<div class="download-body">'
            . '<form data-tvdb-load method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="load"><p class="small text-body-secondary mb-2" role="status" data-tvdb-status>TVDB-metadata wordt opgehaald; de spot blijft hieronder beschikbaar.</p>'
            . '<button class="btn btn-sm btn-outline-secondary" type="submit">Metadata opnieuw controleren</button></form>';
        if ($admin) {
            $html .= '<section class="mt-3" aria-label="TVDB-match kiezen / wijzigen (admin)"><h3 class="h6">Handmatig koppelen (admin)</h3>'
                . '<form data-tvdb-search class="mt-3" method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="search"><label class="form-label" for="tvdb-query">Titel zoeken</label>'
                . '<input class="form-control" id="tvdb-query" name="query" value="' . h($release['query']) . '" required minlength="2" maxlength="200">'
                . '<label class="form-label mt-2" for="tvdb-type">Type</label><select class="form-select" id="tvdb-type" name="type"><option value="movie">Film</option>'
                . '<option value="series"' . ($release['type'] === 'series' ? ' selected' : '') . '>Serie</option></select>'
                . '<button class="btn btn-sm btn-primary mt-2" type="submit">Zoeken bij TVDB</button></form>'
                . '<form data-tvdb-choice class="mt-3" method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="choose"><div data-tvdb-candidates></div>'
                . '<p class="small text-body-secondary">Optioneel seizoen en aflevering voor een serie. Leeg koppelt de serie als geheel; pakketten niet als losse aflevering koppelen. Correcties gelden voor iedereen.</p>'
                . '<div class="row g-2"><div class="col"><label class="form-label" for="tvdb-season">Seizoen</label><input class="form-control" type="number" min="0" max="999" name="season" id="tvdb-season" value="' . h((string) ($release['season'] ?? '')) . '"></div>'
                . '<div class="col"><label class="form-label" for="tvdb-episode">Aflevering</label><input class="form-control" type="number" min="1" max="9999" name="episode" id="tvdb-episode" value="' . h((string) ($release['episode'] ?? '')) . '"></div></div>'
                . '<button class="btn btn-sm btn-primary mt-3" type="submit">Geselecteerde match opslaan</button></form>'
                . '<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-reset>Opnieuw automatisch matchen</button>'
                . '<button class="btn btn-sm btn-outline-danger" type="button" data-tvdb-block>Geen metadata voor deze spot</button></div></section>';
        } else {
            $html .= '<p class="small text-body-secondary mt-3">Alleen een admin kan een gedeelde TVDB-match kiezen, wijzigen of uitschakelen.</p>';
        }
        return $html . '</div></dialog><noscript><p>JavaScript is nodig voor TVDB-verrijking. De oorspronkelijke spot blijft hieronder volledig bruikbaar.</p></noscript>';
    }

    public static function link(): string
    {
        return '<button class="btn btn-sm btn-link metadata-link" type="button" data-tvdb-open aria-controls="metadata-drawer" aria-haspopup="dialog">TVDB link</button>';
    }

    public static function hero(array $spot, array $metadata, string $actions, bool $images, bool $preview = false): string
    {
        $cover = $metadata['poster'];
        if ($metadata['type'] === 'episode' && $metadata['still'] !== '') $cover = $metadata['still'];
        $imageSpot = $spot;
        if ($cover !== '') {
            $imageSpot['image_segments'] = [];
            $imageSpot['image_url'] = $cover;
        }
        $backgroundSpot = $spot;
        if ($metadata['fanart'] !== '') {
            $backgroundSpot['image_segments'] = [];
            $backgroundSpot['image_url'] = $metadata['fanart'];
        } else {
            $backgroundSpot = $imageSpot;
        }
        $heading = $preview ? 'h2' : 'h1';
        $episodeHeading = $preview ? 'h3' : 'h2';
        $copy = '<p class="text-body-secondary mb-2">TheTVDB &middot; ' . h(['movie' => 'Film', 'series' => 'Serie', 'episode' => 'Aflevering'][$metadata['type']]) . '</p>'
            . '<' . $heading . '>' . h($metadata['title']) . '</' . $heading . '>';
        if ($metadata['episode_title'] !== '') $copy .= '<' . $episodeHeading . ' class="h4">' . h($metadata['episode_title']) . '</' . $episodeHeading . '>';
        if ($metadata['season'] !== null && !$metadata['episode_missing']) $copy .= '<p>Seizoen ' . (int) $metadata['season'] . ($metadata['episode'] === null ? '' : ' &middot; Aflevering ' . (int) $metadata['episode']) . '</p>';
        $facts = array_filter([$metadata['date'], $metadata['runtime'] === null ? '' : $metadata['runtime'] . ' min', implode(', ', $metadata['genres'])]);
        $copy .= '<p class="metadata-facts">' . h(implode(' · ', $facts)) . '</p>';
        if ($metadata['overview'] !== '') $copy .= '<p class="metadata-overview">' . nl2br(h($metadata['overview'])) . '</p>';
        if ($metadata['episode_missing']) $copy .= '<p class="small text-body-secondary">Serie gevonden; seizoen ' . (int) $metadata['season']
            . ', aflevering ' . (int) $metadata['requested_episode']
            . ' is niet uniek beschikbaar in de officiële TVDB-nummering. Seizoen en aflevering zijn uit de releasetitel, niet door TVDB bevestigd. Er worden alleen seriemetadata getoond; corrigeer de koppeling via TVDB link.</p>';
        $copy .= \NnSpotty\UI\SpotReleaseQuality::render($spot['title']);
        $typePath = $metadata['type'] === 'movie' ? 'movies/' : ($metadata['type'] === 'episode' ? 'episodes/' : 'series/');
        $copy .= '<a class="metadata-attribution small" href="https://thetvdb.com/dereferrer/' . $typePath . (int) $metadata['id']
            . '" target="_blank" rel="noopener noreferrer"><span>Metadata van TheTVDB</span>'
            . '<img class="tvdb-logo-dark" src="/assets/images/tvdb-dark.png" width="74" height="40" alt="" aria-hidden="true">'
            . '<img class="tvdb-logo-light" src="/assets/images/tvdb-light.png" width="74" height="40" alt="" aria-hidden="true"></a>';
        return '<section class="spot-hero metadata-hero' . ($metadata['type'] === 'episode' ? ' episode-hero' : '') . '">'
            . SpotPresentation::backdrop($backgroundSpot, 'hero-backdrop', $images)
            . '<div class="hero-actions" role="group" aria-label="Spotacties">' . $actions . '</div>'
            . '<div class="hero-poster">' . SpotPresentation::image($imageSpot, false, $images) . '</div><div class="hero-copy">' . $copy . '</div></section>'
            . '<p class="small text-body-secondary metadata-release">Oorspronkelijke spottitel: <strong>' . h($spot['title']) . '</strong></p>';
    }

    public static function people(array $metadata, bool $images): string
    {
        $html = '';
        if ($metadata['people'] !== []) {
            $html .= '<section class="metadata-people mb-4" aria-label="Cast en crew"><h2 class="h4">Cast &amp; Crew</h2><div class="people-list">';
            foreach ($metadata['people'] as $person) {
                $html .= '<article class="person-card">';
                if ($images) {
                    $html .= '<img src="' . h($person['image'] !== '' ? $person['image'] : '/assets/images/placeholder-small.png')
                        . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src=\'/assets/images/placeholder-small.png\'">';
                }
                $html .= '<strong>' . h($person['name']) . '</strong><span>' . h($person['role']) . '</span><small>' . h($person['type']) . '</small></article>';
            }
            $html .= '</div></section>';
        }
        if ($metadata['companies'] !== []) {
            $html .= '<section class="card mb-3"><div class="card-header">Studio / netwerk</div><div class="card-body d-flex flex-wrap gap-3">';
            foreach ($metadata['companies'] as $company) $html .= '<span>' . h($company['name']) . '</span>';
            $html .= '</div></section>';
        }
        return $html;
    }

    public static function message(array $result): string
    {
        return match ($result['state']) {
            'matched' => ($result['manual'] ? 'Handmatig gekoppeld aan TheTVDB.' : 'Automatisch gekoppeld aan TheTVDB.'),
            'ambiguous' => 'Geen overtuigende unieke TVDB-match. De oorspronkelijke spot wordt getoond; een admin kan de match kiezen.',
            'unmatched' => 'Geen TVDB-resultaat gevonden. De oorspronkelijke spot wordt getoond.',
            'blocked' => 'Een admin heeft metadata voor deze spot uitgeschakeld.',
            'busy' => 'TVDB-metadata wordt al opgehaald. Controleer over enkele seconden opnieuw.',
            'error' => $result['message'],
            default => 'Deze spot heeft geen herkenbare film- of serietitel.',
        };
    }
}
