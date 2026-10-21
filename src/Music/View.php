<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use NnSpotty\UI\SpotPresentation;

final class View
{
    public static function settings(Repository $repo, string $csrf, ?string $username = null, bool $includeFanart = true): string
    {
        $personal = $username !== null;
        $enabled = $personal ? $repo->preference($username) : $repo->enabled();
        $html = '<section class="card my-4" id="music-metadata"><div class="card-header">Muziekmetadata: MusicBrainz</div><div class="card-body">'
            . '<p>Artiesten, albums, singles/EPs en specifieke uitgaven met beschikbare tracklists. Voor MusicBrainz geen API-key nodig. Bij openen van muziekdetails wordt de zoekterm gedeeld met MusicBrainz; hoezen laden via Cover Art Archive/Internet Archive. Optionele artiestachtergronden via fanart.tv stel je ' . ($includeFanart ? 'hieronder' : 'in onder Providers') . ' apart in.</p>'
            . '<p>' . ($repo->enabled() ? 'Muziekmetadata is globaal ingeschakeld.' : 'Muziekmetadata is globaal uitgeschakeld; persoonlijke keuzes blijven bewaard.') . '</p>'
            . '<form method="post" action="/music-metadata">' . $csrf . '<input type="hidden" name="action" value="' . ($personal ? 'preference' : 'settings') . '">'
            . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" id="music-' . ($personal ? 'personal' : 'global')
            . '" value="1"' . ($enabled ? ' checked' : '') . '><label class="form-check-label" for="music-' . ($personal ? 'personal' : 'global') . '">'
            . ($personal ? 'Muziekmetadata voor mij ophalen en tonen' : 'Muziekmetadata globaal toestaan') . '</label></div>'
            . '<button class="btn btn-primary mt-3" type="submit">' . ($personal ? 'Muziekmetadatavoorkeur opslaan' : 'Muziekmetadata-instellingen opslaan') . '</button></form>';
        if (!$personal) $html .= '<p class="form-text mt-3">Iedere gebruiker, ook admins, schakelt persoonlijk in onder Voorkeuren. APP_URL wordt als publieke contact-URL meegestuurd in de User-Agent; gebruik geen URL met geheimen. Maximaal een MusicBrainz-aanvraag per 1,1 seconde voor deze installatie. Data zeven dagen, zoekacties/negatieve matches een dag, fouten vijf minuten in de database. Geen massale retrieve-backfill.</p>'
            . '<p class="form-text">Automatisch alleen een unieke exacte artiest-/albumtitelmatch; afzonderlijk slotjaar moet overeenkomen, bronjaar vooraan of bij LP/vinyl/CDRIP niet. Live-/concertdetails worden niet aan een studioversie gekoppeld. Albumgroep is geen specifieke remaster/persing. Kies voor een exacte tracklist handmatig een uitgave. Alleen admins zoeken/corrigeren/blokkeren gedeelde matches. Controleer API-, data- en artworkvoorwaarden bij commercieel gebruik.</p>';
        if ($includeFanart) $html .= '<hr>' . self::fanart($repo, $csrf, $username);
        return $html . '</div></section>';
    }

    public static function fanartCard(Repository $repo, string $csrf): string
    {
        return '<section class="card my-4" id="fanart"><div class="card-header">fanart.tv (artiestachtergronden voor muziek)</div><div class="card-body">'
            . self::fanart($repo, $csrf, null) . '</div></section>';
    }

