<?php

declare(strict_types=1);

namespace NnSpotty\Activity;

final class View
{
    public static function history(array $result, string $tab, int $page): string
    {
        $html = '<section class="activity-page"><h1 class="h3">Mijn historie</h1>'
            . '<p class="text-body-secondary">Alleen jouw acties. Per spot zie je de laatste datum en het totale aantal. Tijden zijn in UTC.</p>'
            . '<nav class="nav nav-tabs mb-4" aria-label="Historie">';
        foreach (['view' => 'Bekeken', 'download' => 'Gedownload'] as $key => $label) {
            $html .= '<a class="nav-link' . ($tab === $key ? ' active' : '') . '" href="/history?tab=' . $key . '"'
                . ($tab === $key ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $html .= '</nav>';
        if ($result['items'] === []) {
            $html .= '<p class="alert alert-secondary">Geen ' . ($tab === 'view' ? 'bekeken' : 'gedownloade') . ' spots op deze pagina.</p>';
        } else {
            $html .= '<div class="table-responsive"><table class="table align-middle activity-history-table"><caption class="visually-hidden">'
                . ($tab === 'view' ? 'Bekeken' : 'Gedownloade') . ' spots</caption><thead><tr><th scope="col">Spot</th>'
                . '<th scope="col">Categorie</th><th scope="col">Laatste actie (UTC)</th><th scope="col">Aantal</th>'
                . ($tab === 'download' ? '<th scope="col">Via</th>' : '') . '</tr></thead><tbody>';
            foreach ($result['items'] as $item) {
                $title = h($item['title']);
                $title = $item['available'] ? '<a href="/spots/view?key=' . h($item['spot_id']) . '">' . $title . '</a>'
                    : $title . ' <span class="badge text-bg-secondary">Niet meer beschikbaar</span>';
                $html .= '<tr><td class="activity-title">' . $title . '</td><td>' . h($item['category']) . '</td><td><time datetime="'
                    . h(str_replace(' ', 'T', $item['last_at']) . 'Z') . '">' . h(substr($item['last_at'], 0, 19)) . '</time></td><td>'
                    . number_format((int) $item['action_count'], 0, ',', '.') . '</td>';
                if ($tab === 'download') {
                    $labels = array_map(static fn (string $source): string => Repository::SOURCES[$source], explode(',', $item['sources']));
                    $html .= '<td>' . h(implode(', ', $labels)) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<nav class="d-flex align-items-center gap-3 mt-3" aria-label="Historiepagina">';
        if ($page > 1) $html .= '<a class="btn btn-sm btn-outline-primary" href="/history?' . h(http_build_query(['tab' => $tab, 'page' => $page - 1])) . '">Vorige</a>';
        $html .= '<span class="text-body-secondary">Pagina ' . $page . '</span>';
        if ($result['more']) $html .= '<a class="btn btn-sm btn-outline-primary" href="/history?' . h(http_build_query(['tab' => $tab, 'page' => $page + 1])) . '">Volgende</a>';
        return $html . '</nav><p class="small text-body-secondary mt-4">Gedownload betekent dat het NZB-bestand is aangeboden of door SABnzbd is geaccepteerd, niet dat de download is voltooid. '
            . 'Historie begint bij deze update; beschikbare bevestigde SABnzbd-verzendingen zijn overgenomen.</p></section>';
    }

    public static function statistics(array $data): string
    {
        $days = $data['days'];
        $html = '<section class="activity-page"><div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-3">'
            . '<h1 class="h3 mb-0">Statistieken</h1><form method="get" action="/statistics" class="d-flex gap-2 align-items-center">'
            . '<label for="statistics-days">Periode</label><select id="statistics-days" name="days" class="form-select form-select-sm">';
        foreach ([7, 30, 90] as $period) $html .= '<option value="' . $period . '"' . ($period === $days ? ' selected' : '') . '>Laatste ' . $period . ' dagen</option>';
        $html .= '</select><button class="btn btn-sm btn-primary" type="submit">Toon</button></form></div>'
            . '<p class="text-body-secondary">Applicatiebrede, geaggregeerde cijfers, zonder persoonlijke kijk- of downloadlijsten. Vandaag telt mee; datums zijn in UTC. Verborgen erotiek telt niet mee.</p>'
            . '<div class="row g-3 mb-4">';
        foreach (['Gebruikers (huidig)' => (int) $data['users']['total'], 'Actieve accounts (huidig)' => (int) $data['users']['active'],
            'Detailweergaven' => $data['totals']['view'], 'NZB-aanleveringen' => $data['totals']['download']] as $label => $count) {
            $html .= '<div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="small text-body-secondary">'
                . h($label) . '</div><strong class="fs-3">' . number_format($count, 0, ',', '.') . '</strong></div></div></div>';
        }
        $html .= '</div><div class="row g-4">';
        $html .= self::bars('Meest bezochte categorieen', $data['categories']['view']);
        $html .= self::bars('Meest gedownloade categorieen', $data['categories']['download']);
        $html .= self::bars('Downloads per bron', array_combine(
            array_map(static fn (string $source): string => Repository::SOURCES[$source], array_keys($data['sources'])),
            array_values($data['sources'])
        ));
        $newUsers = array_sum(array_column(array_values($data['daily']), 'users'));
        $html .= '<div class="col-12 col-xl-6"><section class="card h-100"><div class="card-body"><h2 class="h5">Nieuwe accounts</h2>'
            . '<p><strong>' . $newUsers . '</strong> nieuwe accounts in deze periode, van de nu nog bestaande gebruikers.</p>'
            . self::timeline($data['daily'], ['users' => 'Nieuwe accounts']) . '</div></section></div>';
        $html .= '<div class="col-12"><section class="card"><div class="card-body"><h2 class="h5">Activiteit per dag</h2>'
            . self::timeline($data['daily'], ['view' => 'Bekeken', 'download' => 'NZB-aanleveringen']) . '</div></section></div>';
        return $html . '</div><p class="small text-body-secondary mt-4">Elke herhaalde actie telt mee. Alleen succesvolle NZB-aanleveringen; geen bevestiging van voltooide downloads. '
            . 'Cijfers beginnen bij deze update, plus de beschikbare bevestigde SABnzbd-verzendingen. Geen retrospectieve kijkcijfers.</p></section>';
    }

    private static function bars(string $title, array $counts): string
    {
        $html = '<div class="col-12 col-xl-6"><section class="card h-100"><div class="card-body"><h2 class="h5">' . h($title) . '</h2>';
        $max = max([1, ...array_values($counts)]);
        if ($counts === []) $html .= '<p class="text-body-secondary mb-0">Nog geen activiteit in deze periode.</p>';
        foreach ($counts as $label => $count) {
            $html .= '<div class="activity-bar-row"><div class="d-flex justify-content-between gap-3 small"><span>' . h($label)
                . '</span><strong>' . number_format($count, 0, ',', '.') . '</strong></div><div class="activity-bar-track" aria-hidden="true">'
                . '<div class="activity-bar" style="width:' . round(100 * $count / $max, 2) . '%"></div></div></div>';
        }
        return $html . '</div></section></div>';
    }

    private static function timeline(array $daily, array $series): string
    {
        $max = 1;
        foreach ($daily as $counts) foreach ($series as $key => $_label) $max = max($max, $counts[$key]);
        $html = '<div class="activity-chart-scroll"><svg class="activity-chart" viewBox="0 0 960 210" role="img" aria-label="Dagelijkse aantallen; exacte waarden in de tabel hieronder">'
            . '<line x1="30" y1="180" x2="930" y2="180" class="activity-chart-axis"/>';
        $index = 0;
        foreach ($series as $key => $label) {
            $points = [];
            $i = 0;
            foreach ($daily as $counts) {
                $points[] = round(30 + 900 * $i++ / max(1, count($daily) - 1), 2) . ',' . round(180 - 150 * $counts[$key] / $max, 2);
            }
            $html .= '<polyline class="activity-line activity-series-' . $index++ . '" points="' . implode(' ', $points) . '"/>';
        }
        $html .= '<text x="30" y="202" class="activity-chart-label">' . h(array_key_first($daily)) . '</text>'
            . '<text x="930" y="202" text-anchor="end" class="activity-chart-label">' . h(array_key_last($daily)) . '</text>'
            . '<text x="30" y="20" class="activity-chart-label">Max: ' . $max . '</text></svg></div><div class="d-flex gap-3 small mb-3">';
        $index = 0;
        foreach ($series as $label) $html .= '<span><span class="activity-legend activity-series-' . $index++ . '" aria-hidden="true"></span> ' . h($label) . '</span>';
        $html .= '</div><details><summary>Exacte aantallen per dag</summary><div class="table-responsive mt-2"><table class="table table-sm">'
            . '<thead><tr><th scope="col">Datum (UTC)</th>';
        foreach ($series as $label) $html .= '<th scope="col">' . h($label) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($daily as $day => $counts) {
            $html .= '<tr><th scope="row">' . h($day) . '</th>';
            foreach ($series as $key => $_label) $html .= '<td>' . $counts[$key] . '</td>';
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></details>';
    }
}
