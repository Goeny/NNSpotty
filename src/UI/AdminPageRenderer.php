<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class AdminPageRenderer
{
    /**
     * @param array{
     *   retention: int,
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
    public static function render(array $settings, array $spotterLists, array $comments, array $auditLogs, ?string $statusMessage, string $currentUsername, string $csrfField): string
    {
        $retention = (int) $settings['retention'];
        $server = h($settings['server']);
        $port = (int) $settings['port'];
        $username = h($settings['username']);
        $password = h($settings['password']);
        $encryption = $settings['encryption'];
        $explicitGroups = h(str_replace(',', "\n", $settings['explicit_groups']));
        $groupPattern = h($settings['group_pattern']);
        $discoverMaxGroups = (int) $settings['discover_max_groups'];
        $maxArticlesPerGroup = (int) $settings['max_articles_per_group'];
        $noneSelected = self::selectedAttr($encryption, 'none');
        $sslSelected = self::selectedAttr($encryption, 'ssl');
        $tlsSelected = self::selectedAttr($encryption, 'tls');
        $statusAlert = '';
        if ($statusMessage !== null) {
            $statusAlert = '<div class="alert alert-success" role="alert">' . h($statusMessage) . '</div>';
        }
        $safeCurrentUsername = h($currentUsername);

        $whitelistItems = self::renderSimpleList($spotterLists['whitelist']);
        $blacklistItems = self::renderSimpleList($spotterLists['blacklist']);
        $commentRows = self::renderCommentRows($comments);
        $auditRows = self::renderAuditRows($auditLogs);

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark-subtle">
  <main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h3 mb-0">Admin Beheerpanel</h1>
      <div class="d-flex gap-2">
        <span class="badge text-bg-warning align-self-center">Admin: {$safeCurrentUsername}</span>
        <a class="btn btn-outline-secondary btn-sm" href="/">Terug naar home</a>
        <form method="post" action="/logout" class="d-inline">
          {$csrfField}
          <button class="btn btn-outline-danger btn-sm" type="submit">Logout</button>
        </form>
      </div>
    </div>
    {$statusAlert}
    <div class="row g-4">
      <div class="col-12 col-lg-6">
        <div class="card">
          <div class="card-header fw-semibold">Usenet instellingen</div>
          <div class="card-body">
            <form method="post" action="/admin/settings">
              {$csrfField}
              <div class="mb-3">
                <label class="form-label" for="retention">Retentie (dagen)</label>
                <input class="form-control" id="retention" name="retention" type="number" min="0" value="{$retention}" required>
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
                <label class="form-label" for="group_pattern">Group pattern (optioneel, bv alt.binaries.*)</label>
                <input class="form-control" id="group_pattern" name="group_pattern" type="text" value="{$groupPattern}">
              </div>
              <div class="mb-3">
                <label class="form-label" for="explicit_groups">Expliciete groepen (optioneel, category:group per regel)</label>
                <textarea class="form-control" id="explicit_groups" name="explicit_groups" rows="3" placeholder="movies:alt.binaries.movies&#10;series:alt.binaries.tv">{$explicitGroups}</textarea>
                <div class="form-text">Leeg laten = auto-discovery gebruiken. Aanbevolen Spotnet-start: spots:free.pt en comments:free.ptd.</div>
              </div>
              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label" for="discover_max_groups">Max auto-discovery groepen (0 = geen limiet)</label>
                  <input class="form-control" id="discover_max_groups" name="discover_max_groups" type="number" min="0" value="{$discoverMaxGroups}" required>
                </div>
                <div class="col-6">
                  <label class="form-label" for="max_articles_per_group">Max artikelen per groep per run</label>
                  <input class="form-control" id="max_articles_per_group" name="max_articles_per_group" type="number" min="100" value="{$maxArticlesPerGroup}" required>
                </div>
              </div>
              <button class="btn btn-primary" type="submit">Instellingen opslaan</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
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
                <thead>
                  <tr>
                    <th>Post</th>
                    <th>User</th>
                    <th>Comment</th>
                    <th>Tijd</th>
                  </tr>
                </thead>
                <tbody>
                  {$commentRows}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="row mt-4">
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
  </main>
</body>
</html>
HTML;
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
