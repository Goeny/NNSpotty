<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

final class OperationsView
{
    public static function render(Operations $operations, string $csrf): string
    {
        $policy = $operations->policy();
        $status = self::statusText($operations->status());
        $html = '<section class="card my-4" id="metadata-operations"><div class="card-header">Metadata-runs en herbeoordeling</div><div class="card-body">'
            . '<div class="alert alert-info" role="status" data-metadata-run-status>' . h($status) . '</div>'
            . '<p>De status ververst elke 10 seconden. Elke run verwerkt de nieuwste spots eerst. Bulkruns staan klaar voor cron; de metadataqueue moet ingeschakeld zijn en cron moet metadata uitvoeren.</p>'
            . '<form method="post" action="/admin/metadata-operations">' . $csrf . '<input type="hidden" name="action" value="policy">'
            . '<p>Errors: korte retries vanaf 5 minuten met oplopende wachttijd (maximaal 1 uur). Na het ingestelde maximum volgt onderstaande langere pauze; daarna start een nieuwe reeks. Unmatched en ambiguous worden periodiek opnieuw beoordeeld. Gewijzigde matchregels maken eerdere negatieve beoordelingen opnieuw beschikbaar.</p>';
        foreach (['error' => 'Errors na maximale retries', 'unmatched' => 'Unmatched', 'ambiguous' => 'Ambiguous'] as $name => $label) {
            $html .= '<label class="form-label" for="retry-' . $name . '">' . $label . ' (dagen)</label>'
                . '<input class="form-control mb-2" id="retry-' . $name . '" type="number" min="1" max="365" name="' . $name . '_days" value="' . $policy[$name . '_days'] . '" required>';
        }
        $html .= '<button class="btn btn-primary" type="submit">Wachttijden opslaan</button></form><hr>'
            . '<h3 class="h6">Bulkrun klaarzetten</h3><p>Alleen eerdere errors, unmatched en ambiguous. Handmatige koppelingen/blokkeringen en geslaagde matches blijven behouden. De queue wordt in delen gevuld; de oorspronkelijke spottitel verandert niet.</p>'
            . '<form method="post" action="/admin/metadata-operations">' . $csrf . '<input type="hidden" name="action" value="bulk">'
            . '<label class="form-label" for="bulk-kind">Type</label><select class="form-select mb-2" id="bulk-kind" name="media_kind"><option value="all">Video en muziek</option><option value="video">Video</option><option value="music">Muziek</option></select>'
            . '<label class="form-label" for="bulk-state">Eerder resultaat</label><select class="form-select mb-2" id="bulk-state" name="result_state"><option value="all">Alle niet-gematchte resultaten</option><option value="error">Error</option><option value="unmatched">Unmatched</option><option value="ambiguous">Ambiguous</option></select>'
            . '<label class="form-label" for="bulk-from">Vanaf spotdatum</label><input class="form-control mb-2" type="date" id="bulk-from" name="oldest_date" required>'
            . '<label class="form-label" for="bulk-to">Tot en met spotdatum</label><input class="form-control mb-2" type="date" id="bulk-to" name="newest_date" value="' . gmdate('Y-m-d') . '" required>'
            . '<button class="btn btn-outline-primary" type="submit">Klaarzetten voor cron</button></form>';
        $html .= '<div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Periode</th><th>Type/status</th><th>Queue vullen</th><th>Klaargezet</th></tr></thead><tbody>';
        foreach ($operations->recentBulk() as $row) {
            $html .= '<tr><td>' . h($row['oldest_date'] . ' - ' . $row['newest_date']) . '</td><td>' . h($row['media_kind'] . ' / ' . $row['result_state'])
                . '</td><td>' . ($row['status'] === 'done' ? 'Voltooid (niet noodzakelijk verwerkt)' : 'Wacht op cron / bezig') . '</td><td>' . (int) $row['queued'] . '</td></tr>';
        }
        return $html . '</tbody></table></div></div></section>';
    }

    public static function statusText(array $row): string
    {
        $progress = (int) $row['processed'] . '/' . (int) $row['total'];
        if ($row['active']) return 'Metadata-run actief: ' . $progress . '. Laatste heartbeat (UTC): ' . ($row['heartbeat_at'] ?? '-')
            . ($row['current_title'] !== '' ? '. Huidige spot: ' . $row['current_title'] : '');
        if ($row['started_at'] === null) return 'Geen metadata-run actief; nog geen run geregistreerd.';
        return 'Geen metadata-run actief. Laatste run: ' . $progress . '. '
            . ($row['finished_at'] === null ? 'Vorige run onderbroken.' : 'Afgerond (UTC): ' . $row['finished_at']);
    }
}
