<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use NnSpotty\UI\SpotPresentation;

final class View
{
    public static function settings(Repository $repo, string $csrf, ?string $username = null): string
    {
        $settings = $repo->settings();
        if ($username !== null) {
            $enabled = $repo->providerEnabled('tvdb') || $repo->providerEnabled('tmdb');
            return '<section class="card my-4" id="metadata"><div class="card-header">Film- en seriemetadata (TVDB/TMDB)</div><div class="card-body">'
                . '<p>Poster, fanart, synopsis, scores, trailers en beschikbare cast/crew bij films en series. De beheerder kiest per type de primaire provider (TheTVDB of TMDB) en beheert de centrale API-toegang en gedeelde matches. Bij openen van een detailpagina wordt de titel met de provider gedeeld; afbeeldingen laden rechtstreeks van TheTVDB/TMDB en trailers pas na een klik van YouTube/Vimeo.</p>'
                . '<p>' . ($enabled ? 'Film- en seriemetadata is globaal ingeschakeld.' : 'Film- en seriemetadata is globaal uitgeschakeld; je voorkeur blijft bewaard.') . '</p>'
                . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="preference">'
                . self::switch('enabled', 'Film- en seriemetadata (TVDB/TMDB) voor mij ophalen en tonen', $repo->preference($username))
                . '<button class="btn btn-primary mt-3" type="submit">Metadatavoorkeur opslaan</button></form></div></section>';
        }
        return '<section class="card my-4" id="tvdb-settings"><div class="card-header">TheTVDB</div><div class="card-body">'
            . '<p>Centrale API V4-toegang. Iedereen schakelt metadata zelf in onder Voorkeuren. Gebruik een key die volgens jouw TheTVDB-licentie voor deze installatie mag worden gebruikt; sommige keys vereisen een abonnee-PIN.</p>'
            . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="settings">'
            . self::switch('enabled', 'TheTVDB inschakelen', (bool) $settings['enabled'])
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
            . '<button class="btn btn-outline-secondary" type="submit">Opgeslagen TVDB-toegang testen</button></form></div></section>';
    }

    public static function tmdbSettings(Repository $repo, string $csrf): string
    {
        $settings = $repo->tmdbSettings();
        return '<section class="card my-4" id="tmdb-settings"><div class="card-header d-flex align-items-center gap-2">TMDB (The Movie Database)'
            . '<img class="tmdb-logo" src="/assets/images/tmdb.svg" alt="" aria-hidden="true" width="56" height="14"></div><div class="card-body">'
            . '<p>Vul een TMDB API-key (v3) of API Read Access Token (v4) in. Maak een gratis account op <a href="https://www.themoviedb.org/settings/api" target="_blank" rel="noopener noreferrer">themoviedb.org</a>. '
            . 'TMDB levert o.a. gebruikersscores, leeftijdsclassificaties en trailers. Gebruik zonder aparte overeenkomst is alleen toegestaan voor niet-commercieel gebruik; NNSpotty toont het TMDB-logo en de verplichte vermelding.</p>'
            . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="tmdb-settings">'
            . self::switch('enabled', 'TMDB inschakelen', $settings['enabled'], 'tmdb')
            . '<label for="tmdb-token" class="form-label mt-3">TMDB API-key of Read Access Token</label><input class="form-control" type="password" id="tmdb-token" name="token" autocomplete="new-password" maxlength="2000">'
            . '<p class="form-text">Leeg veld behoudt de bestaande sleutel. Sleutel ' . ($settings['token'] === '' ? 'nog niet ingesteld' : 'opgeslagen')
            . '; wordt herstelbaar in de database opgeslagen en niet teruggetoond. Bescherm backups.</p>'
            . self::switch('clear_token', 'Opgeslagen TMDB-sleutel verwijderen', false, 'tmdb')
            . '<label class="form-label mt-3" for="tmdb-language">Metadatataal</label><select class="form-select" id="tmdb-language" name="language">'
            . '<option value="nl-NL"' . ($settings['language'] === 'nl-NL' ? ' selected' : '') . '>Nederlands (classificatie NL), daarna Engels</option>'
            . '<option value="en-US"' . ($settings['language'] === 'en-US' ? ' selected' : '') . '>Engels (classificatie US)</option></select>'
            . '<button class="btn btn-primary mt-3" type="submit">TMDB-instellingen opslaan</button></form>'
            . '<form method="post" action="/metadata" class="mt-3">' . $csrf . '<input type="hidden" name="action" value="tmdb-test">'
            . '<button class="btn btn-outline-secondary" type="submit">Opgeslagen TMDB-toegang testen</button></form>'
            . '<p class="form-text mt-3">' . self::TMDB_NOTICE . '</p></div></section>';
    }

