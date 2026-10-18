<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotDetailPageRenderer
{
    /**
     * @param array{
     *   release_key: string,
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   first_posted_at: string,
     *   last_posted_at: string
     * } $spot
     * @param array<int, array{
     *   message_id: string,
     *   subject: string,
     *   article_number: int,
     *   from_header: string,
     *   posted_at: string,
     *   bytes: int|null
     * }> $parts
     */
    public static function render(string $username, bool $isAdmin, array $spot, array $parts, string $csrfField): string
    {
        $safeUsername = h($username);
        $adminLink = $isAdmin ? '<a class="btn btn-outline-primary btn-sm" href="/admin">Admin</a>' : '';
        $safeTitle = h($spot['release_title'] !== '' ? $spot['release_title'] : '(zonder titel)');
        $metaTable = self::renderMetaTable($spot);
        $partsRows = self::renderPartsRows($parts);

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Spot details - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <main class="container-fluid py-4 px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h1 class="h3 mb-1">{$safeTitle}</h1>
        <p class="text-body-secondary mb-0">Ingelogd als {$safeUsername}</p>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/spots">Terug naar spots</a>
        {$adminLink}
        <form method="post" action="/logout" class="d-inline">
          {$csrfField}
          <button class="btn btn-outline-danger btn-sm" type="submit">Logout</button>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header fw-semibold">Spot informatie</div>
      <div class="card-body">
        {$metaTable}
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-semibold">Onderdelen (headers)</div>
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Article #</th>
              <th>Datum</th>
              <th>Subject</th>
              <th>Size</th>
            </tr>
          </thead>
          <tbody>
            {$partsRows}
          </tbody>
        </table>
      </div>
    </div>
  </main>
</body>
</html>
HTML;
    }

    /**
     * @param array{
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   first_posted_at: string,
     *   last_posted_at: string
     * } $spot
     */
    private static function renderMetaTable(array $spot): string
    {
        $rows = [
            'Titel' => h($spot['release_title']),
            'Categorie' => h($spot['category']),
            'Groep' => h($spot['group_name']),
            'Spotter' => h($spot['poster']),
            'Onderdelen' => (string) max(1, (int) $spot['total_parts']),
            'Totale size' => self::formatBytes((int) $spot['total_bytes']),
            'Eerste datum' => $spot['first_posted_at'] !== '' ? h($spot['first_posted_at']) : '-',
            'Laatste datum' => $spot['last_posted_at'] !== '' ? h($spot['last_posted_at']) : '-',
        ];

        $htmlRows = [];
        foreach ($rows as $label => $value) {
            $htmlRows[] = '<tr><th style="width:220px">' . $label . '</th><td>' . $value . '</td></tr>';
        }

        return '<table class="table table-sm table-bordered mb-0"><tbody>' . implode('', $htmlRows) . '</tbody></table>';
    }

    /**
     * @param array<int, array{
     *   message_id: string,
     *   subject: string,
     *   article_number: int,
     *   from_header: string,
     *   posted_at: string,
     *   bytes: int|null
     * }> $parts
     */
    private static function renderPartsRows(array $parts): string
    {
        if ($parts === []) {
            return '<tr><td colspan="4" class="text-body-secondary">Geen onderdelen beschikbaar.</td></tr>';
        }

        $rows = [];
        foreach ($parts as $part) {
            $rows[] = '<tr>'
                . '<td>' . (int) $part['article_number'] . '</td>'
                . '<td class="text-nowrap">' . h($part['posted_at'] !== '' ? $part['posted_at'] : '-') . '</td>'
                . '<td>' . h($part['subject']) . '</td>'
                . '<td class="text-nowrap">' . self::formatBytes((int) ($part['bytes'] ?? 0)) . '</td>'
                . '</tr>';
        }

        return implode('', $rows);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unitIndex = 0;
        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $unitIndex === 0 ? 0 : 2, '.', '') . ' ' . $units[$unitIndex];
    }
}
