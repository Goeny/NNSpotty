<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class AdminPageRenderer
{
    /**
     * @param array{
     *   retention: int,
     *   retrieve_after: ?string,
     *   server: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   explicit_groups: string,
     *   group_pattern: string,
     *   discover_max_groups: int,
     *   max_articles_per_group: int
     * } $settings
     * @param array{whitelist: string[], blacklist: string[]} $spotterLists
     * @param array<int, array{post_id: string, username: string, comment: string, created_at: string}> $comments
     * @param array<int, array{actor_username: string, action: string, target_type: string, target_id: string, details_json: string, ip_address: string, created_at: string}> $auditLogs
     */
    public static function render(array $settings, array $spotterLists, array $comments, array $auditLogs, ?string $statusMessage, string $currentUsername, string $csrfField, bool $allowErotica = false, string $theme = 'default', array $globalColumns = [], array $spotContent = ['retrieve_comments' => true, 'show_comments' => true, 'images_enabled' => true], bool $apiEnabled = false, string $sabSettings = '', string $metadataSettings = ''): string
    {
        $retention = (int) $settings['retention'];
        $retrieveAfter = h($settings['retrieve_after'] ?? '');
        $server = h($settings['server']);
        $port = (int) $settings['port'];
        $username = h($settings['username']);
        $password = h($settings['password']);
        $encryption = $settings['encryption'];
        $explicitGroups = h(str_replace(',', "\n", $settings['explicit_groups']));
        $maxArticlesPerGroup = (int) $settings['max_articles_per_group'];
        $noneSelected = self::selectedAttr($encryption, 'none');
        $sslSelected = self::selectedAttr($encryption, 'ssl');
        $tlsSelected = self::selectedAttr($encryption, 'tls');
        $statusAlert = '';
        if ($statusMessage !== null) {
            $statusAlert = '<div class="alert alert-info" role="alert">' . h($statusMessage) . '</div>';
        }
        $safeCurrentUsername = h($currentUsername);
        $columnForm = SpotColumns::form($globalColumns !== [] ? $globalColumns : SpotColumns::defaults(), null, $csrfField);
        $eroticaChecked = $allowErotica ? 'checked' : '';
        $apiChecked = $apiEnabled ? 'checked' : '';
        $contentOptions = '';
        foreach (['retrieve_comments' => 'Usenet-reacties indexeren', 'show_comments' => 'Reacties weergeven en lokale reacties toestaan', 'images_enabled' => 'Afbeeldingen ophalen en tonen (op aanvraag)'] as $key => $label) {
            $contentOptions .= '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="' . $key
                . '" name="' . $key . '" value="1"' . ($spotContent[$key] ? ' checked' : '') . '><label class="form-check-label" for="' . $key . '">' . h($label) . '</label></div>';
        }

        $whitelistItems = self::renderSimpleList($spotterLists['whitelist']);
        $blacklistItems = self::renderSimpleList($spotterLists['blacklist']);
        $commentRows = self::renderCommentRows($comments);
        $commentPanel = !$spotContent['show_comments'] ? '<p class="text-body-secondary">Reacties zijn door de beheerder uitgeschakeld.</p>' : <<<HTML
            <form method="post" action="/admin/comments" class="row g-2 mb-3">
              {$csrfField}
              <div class="col-md-6">
                <input class="form-control" name="post_id" type="text" placeholder="Post ID" required>
              </div>
              <div class="col-md-6">
                <button class="btn btn-primary w-100" type="submit">Comment toevoegen</button>
              </div>
              <div class="col-12">
                <textarea class="form-control" name="comment" rows="3" placeholder="Comment tekst" required></textarea>
              </div>
            </form>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>Post</th><th>User</th><th>Comment</th><th>Tijd</th></tr></thead>
                <tbody>{$commentRows}</tbody>
              </table>
            </div>
HTML;
        $auditRows = self::renderAuditRows($auditLogs);

        $content = <<<HTML
  <div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h1 class="h3 mb-0">Admin Beheerpanel</h1>
      <div class="d-flex flex-wrap gap-2">
        <span class="badge text-bg-warning align-self-center">Admin: {$safeCurrentUsername}</span>
        <a class="btn btn-outline-secondary btn-sm" href="/spots">Terug naar spots</a>
        <form method="post" action="/logout" class="d-inline">
          {$csrfField}
          <button class="btn btn-outline-danger btn-sm" type="submit">Logout</button>
        </form>
      </div>
    </div>
    {$statusAlert}
    <nav class="nav nav-pills gap-2 admin-navigation" aria-label="Beheeronderdelen" data-admin-navigation>
      <a class="nav-link" href="#usenet">Usenet</a>
      <a class="nav-link" href="#appearance">Inhoud en weergave</a>
      <a class="nav-link" href="#newznab">Newznab</a>
      <a class="nav-link" href="#downloads">SABnzbd</a>
      <a class="nav-link" href="#metadata">TVDB metadata</a>
      <a class="nav-link" href="#moderation">Moderatie en reacties</a>
      <a class="nav-link" href="#audit">Auditlog</a>
    </nav>
    <div data-admin-panel="downloads">{$sabSettings}</div>
    <div data-admin-panel="metadata">{$metadataSettings}</div>
    <div data-admin-panel="newznab">
    <section class="card my-4" id="newznab">
      <div class="card-header">Newznab API</div>
      <form method="post" action="/admin/newznab" class="card-body">
        {$csrfField}
        <div class="form-check form-switch">
          <input class="form-check-input" id="api-enabled" type="checkbox" name="enabled" value="1" {$apiChecked}>
          <label class="form-check-label" for="api-enabled">Newznab API inschakelen</label>
        </div>
        <p class="form-text">Standaard uit. Na inschakelen beheert iedere gebruiker een persoonlijke API-key via Voorkeuren. De API volgt de eigen erotica-instelling en de globale blokkade. Zoeken op titel en standaard Newznab-categorieen; geen IMDb/TVDB-ID-zoeken. Maximaal 120 aanvragen per minuut per key. Gebruik HTTPS en APP_URL met het publieke adres.</p>
        <button class="btn btn-primary" type="submit">API-instelling opslaan</button>
      </form>
    </section>
    </div>
    <div data-admin-panel="appearance" id="appearance">
    {$columnForm}
    <section class="card my-4" id="spot-content">
      <div class="card-header fw-semibold">Op te halen inhoud</div>
      <form method="post" action="/admin/spot-content" class="card-body">
        {$csrfField}
        {$contentOptions}
        <p class="form-text">Spots en hun omschrijving blijven de basis. Reacties indexeren uit: geen reactiegroepen ophalen of cursors verplaatsen. Weergave uit: ook bestaande en lokale reacties verborgen. Afbeeldingen worden alleen bij bekijken geladen en in de database gecachet; uit blokkeert ook gecachte afbeeldingen. Opgeslagen inhoud wordt niet verwijderd.</p>
        <p class="form-text">Retentie, de optionele begindatum en max artikelen per incrementele run staan onder Usenet instellingen. Na opnieuw inschakelen van reacties haalt --since-last de achterstand vanaf de bestaande cursor op; --days, --all of --full kan beschikbare historie binnen de ingestelde grenzen aanvullen. Aanvullende IMDB/TVDB-metadata is nog niet beschikbaar.</p>
        <p class="form-text">Reacties bijwerken zonder eerst de spotgroep te scannen: <code>php bin/console retrieve --comments-only --days=7 --verbose</code>. Ook deze datumsessie scant alle beschikbare reactienummers en kan lang duren. Opgeslagen spotmetadata aanvullen: <code>php bin/console retrieve --refresh-spots --days=7 --verbose</code>.</p>
        <button class="btn btn-primary" type="submit">Inhoudsinstellingen opslaan</button>
      </form>
    </section>
    <section class="card mb-4">
      <div class="card-header fw-semibold">Inhoud en zichtbaarheid</div>
      <form method="post" action="/admin/content" class="card-body">
        {$csrfField}
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="allow_erotica" name="allow_erotica" value="1" {$eroticaChecked}>
          <label class="form-check-label" for="allow_erotica">Erotica globaal toestaan</label>
        </div>
        <p class="text-body-secondary small">Uit: verborgen voor iedereen, ook admins. Aan: gebruikers kunnen erotica zelf aanzetten in hun voorkeuren; standaard blijft dit verborgen. Indexeren blijft ongewijzigd.</p>
        <button class="btn btn-primary" type="submit">Zichtbaarheid opslaan</button>
      </form>
    </section>
    </div>
    <div data-admin-panel="usenet" id="usenet" class="my-4">
      <div>
        <div class="card">
          <div class="card-header fw-semibold">Usenet instellingen</div>
          <div class="card-body">
            <form method="post" action="/admin/settings">
              {$csrfField}
              <div class="mb-3">
                <label class="form-label" for="retention">Retentie (dagen)</label>
                <input class="form-control" id="retention" name="retention" type="number" min="0" value="{$retention}" required>
                <div class="form-text">0 betekent onbeperkte retentie. Na een geslaagde retrieve worden alle oudere spots verwijderd, in kleine transactiebatches.</div>
              </div>
              <div class="mb-3">
                <label class="form-label" for="retrieve_after">Spots ophalen vanaf</label>
                <input class="form-control" id="retrieve_after" name="retrieve_after" type="date" value="{$retrieveAfter}">
                <div class="form-text">Optioneel. Alleen spots en reacties op of na deze datum ophalen. De meest recente grens van deze datum en de retentie geldt.</div>
              </div>
              <div class="mb-3">
                <label class="form-label" for="server">Usenet server</label>
                <input class="form-control" id="server" name="server" type="text" value="{$server}" required>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label" for="port">Port</label>
                  <input class="form-control" id="port" name="port" type="number" min="1" max="65535" value="{$port}" required>
                </div>
                <div class="col-6">
                  <label class="form-label" for="encryption">Encryptie</label>
                  <select class="form-select" id="encryption" name="encryption">
                    <option value="none" {$noneSelected}>Geen</option>
                    <option value="ssl" {$sslSelected}>SSL</option>
                    <option value="tls" {$tlsSelected}>TLS</option>
                  </select>
                </div>
              </div>
              <div class="mb-3 mt-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" type="text" value="{$username}">
              </div>
              <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" value="{$password}">
              </div>
              <div class="mb-3">
                <input name="group_pattern" type="hidden" value="">
                <input name="discover_max_groups" type="hidden" value="0">
              </div>
              <div class="mb-3">
                <label class="form-label" for="explicit_groups">Spotnet-groepen (rol:groep per regel)</label>
                <textarea class="form-control" id="explicit_groups" name="explicit_groups" rows="3" placeholder="spots:free.pt&#10;comments:free.usenet">{$explicitGroups}</textarea>
                <div class="form-text">Leeg = spots:free.pt en comments:free.usenet. Alleen de rollen spots en comments worden verwerkt; geen algemene header-discovery.</div>
              </div>
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label" for="max_articles_per_group">Max artikelen per groep per incrementele run</label>
                  <input class="form-control" id="max_articles_per_group" name="max_articles_per_group" type="number" min="100" value="{$maxArticlesPerGroup}" required>
                  <div class="form-text">Geldt voor --since-last. --all/--full en --days scannen alle beschikbare headers voor een volledige backfill; retentie en begindatum begrenzen wat wordt opgeslagen.</div>
                </div>
              </div>
              <button class="btn btn-primary" type="submit">Instellingen opslaan</button>
            </form>
          </div>
        </div>
      </div>

    </div>
    <div data-admin-panel="moderation" id="moderation" class="my-4">
      <div>
        <div class="card mb-4">
          <div class="card-header fw-semibold">Spotter whitelist/blacklist</div>
          <div class="card-body">
            <form method="post" action="/admin/spotters" class="row g-2 mb-3">
              {$csrfField}
              <div class="col-md-6">
                <input class="form-control" name="spotter" type="text" placeholder="Spotter naam" required>
              </div>
              <div class="col-md-4">
                <select class="form-select" name="list_type">
                  <option value="whitelist">Whitelist</option>
                  <option value="blacklist">Blacklist</option>
                </select>
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-success" type="submit">Toevoegen</button>
              </div>
            </form>
            <div class="row">
              <div class="col-6">
                <h2 class="h6">Whitelist</h2>
                {$whitelistItems}
              </div>
              <div class="col-6">
                <h2 class="h6">Blacklist</h2>
                {$blacklistItems}
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header fw-semibold">Comments op posts</div>
          <div class="card-body">
            {$commentPanel}
          </div>
        </div>
      </div>
    </div>
    <div class="row mt-4" data-admin-panel="audit" id="audit">
      <div class="col-12">
        <div class="card">
          <div class="card-header fw-semibold">Admin audit log (laatste wijzigingen)</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>Tijd</th>
                    <th>Actor</th>
                    <th>Actie</th>
                    <th>Target</th>
                    <th>IP</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  {$auditRows}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
HTML;
        return SpotLayout::render('Beheerpanel', $currentUsername, true, $csrfField, $content, theme: $theme);
    }

    /**
     * @param string[] $entries
     */
    private static function renderSimpleList(array $entries): string
    {
        if ($entries === []) {
            return '<p class="text-body-secondary small">Nog leeg.</p>';
        }

        $items = array_map(static fn (string $entry): string => '<li class="list-group-item">' . h($entry) . '</li>', $entries);

        return '<ul class="list-group list-group-flush">' . implode('', $items) . '</ul>';
    }

    /**
     * @param array<int, array{post_id: string, username: string, comment: string, created_at: string}> $comments
     */
    private static function renderCommentRows(array $comments): string
    {
        if ($comments === []) {
            return '<tr><td colspan="4" class="text-body-secondary">Nog geen comments.</td></tr>';
        }

        $sorted = array_reverse($comments);
        $rows = array_map(static function (array $comment): string {
            return '<tr><td>' . h($comment['post_id']) . '</td><td>' . h($comment['username']) . '</td><td>' . h($comment['comment']) . '</td><td><small>' . h($comment['created_at']) . '</small></td></tr>';
        }, $sorted);

        return implode('', $rows);
    }

    private static function selectedAttr(string $actual, string $expected): string
    {
        return $actual === $expected ? 'selected' : '';
    }

    /**
     * @param array<int, array{actor_username: string, action: string, target_type: string, target_id: string, details_json: string, ip_address: string, created_at: string}> $auditLogs
     */
    private static function renderAuditRows(array $auditLogs): string
    {
        if ($auditLogs === []) {
            return '<tr><td colspan="6" class="text-body-secondary">Nog geen audit events.</td></tr>';
        }

        $rows = array_map(static function (array $entry): string {
            return '<tr>'
                . '<td><small>' . h($entry['created_at']) . '</small></td>'
                . '<td>' . h($entry['actor_username']) . '</td>'
                . '<td>' . h($entry['action']) . '</td>'
                . '<td>' . h($entry['target_type']) . ':' . h($entry['target_id']) . '</td>'
                . '<td><small>' . h($entry['ip_address']) . '</small></td>'
                . '<td><small>' . h($entry['details_json']) . '</small></td>'
                . '</tr>';
        }, $auditLogs);

        return implode('', $rows);
    }
}