    private static function fanart(Repository $repo, string $csrf, ?string $username): string
    {
        $personal = $username !== null;
        $fanart = $repo->fanartSettings();
        $fanartEnabled = $personal ? $repo->fanartPreference($username) : $fanart['enabled'];
        $html = '<h3 class="h5">Artiest-fanart: fanart.tv</h3>'
            . '<p>Optionele artiestachtergronden via de MusicBrainz-artiest-ID. Bij openen van muziekdetails wordt deze ID met fanart.tv gedeeld; afbeeldingen laden rechtstreeks van assets.fanart.tv. Beide muziekschakelaars en beide fanart-schakelaars moeten aan staan. Afbeeldingen uit blokkeert ophalen en tonen. Geen garantie dat iedere artiest artwork heeft; geen willekeurige artiest bij compilaties of meerdere artiesten.</p>'
            . '<form method="post" action="/music-metadata">' . $csrf . '<input type="hidden" name="action" value="' . ($personal ? 'fanart-preference' : 'fanart-settings') . '">'
            . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="fanart-' . ($personal ? 'personal' : 'global') . '"'
            . ($fanartEnabled ? ' checked' : '') . '><label class="form-check-label" for="fanart-' . ($personal ? 'personal' : 'global') . '">'
            . ($personal ? 'Artiest-fanart voor mij ophalen en tonen' : 'Artiest-fanart globaal toestaan') . '</label></div>';
        if (!$personal) $html .= '<label class="form-label mt-3" for="fanart-key">Fanart.tv API-key</label>'
            . '<input class="form-control" type="password" id="fanart-key" name="api_key" autocomplete="new-password" maxlength="128">'
            . '<p class="form-text">Leeg laten behoudt de bestaande key; ' . ($fanart['api_key'] === '' ? 'nog niet ingesteld' : 'opgeslagen') . '. De key wordt alleen server-side gebruikt en niet teruggetoond.</p>'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" name="clear_key" value="1" id="fanart-clear"><label class="form-check-label" for="fanart-clear">Opgeslagen key wissen (schakel fanart ook uit)</label></div>';
        $html .= '<button class="btn btn-primary mt-3">Fanartinstelling opslaan</button></form>'
            . '<p class="form-text mt-3">Artwork-URLs zeven dagen, ontbrekend artwork een dag en fouten vijf minuten in de database. Preview gebruikt uitsluitend cache. Beschikbare achtergrond met de meeste stemmen; geen afbeeldingsbestanden lokaal opgeslagen. Bronvermelding bij de afbeelding. Controleer de <a href="https://fanart.tv/api-info/" target="_blank" rel="noopener noreferrer">fanart.tv-voorwaarden</a> en de rechten op artwork.</p>';
        return $html;
    }

    public static function controls(array $spot, bool $admin, string $csrf): string
    {
        $release = Release::parse($spot);
        if ($release === null) return '';
        $context = $csrf . '<input type="hidden" name="id" value="' . h($spot['id']) . '">';
        $html = '<dialog id="metadata-drawer" class="download-drawer metadata-drawer" aria-labelledby="metadata-title" data-tvdb data-provider-name="MusicBrainz" data-endpoint="/music-metadata" data-choice-id="mbid" data-spot="' . h($spot['id']) . '">'
            . '<div class="download-toolbar"><h2 class="h5 mb-0" id="metadata-title">MusicBrainz link</h2><button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-close aria-label="Muziekpaneel sluiten">Sluiten &times;</button></div>'
            . '<div class="download-body"><form data-tvdb-load method="post" action="/music-metadata">' . $context
            . '<input type="hidden" name="action" value="load"><p class="small text-body-secondary mb-2" role="status" data-tvdb-status>Muziekmetadata wordt opgehaald; de oorspronkelijke spot blijft beschikbaar.</p><button class="btn btn-sm btn-outline-secondary">Metadata opnieuw controleren</button></form>';
        if ($admin) {
            $html .= '<section class="mt-3" aria-label="Muziekmatch beheren"><h3 class="h6">Handmatig koppelen (admin)</h3>'
                . '<form data-tvdb-search method="post" action="/music-metadata">' . $context
                . '<label class="form-label" for="music-query">Artiest en titel zoeken</label><input class="form-control" name="query" id="music-query" minlength="2" maxlength="200" required value="' . h($release['query']) . '">'
                . '<label class="form-label mt-2" for="music-type">Type</label><select class="form-select" name="type" id="music-type">'
                . '<option value="release-group">Album / single / EP (geen specifieke editie)</option>'
                . '<option value="release">Specifieke uitgave met tracklist</option><option value="artist"' . ($release['type'] === 'artist' ? ' selected' : '') . '>Artiest / discografie</option></select>'
                . '<button class="btn btn-sm btn-primary mt-2">Zoeken bij MusicBrainz</button></form>'
                . '<form data-tvdb-choice method="post" action="/music-metadata" class="mt-3">' . $context . '<div data-tvdb-candidates></div>'
                . '<p class="small text-body-secondary">Controleer artiest, jaar, editie en toelichting. Bij live/concerttitels kunnen suggesties ook studioversies zijn: controleer de opname. Correcties gelden voor iedereen; een discografie is geen enkel album.</p>'
                . '<button class="btn btn-sm btn-primary mt-2">Geselecteerde match opslaan</button></form>'
                . '<div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-sm btn-outline-secondary" type="button" data-tvdb-reset>Opnieuw automatisch matchen</button>'
                . '<button class="btn btn-sm btn-outline-danger" type="button" data-tvdb-block>Geen metadata voor deze spot</button></div></section>';
        } else $html .= '<p class="small text-body-secondary mt-3">Alleen admins corrigeren gedeelde muziekmatches.</p>';
        return $html . '</div></dialog><noscript><p>JavaScript is nodig voor muziekverrijking; de oorspronkelijke spot blijft beschikbaar.</p></noscript>';
    }

