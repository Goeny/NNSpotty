<?php

declare(strict_types=1);

namespace NnSpotty\Moderation;

final class View
{
    public static function badge(array $spot): string
    {
        return match ($spot['spotter_status'] ?? 'unverified') {
            'whitelist' => ' <span class="badge text-bg-success" title="Handtekening bij message-ID gecontroleerd; publieke sleutel staat op de Spotlist-whitelist. Geen inhoudscontrole.">Whitelist</span>',
            'blacklist' => ' <span class="badge text-bg-danger" title="Deze eerder opgeslagen spotter staat nu op de blacklist.">Blacklist</span>',
            default => '',
        };
    }

    public static function settings(SpotterLists $lists, string $csrfField): string
    {
        $settings = $lists->settings();
        $totals = $lists->totals();
        $checked = $settings['enabled'] ? ' checked' : '';
        $updated = h($settings['updated_at'] === null ? 'Nog niet gesynchroniseerd' : $settings['updated_at'] . ' UTC');
        $error = $settings['last_error'] === null ? '' : '<div class="alert alert-warning" role="alert">'
            . h($settings['last_error']) . ' Laatst succesvol opgeslagen lijsten blijven behouden.</div>';
        if ($settings['last_warning'] !== null) {
            $error .= '<div class="alert alert-warning" role="status">' . h($settings['last_warning']) . '</div>';
        }
        $white = (int) ($totals['whitelist'] ?? 0);
        $black = (int) ($totals['blacklist'] ?? 0);
        return <<<HTML
<div class="card mb-4">
  <div class="card-header fw-semibold">Spotlist: geverifieerde spottersleutels</div>
  <div class="card-body">
    {$error}
    <p>Laatste update: {$updated}. Whitelist: {$white} sleutels; blacklist: {$black} sleutels.</p>
    <p class="small">Bij ingeschakelde moderatie vernieuwt retrieve de lijsten zodra ze ouder zijn dan 24 uur.
    Blacklist heeft voorrang. Nieuwe spots van een gecontroleerde blacklisted sleutel worden overgeslagen;
    onbekende of ongeldige handtekeningen blijven behouden zonder whitelist-badge. Namen bepalen nooit de identiteit.
    Bestaande spots worden niet automatisch verwijderd of opnieuw gecontroleerd. Reacties vallen niet onder deze sleutelcontrole.</p>
    <p class="small">Bronnen:
    <a href="https://spotlist.store/spotnet/whitelist.xml" target="_blank" rel="noopener noreferrer">Spotlist whitelist</a>,
    <a href="https://spotlist.store/spotnet/blacklist.xml" target="_blank" rel="noopener noreferrer">Spotlist blacklist</a>.
    Een whitelist-badge betekent geen goedkeuring of controle van de inhoud. Oude XML-only ondertekening wordt niet als identiteit bevestigd.</p>
    <form method="post" action="/admin/spotter-lists" class="mb-3">
      {$csrfField}<input type="hidden" name="action" value="settings">
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" id="spotter-lists-enabled" name="enabled" value="1"{$checked}>
        <label class="form-check-label" for="spotter-lists-enabled">Sleutelgebaseerde moderatie inschakelen</label>
      </div>
      <button class="btn btn-primary" type="submit">Opslaan</button>
    </form>
    <form method="post" action="/admin/spotter-lists">
      {$csrfField}<input type="hidden" name="action" value="sync">
      <button class="btn btn-outline-primary" type="submit">Lijsten nu bijwerken</button>
    </form>
  </div>
</div>
HTML;
    }
}
