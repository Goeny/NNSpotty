<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class PreferencesPageRenderer
{
    /**
     * @param array{theme: string, items_per_page: int} $preferences
     */
    public static function render(string $username, array $preferences, ?string $statusMessage, bool $isAdmin, string $csrfField): string
    {
        $safeUsername = h($username);
        $theme = $preferences['theme'];
        $itemsPerPage = $preferences['items_per_page'];
        $defaultSelected = self::selectedAttr($theme, 'default');
        $plexDarkSelected = self::selectedAttr($theme, 'plex-dark');
        $plexLightSelected = self::selectedAttr($theme, 'plex-light');
        $adminLink = self::adminLink($isAdmin);
        $statusAlert = '';

        if ($statusMessage !== null) {
            $statusAlert = '<div class="alert alert-success" role="alert">' . h($statusMessage) . '</div>';
        }

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Preferences - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h3 mb-0">Gebruikersvoorkeuren</h1>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/">Terug naar home</a>
        {$adminLink}
        <form method="post" action="/logout" class="d-inline">
          {$csrfField}
          <button class="btn btn-outline-danger btn-sm" type="submit">Logout</button>
        </form>
      </div>
    </div>
    {$statusAlert}
    <div class="card">
      <div class="card-header fw-semibold">Preferences voor username: {$safeUsername}</div>
      <div class="card-body">
        <form method="post" action="/preferences">
          {$csrfField}
          <input type="hidden" name="username" value="{$safeUsername}">
          <div class="mb-3">
            <label class="form-label" for="theme">Thema</label>
            <select class="form-select" id="theme" name="theme">
              <option value="default" {$defaultSelected}>Default</option>
              <option value="plex-dark" {$plexDarkSelected}>Plex Dark</option>
              <option value="plex-light" {$plexLightSelected}>Plex Light</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label" for="items_per_page">Items per pagina</label>
            <input class="form-control" id="items_per_page" name="items_per_page" type="number" min="10" max="200" value="{$itemsPerPage}" required>
          </div>
          <button class="btn btn-primary" type="submit">Preferences opslaan</button>
        </form>
      </div>
    </div>
  </main>
</body>
</html>
HTML;
    }

    private static function selectedAttr(string $actual, string $expected): string
    {
        return $actual === $expected ? 'selected' : '';
    }

    private static function adminLink(bool $isAdmin): string
    {
        if (!$isAdmin) {
            return '';
        }

        return '<a class="btn btn-outline-primary btn-sm" href="/admin">Admin</a>';
    }
}