    public static function link(): string
    {
        return '<button class="btn btn-sm btn-link metadata-link" type="button" data-tvdb-open aria-controls="metadata-drawer" aria-haspopup="dialog">MusicBrainz link</button>';
    }

    public static function message(array $result): string
    {
        $message = match ($result['state']) {
            'matched' => ($result['manual'] ? 'Handmatig gekoppeld.' : 'Unieke muziekmatch gevonden.') . ' Controleer bij albumgroepen de specifieke editie indien nodig.',
            'blocked' => 'Een admin heeft muziekmetadata voor deze spot uitgeschakeld.',
            'ambiguous' => 'Geen overtuigende unieke artiest/albumcombinatie. De oorspronkelijke spot blijft zichtbaar; een admin kan handmatig koppelen.',
            'unmatched' => 'Geen passende muziekmatch gevonden.',
            'busy' => 'MusicBrainz-data wordt al verwerkt; probeer opnieuw.',
            'unsupported' => 'Geen ondersteunde muziekspot.',
            default => 'MusicBrainz kon niet worden opgehaald. Probeer over vijf minuten opnieuw; de oorspronkelijke spot blijft beschikbaar.',
        };
        return $message . (isset($result['metadata']['fanart_warning']) ? ' ' . $result['metadata']['fanart_warning'] : '');
    }

