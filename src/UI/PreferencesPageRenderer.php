<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class PreferencesPageRenderer
{
    /**
     * @param array{theme: string, items_per_page: int, show_erotica?: bool} $preferences
     */
    public static function render(string $username, array $preferences, ?string $statusMessage, bool $isAdmin, string $csrfField, bool $allowErotica = false, array $globalColumns = [], array $personalColumns = []): string
    {
        $safeUsername = h($username);
        $columnForm = SpotColumns::form($globalColumns !== [] ? $globalColumns : SpotColumns::defaults(), $personalColumns, $csrfField);
        $theme = $preferences['theme'];
        $itemsPerPage = $preferences['items_per_page'];
        $defaultSelected = self::selectedAttr($theme, 'default');
        $plexDarkSelected = self::selectedAttr($theme, 'plex-dark');
        $plexLightSelected = self::selectedAttr($theme, 'plex-light');
        $adminLink = self::adminLink($isAdmin);
        $statusAlert = '';
        $eroticaChecked = ($preferences['show_erotica'] ?? false) ? 'checked' : '';
        $eroticaDisabled = $allowErotica ? '' : 'disabled';
        $eroticaNotice = $allowErotica
            ? 'Alleen zichtbaar voor jouw account wanneer je dit zelf aanzet.'
            : 'De beheerder heeft erotica globaal uitgeschakeld. Je opgeslagen voorkeur wordt pas actief wanneer de beheerder dit toestaat.';

        if ($statusMessage !== null) {
            $statusAlert = '<div class="alert alert-info" role="alert">' . h($statusMessage) . '</div>';
        }

        $content = <<<HTML
  <div class="container-fluid">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h1 class="h3 mb-0">Gebruikersvoorkeuren</h1>
      <div class="d-flex flex-wrap gap-2">
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
            <label class="form-label" for="items_per_page">Resultaten per laadbatch</label>
            <input class="form-control" id="items_per_page" name="items_per_page" type="number" min="10" max="200" value="{$itemsPerPage}" required>
          </div>
          <div class="form-check form-switch mt-4">
            <input class="form-check-input" type="checkbox" role="switch" id="show_erotica" name="show_erotica" value="1" {$eroticaChecked} {$eroticaDisabled}>
            <label class="form-check-label" for="show_erotica">Erotica weergeven</label>
          </div>
          <p class="form-text">{$eroticaNotice}</p>
          <button class="btn btn-primary" type="submit">Preferences opslaan</button>
        </form>
      </div>
      {$columnForm}
    </div>
  </div>
HTML;
        return SpotLayout::render('Voorkeuren', $username, $isAdmin, $csrfField, $content, theme: $theme);
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
