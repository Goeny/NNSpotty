<?php

declare(strict_types=1);

namespace NnSpotty\UI;

final class SpotDetailPageRenderer
{
    /** @param array<string, mixed> $spot
     *  @param list<array<string, mixed>> $comments
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $spot,
        array $comments,
        string $csrfField,
        ?string $statusMessage = null
    ): string {
        $title = h($spot['title']);
        $safeUsername = h($username);
        $id = h($spot['id']);
        $poster = h($spot['poster']);
        $category = h($spot['category']);
        $date = h($spot['posted_at']);
        $description = nl2br(h($spot['description']));
        $tag = h($spot['tag']);
        $messageId = h($spot['message_id']);
        $size = number_format((int) $spot['size'] / 1048576, 2, ',', '.') . ' MiB';
        $adminLink = $isAdmin ? '<a class="btn btn-outline-primary btn-sm" href="/admin">Admin</a>' : '';
        $image = SpotPresentation::image($spot, false);
        $website = $spot['website'] !== ''
            ? '<a href="' . h($spot['website']) . '" target="_blank" rel="noopener noreferrer">Website van de spot</a>' : '';
        $nzb = count($spot['nzb_segments']) > 0
            ? count($spot['nzb_segments']) . ' NZB-segment(en) gevonden. Download/forwarding wordt nog niet ondersteund.'
            : 'Geen NZB-verwijzing aanwezig.';
        $status = $statusMessage !== null ? '<div class="alert alert-info" role="status">' . h($statusMessage) . '</div>' : '';
        $commentHtml = '';
        foreach ($comments as $comment) {
            $commentHtml .= '<article class="border-bottom py-3"><div class="fw-semibold">' . h($comment['poster'])
                . ' <small class="text-body-secondary">' . h($comment['posted_at']) . ' &middot; ' . h($comment['source'])
                . '</small></div><div class="text-break">' . nl2br(h($comment['body'])) . '</div></article>';
        }
        if ($commentHtml === '') {
            $commentHtml = '<p class="text-body-secondary">Nog geen reacties opgehaald of geplaatst.</p>';
        }
        $count = count($comments);

        return <<<HTML
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title} - NNSpotty</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
  <main class="container py-4">
    <header class="d-flex flex-wrap justify-content-between gap-2 mb-3">
      <div><h1 class="h3">{$title}</h1><p class="text-body-secondary">Ingelogd als {$safeUsername}</p></div>
      <div class="d-flex align-items-start gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="/spots">Terug naar spots</a>
        {$adminLink}
        <form method="post" action="/logout">{$csrfField}<button class="btn btn-outline-danger btn-sm">Logout</button></form>
      </div>
    </header>
    {$status}
    <div class="row g-4 mb-4">
      <aside class="col-md-4">{$image}</aside>
      <section class="col-md-8">
        <div class="card mb-3"><div class="card-body">
          <span class="badge text-bg-secondary">{$category}</span>
          <dl class="row mt-3 mb-0">
            <dt class="col-sm-3">Spotter</dt><dd class="col-sm-9">{$poster}</dd>
            <dt class="col-sm-3">Datum</dt><dd class="col-sm-9">{$date} UTC</dd>
            <dt class="col-sm-3">Grootte</dt><dd class="col-sm-9">{$size}</dd>
            <dt class="col-sm-3">Tag</dt><dd class="col-sm-9 text-break">{$tag}</dd>
          </dl>
          {$website}
          <p class="small text-body-secondary mt-3">{$nzb}</p>
          <p class="small text-body-secondary text-break mb-0">Message-ID: {$messageId}</p>
        </div></div>
        <div class="card"><div class="card-header">Omschrijving</div><div class="card-body text-break">{$description}</div></div>
      </section>
    </div>
    <div class="alert alert-secondary">Usenet-inhoud wordt als onbevestigd getoond. Spotterhandtekeningen worden nog niet geverifieerd. Opmaak in omschrijvingen en reacties wordt veilig als tekst weergegeven.</div>
    <section class="card">
      <div class="card-header">Reacties ({$count})</div>
      <div class="card-body">
        {$commentHtml}
        <form method="post" action="/spots/comment" class="mt-3">
          {$csrfField}<input type="hidden" name="spot_id" value="{$id}">
          <label class="form-label" for="comment">Lokale reactie plaatsen</label>
          <textarea class="form-control mb-2" name="comment" id="comment" rows="4" maxlength="10000" required></textarea>
          <p class="form-text">Deze reactie blijft in NNSpotty en wordt niet op Usenet gepubliceerd.</p>
          <button class="btn btn-primary" type="submit">Reactie plaatsen</button>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
HTML;
    }
}