    public static function summary(array $spot, array $metadata, string $actions, bool $images, bool $preview): string
    {
        $artist = $metadata['type'] === 'artist';
        $heading = $preview ? 'h2' : 'h1';
        $coverSpot = $spot;
        if ($metadata['cover'] !== '') {
            $coverSpot['image_segments'] = [];
            $coverSpot['image_url'] = $metadata['cover'];
        }
        $cover = $artist ? '<div class="music-artist-symbol" aria-hidden="true">&#9835;</div>' : SpotPresentation::image($coverSpot, false, $images);
        $kind = $artist ? 'Artiest' : ($metadata['type'] === 'release' ? 'Specifieke uitgave' : 'Albumgroep');
        $facts = array_filter([$metadata['kind'], $metadata['date'], $metadata['country'], implode(', ', $metadata['genres'])]);
        $fanart = $images ? Fanart::imageUrl($metadata['fanart'] ?? null) : '';
        $background = $fanart === '' ? '' : SpotPresentation::backdrop(['image_segments' => [], 'image_url' => $fanart], 'hero-backdrop', $images);
        $html = '<section class="spot-hero music-hero' . ($artist ? ' music-artist-hero' : '') . '">'
            . $background
            . '<div class="hero-actions" role="group" aria-label="Spotacties">' . $actions . '</div>'
            . '<div class="hero-poster">' . $cover . '</div><div class="hero-copy">'
            . '<p class="text-body-secondary mb-2">MusicBrainz &middot; ' . h($kind) . '</p><' . $heading . '>' . h($metadata['title']) . '</' . $heading . '>'
            . ($metadata['artist'] === '' ? '' : '<p class="music-artist-name">' . h($metadata['artist']) . '</p>')
            . '<p>' . h(implode(' · ', $facts)) . '</p>'
            . ($metadata['end'] === '' ? '' : '<p>Einddatum: ' . h($metadata['end']) . '</p>')
            . ($metadata['note'] === '' ? '' : '<p>' . h($metadata['note']) . '</p>')
            . ($metadata['labels'] === [] ? '' : '<p>Label: ' . h(implode(', ', $metadata['labels'])) . '</p>')
            . '<p class="small text-body-secondary">' . ($artist ? 'Artiestgegevens; geen volledige discografie of biografie. Eventuele artiestachtergrond komt van fanart.tv, niet van MusicBrainz.'
                : ($metadata['type'] === 'release-group' ? 'Algemene albumgroep; geen bevestiging van een specifieke editie, remaster of tracklist.'
                    : 'Tracklist hoort bij deze geselecteerde uitgave; mediabestanden zijn niet gecontroleerd.')) . '</p>'
            . '<a class="metadata-attribution small" href="https://musicbrainz.org/' . h($metadata['type']) . '/' . h($metadata['id'])
            . '" target="_blank" rel="noopener noreferrer">Metadata van MusicBrainz</a>'
            . (!$artist && $images && $metadata['cover'] !== '' ? '<span class="small"> &middot; <a href="https://coverartarchive.org/" target="_blank" rel="noopener noreferrer">Hoezen: Cover Art Archive</a></span>' : '')
            . ($fanart === '' ? '' : '<span class="small"> &middot; <a href="https://fanart.tv/artist/' . h($metadata['artist_id']) . '/" target="_blank" rel="noopener noreferrer">Artiest-fanart: fanart.tv</a></span>')
            . '</div></section>'
            . ($images && isset($metadata['fanart_warning']) ? '<div class="alert alert-warning" role="status">' . h($metadata['fanart_warning']) . '</div>' : '')
            . '<p class="small text-body-secondary metadata-release">Oorspronkelijke spottitel: <strong>' . h($spot['title']) . '</strong></p>';
        if ($metadata['tracks'] !== []) {
            $html .= '<section class="card mb-3 music-tracks"><div class="card-header">Tracklist (' . (int) $metadata['track_count'] . ')</div><div class="card-body">';
            if ($metadata['duration'] !== null) $html .= '<p>Speelduur: ' . self::duration($metadata['duration']) . '</p>';
            $html .= '<ol class="list-unstyled mb-0">';
            foreach ($metadata['tracks'] as $track) {
                $html .= '<li class="music-track"><span>' . (int) $track['disc'] . '.' . h($track['number']) . '</span><span>'
                    . h($track['title']) . ($track['artist'] === '' ? '' : '<small class="d-block text-body-secondary">' . h($track['artist']) . '</small>')
                    . '</span><span>' . ($track['length'] === null ? '' : self::duration($track['length'])) . '</span></li>';
            }
            if (count($metadata['tracks']) < $metadata['track_count']) $html .= '<li class="small text-body-secondary">Maximaal 200 tracks getoond; zie MusicBrainz voor de volledige uitgave.</li>';
            $html .= '</ol></div></section>';
        }
        return $html;
    }

    private static function duration(int $milliseconds): string
    {
        $seconds = intdiv($milliseconds, 1000);
        return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
