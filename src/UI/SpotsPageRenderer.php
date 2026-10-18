<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotsPageRenderer
{
    /**
     * @param array<int, array{category: string, total: int}> $categories
     * @param array<int, array{
     *   release_key: string,
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   last_posted_at: string
     * }> $spots
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $categories,
        array $spots,
        ?string $selectedCategory,
        int $currentPage,
        int $totalPages,
        int $totalItems,
        string $csrfField
    ): string {
        $safeUsername = h($username);
        $categoryPills = self::renderCategoryPills($categories, $selectedCategory);
        $tableRows = self::renderSpotRows($spots);
        $pagination = self::renderPagination($selectedCategory, $currentPage, $totalPages);
        $adminLink = $isAdmin ? '<a class="btn btn-outline-primary btn-sm" href="/admin">Admin</a>' : '';
        $activeCategory = $selectedCategory !== null ? h($selectedCategory) : 'alle categorieën';

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Spots - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <main class="container-fluid py-4 px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h1 class="h3 mb-1">Spots overzicht</h1>
        <p class="text-body-secondary mb-0">Ingelogd als {$safeUsername} · Filter: {$activeCategory} · Releases: {$totalItems}</p>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/">Home</a>
        <a class="btn btn-outline-secondary btn-sm" href="/preferences">Preferences</a>
        {$adminLink}
        <form method="post" action="/logout" class="d-inline">
          {$csrfField}
          <button class="btn btn-outline-danger btn-sm" type="submit">Logout</button>
        </form>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <h2 class="h6">Categorieën</h2>
        <div class="d-flex flex-wrap gap-2">
          {$categoryPills}
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-semibold">Releases</div>
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th>Datum</th>
              <th>Categorie</th>
              <th>Titel</th>
              <th>Spotter</th>
              <th>Onderdelen</th>
              <th>Size</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {$tableRows}
          </tbody>
        </table>
      </div>
      <div class="card-footer">{$pagination}</div>
    </div>
  </main>
</body>
</html>
HTML;
    }

    /**
     * @param array<int, array{category: string, total: int}> $categories
     */
    private static function renderCategoryPills(array $categories, ?string $selectedCategory): string
    {
        $allActive = $selectedCategory === null ? 'btn-primary' : 'btn-outline-primary';
        $items = ['<a class="btn ' . $allActive . ' btn-sm" href="/spots">Alles</a>'];

        foreach ($categories as $entry) {
            $category = $entry['category'];
            $total = $entry['total'];
            $active = $selectedCategory === $category ? 'btn-primary' : 'btn-outline-primary';
            $items[] = '<a class="btn ' . $active . ' btn-sm" href="/spots?category=' . rawurlencode($category) . '">' . h($category) . ' <span class="badge text-bg-light ms-1">' . (int) $total . '</span></a>';
        }

        return implode('', $items);
    }

    /**
     * @param array<int, array{
     *   release_key: string,
     *   release_title: string,
     *   category: string,
     *   group_name: string,
     *   poster: string,
     *   total_parts: int,
     *   total_bytes: int,
     *   last_posted_at: string
     * }> $spots
     */
    private static function renderSpotRows(array $spots): string
    {
        if ($spots === []) {
            return '<tr><td colspan="7" class="text-body-secondary">Nog geen spots gevonden. Draai retrieve en daarna db reindex-spots.</td></tr>';
        }

        $rows = [];
        foreach ($spots as $spot) {
            $date = $spot['last_posted_at'] !== '' ? h($spot['last_posted_at']) : '-';
            $category = h($spot['category']);
            $title = h($spot['release_title'] !== '' ? $spot['release_title'] : '(zonder titel)');
            $poster = h($spot['poster']);
            $parts = max(1, (int) $spot['total_parts']);
            $size = self::formatBytes((int) $spot['total_bytes']);
            $detailsLink = '/spots/view?key=' . rawurlencode($spot['release_key']);
            $rows[] = '<tr>'
                . '<td class="text-nowrap">' . $date . '</td>'
                . '<td><span class="badge text-bg-secondary">' . $category . '</span></td>'
                . '<td>' . $title . '</td>'
                . '<td>' . $poster . '</td>'
                . '<td class="text-nowrap">' . $parts . '</td>'
                . '<td class="text-nowrap">' . $size . '</td>'
                . '<td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="' . $detailsLink . '">Details</a></td>'
                . '</tr>';
        }

        return implode('', $rows);
    }

    private static function renderPagination(?string $selectedCategory, int $currentPage, int $totalPages): string
    {
        if ($totalPages <= 1) {
            return '1 pagina';
        }

        $previousPage = max(1, $currentPage - 1);
        $nextPage = min($totalPages, $currentPage + 1);
        $previousDisabled = $currentPage <= 1 ? ' disabled' : '';
        $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';

        $buildLink = static function (int $page) use ($selectedCategory): string {
            $query = 'page=' . $page;
            if ($selectedCategory !== null) {
                $query .= '&category=' . rawurlencode($selectedCategory);
            }

            return '/spots?' . $query;
        };

        $previousLink = $buildLink($previousPage);
        $nextLink = $buildLink($nextPage);

        return '<div class="d-flex justify-content-between align-items-center">'
            . '<span>Pagina ' . $currentPage . ' van ' . $totalPages . '</span>'
            . '<div class="btn-group">'
            . '<a class="btn btn-outline-secondary btn-sm' . $previousDisabled . '" href="' . $previousLink . '">Vorige</a>'
            . '<a class="btn btn-outline-secondary btn-sm' . $nextDisabled . '" href="' . $nextLink . '">Volgende</a>'
            . '</div>'
            . '</div>';
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