    public static function providerForm(Repository $repo, string $csrf, string $type): string
    {
        $label = $type === 'movie' ? 'films' : 'series';
        $primary = $repo->providers()[$type];
        $effective = $repo->effectiveProvider($type);
        $names = ['tmdb' => 'TMDB', 'tvdb' => 'TheTVDB'];
        $state = $effective === null ? 'Geen provider ingeschakeld; er worden geen ' . $label . 'metadata opgehaald.'
            : ($effective === $primary ? 'Actief: ' . $names[$primary] . ' als primaire provider.'
            : $names[$primary] . ' is niet ingeschakeld; ' . $names[$effective] . ' wordt nu als primaire provider gebruikt.');
        return '<section class="card my-4" id="metadata-provider-' . $type . '"><div class="card-header">Metadata voor ' . $label . '</div><div class="card-body">'
            . '<form method="post" action="/metadata">' . $csrf . '<input type="hidden" name="action" value="provider"><input type="hidden" name="type" value="' . $type . '">'
            . '<label class="form-label" for="metadata-provider-select-' . $type . '">Primaire provider</label>'
            . '<select class="form-select" id="metadata-provider-select-' . $type . '" name="provider">'
            . '<option value="tmdb"' . ($primary === 'tmdb' ? ' selected' : '') . '>TMDB (The Movie Database)</option>'
            . '<option value="tvdb"' . ($primary === 'tvdb' ? ' selected' : '') . '>TheTVDB</option></select>'
            . '<p class="form-text">' . h($state) . ' Eerst wordt de primaire provider geprobeerd. Zonder unieke match, bij een fout of ontbrekende aflevering wordt ook de andere ingeschakelde provider geprobeerd. '
            . 'Een geslaagde match bepaalt de titel en synopsis; de andere ingeschakelde provider'
            . ' kan ontbrekende gegevens aanvullen (bijv. poster, score, classificatie, trailers, cast) via IMDb-/TVDB-ID&#39;s, nooit alleen op titel. Wijzigen bepaalt bestaande automatische matches opnieuw; handmatige keuzes blijven bij hun provider zolang die is ingeschakeld.</p>'
            . '<button class="btn btn-primary" type="submit">Provider voor ' . $label . ' opslaan</button></form>'
            . '<p class="form-text mt-3">Alleen overtuigende titels worden automatisch gekoppeld; films vereisen ook een overeenkomend jaar. Onduidelijke titels blijven origineel. Data wordt zeven dagen gecachet, zoekresultaten en ontbrekende matches een dag; fouten vijf minuten. Geen massale backfill tijdens retrieval.</p>'
            . '</div></section>';
    }

    private const TMDB_NOTICE = 'This website uses TMDB and the TMDB APIs but is not endorsed, certified, or otherwise approved by TMDB.';

    private static function switch(string $name, string $label, bool $checked, string $prefix = 'tvdb'): string
    {
        return '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="' . $name . '" id="' . $prefix . '-' . $name
            . '" value="1"' . ($checked ? ' checked' : '') . '><label class="form-check-label" for="' . $prefix . '-' . $name . '">' . h($label) . '</label></div>';
    }

