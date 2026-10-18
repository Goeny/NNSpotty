<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Config\AppConfig;

final class HomePageRenderer
{
    /**
     * @param array{username: string, is_admin: bool}|null $user
     */
    public static function render(AppConfig $config, ?array $user, string $csrfField): string
    {
        $name = htmlspecialchars($config->name, ENT_QUOTES, 'UTF-8');
        $version = htmlspecialchars($config->version, ENT_QUOTES, 'UTF-8');
        $codename = htmlspecialchars($config->codename, ENT_QUOTES, 'UTF-8');
        $authActions = '<a class="btn btn-outline-light" href="/login">Login</a>';

        if ($user !== null) {
            $userBadge = '<span class="badge rounded-pill text-bg-warning">Ingelogd als: ' . h($user['username']) . '</span>';
            $adminButton = $user['is_admin'] ? '<a class="btn btn-primary" href="/admin">Open admin panel</a>' : '';
            $authActions = $userBadge .
                '<a class="btn btn-outline-info" href="/spots">Browse spots</a>' .
                '<a class="btn btn-outline-light" href="/preferences">Mijn preferences</a>' .
                $adminButton .
                '<form method="post" action="/logout" class="d-inline">' . $csrfField . '<button class="btn btn-outline-danger" type="submit">Logout</button></form>';
        }

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$name}</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      min-height: 100vh;
      background: linear-gradient(135deg, rgba(9,17,28,0.95), rgba(22,40,62,0.9)),
                  url('https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?auto=format&fit=crop&w=1920&q=60') center/cover no-repeat fixed;
      color: #fff;
    }
    .hero-card {
      background: rgba(12, 20, 34, 0.78);
      border: 1px solid rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(4px);
    }
    .poster {
      border-radius: 14px;
      width: 100%;
      max-width: 280px;
      box-shadow: 0 12px 26px rgba(0, 0, 0, 0.45);
    }
  </style>
</head>
<body>
  <main class="container py-5">
    <section class="hero-card rounded-4 p-4 p-lg-5">
      <div class="row g-4 align-items-center">
        <div class="col-lg-4 text-center">
          <img class="poster" src="https://images.unsplash.com/photo-1478720568477-152d9b164e26?auto=format&fit=crop&w=650&q=60" alt="Cover image voorbeeld">
        </div>
        <div class="col-lg-8">
          <h1 class="display-5 fw-bold mb-3">{$name}</h1>
          <p class="lead mb-3">Moderne Spotweb-opvolger op basis van php-nntp.</p>
          <div class="d-flex flex-wrap gap-2 mb-4">
            <span class="badge rounded-pill bg-primary">v{$version}</span>
            <span class="badge rounded-pill text-bg-light">Codenaam: {$codename}</span>
            <span class="badge rounded-pill bg-success">Scaffold actief</span>
          </div>
          <div class="alert alert-info mb-0" role="alert">
            Eerste basis staat live. Volgende stappen: usenet-indexing, NZB-forwarding, metadata-integratie en Newznab API.
          </div>
          <div class="mt-3 d-flex flex-wrap gap-2">
            {$authActions}
          </div>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
HTML;
    }
}
