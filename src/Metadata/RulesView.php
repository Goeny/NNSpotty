<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

final class RulesView
{
    public static function render(MatchRules $rules, string $csrf): string
    {
        $types = ['movie' => 'Film', 'series' => 'Serie', 'music' => 'Muziek'];
        $operations = [
            'remove_word' => 'Heel woord / letterlijke frase verwijderen',
            'remove_prefix' => 'Letterlijk voorvoegsel verwijderen',
            'remove_suffix' => 'Letterlijk achtervoegsel verwijderen',
            'remove_bracketed' => 'Haakjes met deze term verwijderen',
            'year_marker' => 'Eigen jaarmarkering → jaartal',
            'season_marker' => 'Eigen seizoenmarkering → Season',
            'episode_marker' => 'Eigen afleveringsmarkering → Episode',
        ];
        $html = '<section class="card my-4" id="metadata-match-rules"><div class="card-header">Metadata-matchregels</div><div class="card-body">'
            . '<p>Eigen regels herschrijven uitsluitend een kopie van de zoektitel, niet de opgeslagen spottitel of de oorspronkelijke film-/serie-indeling. '
            . 'Ze worden op volgorde van regelnummer toegepast, vóór de ingebouwde parser. Maximaal 100 regels per mediatype, ook uitgeschakelde regels tellen mee.</p>'
            . '<div class="alert alert-warning">Titeltekst weghalen kan fout-positieve matches veroorzaken. Gebruik gerichte, unieke frases; geen code of reguliere expressies. '
            . 'Verwijderingen slaan tekst met cijfers over (ook in haakjes). Markeringen behouden cijfers, voorloopnullen en bereikstaarten; '
            . 'jaar: 1900–2099, seizoen: 1–3 cijfers, aflevering: 1–4 cijfers. Seizoen/aflevering zijn voor de videoparser; muziek heeft geen afleveringen.</div>'
            . '<p class="form-text">Hoofdletterongevoelige, letterlijke woorden/frases. Voor-/achtervoegsels zijn verankerd en negeren scheidingstekens. '
            . 'Haakjesverwijdering geldt alleen voor ronde of vierkante haakjes die de opgegeven hele term bevatten, niet voor alle haakjes. '
            . 'Een term is 1–160 UTF-8-bytes zonder controletekens; markeringen bevatten minstens één letter. '
            . 'De ingebouwde matching hieronder is alleen-lezen; matchdrempels zijn niet instelbaar.</p>'
            . '<form method="post" action="/admin/metadata-operations" class="row g-3">' . $csrf
            . '<input type="hidden" name="action" value="rule-add">'
            . '<div class="col-md-3"><label class="form-label" for="match-rule-type">Mediatype</label><select class="form-select" id="match-rule-type" name="media_type">';
        foreach ($types as $value => $label) $html .= '<option value="' . $value . '">' . $label . '</option>';
        $html .= '</select></div><div class="col-md-4"><label class="form-label" for="match-rule-operation">Bewerking</label>'
            . '<select class="form-select" id="match-rule-operation" name="operation">';
        foreach ($operations as $value => $label) $html .= '<option value="' . $value . '">' . self::escape($label) . '</option>';
        $html .= '</select></div><div class="col-md-5"><label class="form-label" for="match-rule-term">Letterlijke term</label>'
            . '<input class="form-control" id="match-rule-term" name="term" type="text" required maxlength="160" autocomplete="off">'
            . '<div class="form-text">Maximaal 160 bytes; tekens met accenten kunnen meerdere bytes gebruiken.</div></div>'
            . '<div class="col-12"><button class="btn btn-primary" type="submit">Matchregel toevoegen</button></div></form>'
            . '<h3 class="h6 mt-4">Titelvoorbeeld met opgeslagen regels</h3>'
            . '<form method="post" action="/admin/metadata-operations" class="row g-3">' . $csrf
            . '<input type="hidden" name="action" value="rule-preview">'
            . '<div class="col-md-3"><label class="form-label" for="match-preview-type">Mediatype</label>'
            . '<select class="form-select" id="match-preview-type" name="media_type">';
        foreach ($types as $value => $label) $html .= '<option value="' . $value . '">' . $label . '</option>';
        $html .= '</select></div><div class="col-md-9"><label class="form-label" for="match-preview-title">Spottitel</label>'
            . '<input class="form-control" id="match-preview-title" name="title" type="text" required maxlength="16384">'
            . '</div><div class="col-12"><button class="btn btn-outline-secondary" type="submit">Titelvoorbeeld bekijken</button></div></form>'
            . '<p class="form-text">Gebruikt de bestaande regels; sla een nieuwe regel eerst op. Het voorbeeld wijzigt geen spot of match.</p>'
            . '<div class="table-responsive mt-4"><table class="table table-sm align-middle"><thead><tr>'
            . '<th>Nr.</th><th>Type</th><th>Bewerking</th><th>Term</th><th>Status</th><th>Actie</th></tr></thead><tbody>';
        $rows = $rules->rows();
        if ($rows === []) $html .= '<tr><td colspan="6">Nog geen eigen matchregels.</td></tr>';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . (int) $row['id'] . '</td><td>' . self::escape($types[$row['media_type']] ?? $row['media_type'])
                . '</td><td>' . self::escape($operations[$row['operation']] ?? $row['operation'])
                . '</td><td><code>' . self::escape($row['term']) . '</code></td><td>' . ($row['enabled'] ? 'Actief' : 'Uitgeschakeld')
                . '</td><td><form method="post" action="/admin/metadata-operations">' . $csrf
                . '<input type="hidden" name="action" value="rule-delete"><input type="hidden" name="id" value="' . (int) $row['id']
                . '"><button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Matchregel '
                . (int) $row['id'] . ' verwijderen">Verwijderen</button></form></td></tr>';
        }
        $html .= '</tbody></table></div><h3 class="h6 mt-4">Ingebouwde regels (alleen-lezen)</h3><div class="accordion" id="metadata-builtins">';
        foreach (self::builtins() as $type => $body) {
            $id = 'metadata-builtin-' . $type;
            $html .= '<details class="accordion-item" id="' . $id . '">'
                . '<summary class="p-3 fw-semibold bg-body-tertiary">' . $types[$type] . '</summary>'
                . '<div class="accordion-body">' . $body . '</div></details>';
        }
        return $html . '</div></div></section>';
    }

    private static function builtins(): array
    {
        $video = '<ul><li>Alleen categorieën Films/Series. Underscores worden punten; een voorloopnummer van 1–2 cijfers met punt en spatie wordt verwijderd. '
            . 'Titel eindigt vóór seizoen of het eerste technische token. Punten/underscores worden spaties; buitenste spaties, streepjes en haakjes worden getrimd. Zoeknaam: 2–200 bytes.</li>'
            . '<li>Seizoenen: S met 1–3 cijfers, optioneel E met 1–4 cijfers; ook 1–2 cijfers x 2–3 cijfers; woorden seizoen/season en aflevering/episode/ep. '
            . 'Woordnotatie ondersteunt bereiken met -, t/m, tm en to. S/E-staarten herkennen extra E/S-nummers, cijfers en to/tot/t/m; x-notatie herkent volgende x-paren of afleveringsnummers. '
            . 'Een seizoen maakt het type serie; anders bepaalt de oorspronkelijke categorie het type. Bij meerdere afleveringen/seizoenen blijft de eerste seizoenwaarde staan en wordt aflevering null.</li>'
            . '<li>Bundels: trilogy, trilogie, collection, boxset, of 2–9 x BD25/BD50/BD66/BD100. Filmbundels worden niet automatisch geselecteerd.</li>'
            . '<li>Jaar: laatste losstaand jaartal 1900–2099 vóór het seizoen; de zoeknaam wordt daarvoor afgekapt. Een leidende numerieke titel zoals 1917 geldt niet als releasejaar.</li>'
            . '<li>Technische afkaptokens: 2160p, 1080p/i, 720p, 480p, 4K, UHD, Ultra HD, WEB-DL, WEBRip, Blu-Ray, BDRip, BRRip, DVDRip, HDTV, '
            . 'x264/x265, h264/h265, HEVC, REMUX, DV, DoVi, Dolby Vision, COMPLETE, REPACK, PROPER, MULTISUBS, NL SUBS/NL RETAILSUBS, IMAX, '
            . 'Theatrical Version/Cut, Extended Edition/Cut en Director\'s Cut. Diverse punt-, spatie- en koppeltekenvarianten worden herkend volgens het token.</li>'
            . '<li>Vergelijking: &amp; → and, ASCII-transliteratie, kleine letters, alleen a–z/0–9; bij een lege ASCII-sleutel fallback op Unicode-letters/cijfers. '
            . 'Alle kandidaatnamen tellen mee. Exact: 100 punten; similar_text ≥92%: 85 punten; ≥85%: 70 punten; lager valt af. Alleen kandidaten van hetzelfde type.</li>'
            . '<li>Films vereisen een releasejaar. Met een releasejaar moet het kandidaatjaar exact zijn (+25) of ±1 (+8); anders afwijzen. Zonder seriejaar geen jaarbonus. '
            . 'Eén overblijvende kandidaat wordt geselecteerd. Bij meerdere: exacte titel én exact jaar krijgt voorrang; gelijke exacte toppers worden eerst op vote_count, dan popularity beslist '
            . '(alleen bij verschil). Anders is minstens 110 punten en minstens 15 punten voorsprong nodig. Ambigue resultaten blijven ongematcht.</li></ul>';
        $music = '<ul><li>Alleen Geluid, met uitsluiting van subcategorieën 2z2/2z02 en 2z3/2z03. Underscores worden spaties; en-/em-dash wordt koppelteken. '
            . 'Artist - Album (of eerste koppelteken zonder spaties) splitst artiest/album; punten worden spaties. Voorloopnummer 1–2 cijfers met punt/spatie vervalt. '
            . 'Artiest: 2–200 bytes; album maximaal 200 bytes. Zonder artiest/album-paar alleen handmatig zoeken, tenzij expliciete artiest/discografie.</li>'
            . '<li>Techniek: FLAC, MP3, AAC, ALAC, APE, WAVPACK, WAV, WMA, OGG, DSD, SACD, WEB, CDRIP, Hi-Res/Hi Res/HiRes, HRA, '
            . 'n x CD, nCD/nCDS, DTS/DTS-HD, 16/24/32 bit, 320 kbps; LP/Vinyl alleen op de herkende technische grens. '
            . 'Technische suffixen worden pas afgeknipt als geen lege albumhelft ontstaat en niet binnen haakjes. Technische haakjes met Vinyl/LP/FLAC/MP3/WAVPACK/DTS/kHz/Hi-Res/HRA/n CD/bit worden verwijderd, '
            . 'behalve als ze live/remaster(ed)/deluxe/edition/mix/version bevatten.</li>'
            . '<li>Jaren: YEAR - Artist - Album, Artist - YEAR - Album, eindjaar en scene -YEAR-GROUP worden herkend. Een voorloopjaar of fysieke Vinyl/LP/CDRIP-uitgave '
            . 'legt geen exact albumjaar op. Scenejaar wordt vóór technische afkapping vastgelegd. Discography/discografie/discographie geeft artiestmodus en verwijdert een eindbereik van jaren; '
            . 'artist:/artiest: geeft expliciete artiestmodus.</li>'
            . '<li>Albumtypehint: EP of Single als achtervoegsel met koppelteken/haakjes; losse EP ook met spatie. Editielabels in eindhaakjes: deluxe, anniversary, expanded, remaster(ed), edition, reissue, bonus, special, '
            . 'collector\'s, legacy, jubileum, editie, uitgave; verwijderen tenzij live/concert/unplugged/acoustic/session/mix/version aanwezig is. '
            . 'Losse DeLuxe/Super Deluxe (Edition) aan het einde vervalt ook. Live-/locatietitels blijven identiteit.</li>'
            . '<li>Various Artists-aliassen (na normalisatie): va, various, variousartists, variousartist, diverseartiesten, diverseartiest, diversen, diverse, verschillendeartiesten, '
            . 'divartiesten, artistesdivers, variosartistas, verschiedeneinterpreten, verschiedenekunstler. Staat zo’n alias in de albumhelft, dan worden artiest/album omgewisseld.</li>'
            . '<li>Vergelijking gebruikt de videonormalisatie (inclusief &amp; → and) plus vol/volume/deel/part/pt met cijfers → vol met cijfers; los &amp;/+/and met spaties worden and. '
            . 'Exacte genormaliseerde titel-/artiestaliassen zijn vereist, geen fuzzy score. Artist en release-group blijven gescheiden. Voor albums tellen kandidaat-artiest en aanvullende artiestnamen mee.</li>'
            . '<li>Albumtitel met aangeplakt jaar is een toegestane naamvariant. Anders moet een exact jaar overeenkomen; bij scene/editiejaar mag kandidaatjaar leeg of niet later zijn. '
            . 'Kandidaten worden op id ontdubbeld. Bij meerdere albumgroepen krijgt een unieke expliciete EP/Single-hint voorrang, anders een uniek Album. '
            . 'Bij blijvende ambiguïteit geen automatische match; geen populariteitsrangschikking of instelbare drempel.</li></ul>';
        return ['movie' => $video, 'series' => $video, 'music' => $music];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