    public static function controls(array $spot, bool $admin, string $csrf, ?Repository $repo = null): string
    {
        $release = Release::parse($spot);
        if ($release === null) return '';
        $default = $repo?->effectiveProvider($release['type']) ?? 'tvdb';
        $providers = $repo === null ? ['tvdb'] : array_values(array_filter(['tmdb', 'tvdb'], $repo->providerEnabled(...)));
        if ($providers === []) $providers = ['tvdb'];
        $options = '';
        foreach ($providers as $provider) $options .= '<option value="' . $provider . '"' . ($provider === $default ? ' selected' : '') . '>' . ($provider === 'tmdb' ? 'TMDB' : 'TheTVDB') . '</option>';
        $context = $csrf . '<input type="hidden" name="id" value="' . h($spot['id']) . '">';
        $html = '<dialog id="metadata-drawer" class="download-drawer metadata-drawer" aria-labelledby="metadata-title" data-tvdb data-provider-name="Metadata" data-choice-id="metadata_id" data-spot="' . h($spot['id']) . '">'
            . '<div class="download-toolbar"><h2 class="h5 mb-0" id="metadata-title">Metadata link</h2>'
            . '<button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-close aria-label="Metadatapaneel sluiten">Sluiten &times;</button></div>'
            . '<div class="download-body">'
            . '<form data-tvdb-load method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="load"><p class="small text-body-secondary mb-2" role="status" data-tvdb-status>Film-/seriemetadata wordt opgehaald; de spot blijft hieronder beschikbaar.</p>'
            . '<button class="btn btn-sm btn-outline-secondary" type="submit">Metadata opnieuw controleren</button></form>';
        if ($admin) {
            $html .= '<section class="mt-3" aria-label="Metadatamatch kiezen / wijzigen"><h3 class="h6">Handmatig koppelen</h3>'
                . '<form data-tvdb-search class="mt-3" method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="search"><label class="form-label" for="tvdb-query">Titel zoeken</label>'
                . '<input class="form-control" id="tvdb-query" name="query" value="' . h($release['query']) . '" required minlength="2" maxlength="200">'
                . '<label class="form-label mt-2" for="tvdb-type">Type</label><select class="form-select" id="tvdb-type" name="type"><option value="movie">Film</option>'
                . '<option value="series"' . ($release['type'] === 'series' ? ' selected' : '') . '>Serie</option></select>'
                . '<label class="form-label mt-2" for="metadata-provider">Provider</label><select class="form-select" id="metadata-provider" name="provider">' . $options . '</select>'
                . '<button class="btn btn-sm btn-primary mt-2" type="submit">Zoeken</button></form>'
                . '<form data-tvdb-choice class="mt-3" method="post" action="/metadata">' . $context . '<input type="hidden" name="action" value="choose"><div data-tvdb-candidates></div>'
                . '<p class="small text-body-secondary">Optioneel seizoen en aflevering voor een serie. Leeg koppelt de serie als geheel; pakketten niet als losse aflevering koppelen. Correcties gelden voor iedereen.</p>'
                . '<div class="row g-2"><div class="col"><label class="form-label" for="tvdb-season">Seizoen</label><input class="form-control" type="number" min="0" max="999" name="season" id="tvdb-season" value="' . h((string) ($release['season'] ?? '')) . '"></div>'
                . '<div class="col"><label class="form-label" for="tvdb-episode">Aflevering</label><input class="form-control" type="number" min="1" max="9999" name="episode" id="tvdb-episode" value="' . h((string) ($release['episode'] ?? '')) . '"></div></div>'
                . '<button class="btn btn-sm btn-primary mt-3" type="submit">Geselecteerde match opslaan</button></form>'
                . '<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-reset>Opnieuw automatisch matchen</button>'
                . '<button class="btn btn-sm btn-outline-danger" type="button" data-tvdb-block>Geen metadata voor deze spot</button></div></section>';
        } else {
            $html .= '<p class="small text-body-secondary mt-3">Alleen een bevoegde gebruiker kan een gedeelde metadatamatch kiezen, wijzigen of uitschakelen.</p>';
        }
        return $html . '</div></dialog><noscript><p>JavaScript is nodig voor metadataverrijking. De oorspronkelijke spot blijft hieronder volledig bruikbaar.</p></noscript>';
    }

