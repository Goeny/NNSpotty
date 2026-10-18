<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class LoginPageRenderer
{
    public static function render(?string $statusMessage, string $csrfField): string
    {
        $statusAlert = '';
        if ($statusMessage !== null) {
            $statusAlert = '<div class="alert alert-warning" role="alert">' . h($statusMessage) . '</div>';
        }

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - NNSpotty</title>
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/spotty.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <main class="container py-5">
    <div class="row justify-content-center">
      <div class="col-12 col-md-6 col-lg-4">
        <a href="/" class="d-block mb-4"><img class="application-logo" src="/assets/images/logo.png" width="768" height="512" alt="NNSpotty"></a>
        <div class="card shadow-sm">
          <div class="card-header fw-semibold">Inloggen</div>
          <div class="card-body">
            {$statusAlert}
            <form method="post" action="/login">
              {$csrfField}
              <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <input class="form-control" id="username" name="username" type="text" required>
              </div>
              <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">Login</button>
            </form>
          </div>
          <div class="card-footer text-body-secondary small">
            Voor adminfuncties is een admin-account vereist.
          </div>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
HTML;
    }
}