    public static function link(): string
    {
        return '<button class="btn btn-sm btn-link metadata-link" type="button" data-tvdb-open aria-controls="metadata-drawer" aria-haspopup="dialog">Metadata link</button>';
    }

    public static function hero(array $spot, array $metadata, string $actions, bool $images, bool $preview = false, string $link = ''): string
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
        $provider = ($metadata['provider'] ?? 'tvdb') === 'tmdb' ? 'tmdb' : 'tvdb';
        $providerName = $provider === 'tmdb' ? 'TMDB' : 'TheTVDB';
        $copy = '<p class="text-body-secondary mb-2">' . $providerName . ' &middot; ' . h(['movie' => 'Film', 'series' => 'Serie', 'episode' => 'Aflevering'][$metadata['type']]) . '</p>'
            . '<' . $heading . '>' . h($metadata['title']) . '</' . $heading . '>';
        if ($metadata['episode_title'] !== '') $copy .= '<' . $episodeHeading . ' class="h4">' . h($metadata['episode_title']) . '</' . $episodeHeading . '>';
        if ($metadata['season'] !== null && !$metadata['episode_missing']) $copy .= '<p>Seizoen ' . (int) $metadata['season'] . ($metadata['episode'] === null ? '' : ' &middot; Aflevering ' . (int) $metadata['episode']) . '</p>';
        $facts = array_filter([$metadata['date'], $metadata['runtime'] === null ? '' : $metadata['runtime'] . ' min', implode(', ', $metadata['genres'])]);
        $copy .= '<p class="metadata-facts">' . h(implode(' · ', $facts)) . '</p>' . self::badges($metadata);
        if ($metadata['overview'] !== '') $copy .= '<p class="metadata-overview">' . nl2br(h($metadata['overview'])) . '</p>';
        if ($metadata['episode_missing']) $copy .= '<p class="small text-body-secondary">Serie gevonden; seizoen ' . (int) $metadata['season']
            . ', aflevering ' . (int) $metadata['requested_episode']
            . ' is niet uniek beschikbaar in de officiële ' . $providerName . '-nummering. Seizoen en aflevering zijn uit de releasetitel, niet door ' . $providerName . ' bevestigd. Er worden alleen seriemetadata getoond; corrigeer de koppeling via Metadata link.</p>';
        $copy .= \NnSpotty\UI\SpotReleaseQuality::render($spot['title']);
        $trailers = $preview ? [] : self::trailerItems($metadata);
        if ($trailers !== []) {
            $first = $trailers[0];
            $actions = '<a class="trailer-button" href="' . h($first['url']) . '" target="_blank" rel="noopener noreferrer" data-trailer-open'
                . ' aria-controls="trailer-dialog" aria-haspopup="dialog" aria-label="Trailer afspelen" title="Trailer afspelen">'
                . '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true" focusable="false">'
                . '<path fill-rule="evenodd" d="M12 2a10 10 0 1 1 0 20a10 10 0 1 1 0-20ZM12 4.4a2 2 0 1 1 0 4a2 2 0 1 1 0-4ZM17.33 8.27a2 2 0 1 1 0 4a2 2 0 1 1 0-4ZM15.29 14.53a2 2 0 1 1 0 4a2 2 0 1 1 0-4ZM8.71 14.53a2 2 0 1 1 0 4a2 2 0 1 1 0-4ZM6.67 8.27a2 2 0 1 1 0 4a2 2 0 1 1 0-4ZM12 11.1a.9.9 0 1 1 0 1.8a.9.9 0 1 1 0-1.8Z"/><rect x="12" y="20" width="10" height="2" rx="1"/>'
                . '</svg></a>' . $actions;
        }
        return '<section class="spot-hero metadata-hero' . ($metadata['type'] === 'episode' ? ' episode-hero' : '') . '">'
            . SpotPresentation::backdrop($backgroundSpot, 'hero-backdrop', $images)
            . '<div class="hero-actions" role="group" aria-label="Spotacties"><div class="hero-sources">' . $link . self::attribution($metadata, $provider) . '</div>'
            . '<div class="hero-buttons">' . $actions . '</div></div>'
            . '<div class="hero-poster">' . SpotPresentation::image($imageSpot, false, $images) . '</div><div class="hero-copy">' . $copy . '</div></section>'
            . '<p class="small text-body-secondary metadata-release">Oorspronkelijke spottitel: <strong>' . h($spot['title']) . '</strong></p>';
    }

    private static function badges(array $metadata): string
    {
        $badges = [];
        foreach (['score' => 'TMDB-gebruikersscore', 'episode_score' => 'TMDB-score aflevering'] as $field => $label) {
            $score = $metadata[$field] ?? null;
            if (!is_array($score) || !is_numeric($score['value'] ?? null) || !is_int($score['votes'] ?? null)) continue;
            $percent = (int) round((float) $score['value'] * 10);
            $badges[] = '<span class="badge metadata-score" title="' . h($label . ' (' . $score['votes'] . ' stemmen)') . '">'
                . ($field === 'episode_score' ? 'Aflevering ' : 'TMDB ') . $percent . '%<small> &middot; ' . number_format($score['votes'], 0, ',', '.') . ' stemmen</small></span>';
        }
        $certification = $metadata['certification'] ?? [];
        if (is_string($certification['value'] ?? null) && is_string($certification['country'] ?? null)) {
            $badges[] = '<span class="badge metadata-certification" title="Leeftijdsclassificatie (' . h($certification['country']) . ')">'
                . h($certification['country'] . ' ' . $certification['value']) . '</span>';
        }
        return $badges === [] ? '' : '<p class="metadata-badges d-flex flex-wrap gap-2">' . implode('', $badges) . '</p>';
    }

    private static function attribution(array $metadata, string $provider): string
    {
        $links = is_array($metadata['links'] ?? null) ? $metadata['links'] : [];
        if ($links === [] || !isset($links[$provider])) {
            $links = [$provider => ($metadata['type'] === 'movie' ? 'movies/' : ($metadata['type'] === 'episode' ? 'episodes/' : 'series/')) . (int) $metadata['id']] + $links;
        }
        $html = '';
        foreach ($links as $source => $path) {
            if (!is_string($path) || !preg_match('~^[a-z]+/[0-9]+(?:/season/[0-9]+/episode/[0-9]+)?$~D', $path)) continue;
            if ($source === 'tvdb') {
                $html .= '<a class="metadata-attribution small" href="https://thetvdb.com/dereferrer/' . h($path)
                    . '" target="_blank" rel="noopener noreferrer"><span>Metadata van TheTVDB</span>'
                    . '<img class="tvdb-logo-dark" src="/assets/images/tvdb-dark.png" width="30" height="16" alt="" aria-hidden="true">'
                    . '<img class="tvdb-logo-light" src="/assets/images/tvdb-light.png" width="30" height="16" alt="" aria-hidden="true"></a>';
            } elseif ($source === 'tmdb') {
                $html .= '<a class="metadata-attribution small" href="https://www.themoviedb.org/' . h($path)
                    . '" target="_blank" rel="noopener noreferrer"><span>Metadata van TMDB</span>'
                    . '<img class="tmdb-logo" src="/assets/images/tmdb.svg" width="56" height="14" alt="" aria-hidden="true"></a>'
                    . '<p class="metadata-notice small text-body-secondary">' . self::TMDB_NOTICE . '</p>';
            }
        }
        return '<div class="metadata-attributions">' . $html . '</div>';
    }

    /** @return list<array{site: string, key: string, url: string, name: string}> */
    private static function trailerItems(array $metadata): array
    {
        $items = [];
        foreach ($metadata['trailers'] ?? [] as $trailer) {
            $site = $trailer['site'] ?? '';
            $key = $trailer['key'] ?? '';
            if ($site === 'YouTube' && is_string($key) && preg_match('/^[A-Za-z0-9_-]{6,20}$/D', $key)) {
                $url = 'https://www.youtube.com/watch?v=' . $key;
            } elseif ($site === 'Vimeo' && is_string($key) && preg_match('/^\d{1,12}$/D', $key)) {
                $url = 'https://vimeo.com/' . $key;
            } else {
                continue;
            }
            $items[] = ['site' => $site, 'key' => $key, 'url' => $url, 'name' => (string) ($trailer['name'] ?? 'Trailer')];
        }
        return $items;
    }

    public static function trailers(array $metadata): string
    {
        $items = self::trailerItems($metadata);
        if ($items === []) return '';
        $buttons = '';
        foreach ($items as $index => $item) {
            $buttons .= '<a class="btn btn-sm btn-outline-secondary' . ($index === 0 ? ' active' : '') . '" href="' . h($item['url'])
                . '" target="_blank" rel="noopener noreferrer" data-trailer-site="' . h($item['site']) . '" data-trailer-key="' . h($item['key']) . '">'
                . h($item['name']) . '</a>';
        }
        return '<dialog class="image-lightbox trailer-dialog" id="trailer-dialog" aria-labelledby="trailer-title">'
            . '<div class="lightbox-toolbar"><h2 class="h6 mb-0" id="trailer-title">Trailer</h2>'
            . '<button class="btn btn-sm btn-outline-secondary" type="button" data-trailer-close>Sluiten &times;</button></div>'
            . '<div class="trailer-body">' . (count($items) > 1 ? '<div class="d-flex flex-wrap gap-2 mb-2">' . $buttons . '</div>' : '<div hidden>' . $buttons . '</div>')
            . '<div class="ratio ratio-16x9 trailer-frame" data-trailer-frame hidden></div>'
            . '<p class="small text-body-secondary mt-2 mb-0">De speler laadt pas na een klik, via youtube-nocookie.com of Vimeo.</p></div></dialog>';
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
        $providers = implode(' of ', array_map(self::name(...), $result['providers'] ?? [$result['provider'] ?? 'tvdb']));
        $message = match ($result['state']) {
            'matched' => ($result['manual'] ? 'Handmatig gekoppeld aan ' : 'Automatisch gekoppeld aan ') . self::name($result['metadata']['provider'] ?? 'tvdb') . '.',
            'ambiguous' => 'Geen overtuigende unieke ' . $providers . '-match. De oorspronkelijke spot wordt getoond; een bevoegde gebruiker kan de match kiezen.',
            'unmatched' => 'Geen ' . $providers . '-resultaat gevonden. De oorspronkelijke spot wordt getoond.',
            'blocked' => 'Een admin heeft metadata voor deze spot uitgeschakeld.',
            'busy' => 'Film-/seriemetadata wordt al opgehaald. Controleer over enkele seconden opnieuw.',
            'error' => $result['message'] ?? 'Metadata kon niet worden opgehaald. De oorspronkelijke spot blijft beschikbaar.',
            default => 'Deze spot heeft geen herkenbare film- of serietitel.',
        };
        if (isset($result['fallback_from'])) $message .= ' Terugval vanaf ' . self::name($result['fallback_from']) . '.';
        if (!empty($result['warnings'])) $message .= ' ' . implode(' ', $result['warnings']);
        return $message;
    }

    private static function name(string $provider): string
    {
        return $provider === 'tmdb' ? 'TMDB' : 'TheTVDB';
    }
}
