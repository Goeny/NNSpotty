<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;

final class SpotDetailPageRenderer
{
    /** @param array<string, mixed> $spot
     *  @param list<array<string, mixed>> $comments
     *  @param list<array<string, mixed>> $categories
     */
    public static function render(
        string $username,
        bool $isAdmin,
        array $spot,
        array $comments,
        string $csrfField,
        ?string $statusMessage = null,
        array $categories = [],
        string $theme = 'default'
    ): string {
        $id = h($spot['id']);
        $summary = self::summary($spot, false);
        $status = $statusMessage !== null ? '<div class="alert alert-info" role="status">' . h($statusMessage) . '</div>' : '';
        $commentHtml = '';
        foreach ($comments as $comment) {
            $commentHtml .= '<article class="border-bottom py-3"><div class="fw-semibold">' . h($comment['poster'])
                . '</div><small class="text-body-secondary">' . h($comment['posted_at']) . ' UTC &middot; ' . h($comment['source'])
                . '</small><div class="comment-body mt-2">' . nl2br(h($comment['body'])) . '</div></article>';
        }
        if ($commentHtml === '') {
            $commentHtml = '<p class="text-body-secondary">Nog geen reacties opgehaald of geplaatst.</p>';
        }
        $count = count($comments);
        $content = <<<HTML
<a class="btn btn-sm btn-outline-secondary mb-3" href="/spots">Terug naar spots</a>
{$status}
{$summary}
<section class="card mt-4" id="comments">
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
HTML;
        return SpotLayout::render($spot['title'], $username, $isAdmin, $csrfField, $content, $categories, $spot['browse_type'] ?? $spot['category'], $theme);
    }

    /** @param array<string, mixed> $spot */
    public static function summary(array $spot, bool $preview): string
    {
        $image = SpotPresentation::image($spot, false);
        $title = h($spot['title']);
        $classification = SpotCategories::classify($spot['category'], $spot['subcategories']);
        $type = h($classification['type']);
        $tag = $preview ? 'h2' : 'h1';
        $details = SpotCategories::describe($spot);
        $chips = '';
        foreach ($details['Genre'] ?? [] as $genre) {
            $chips .= '<span class="tag-chip">' . h($genre) . '</span>';
        }
        $website = $spot['website'] !== '' ? '<a href="' . h($spot['website']) . '" target="_blank" rel="noopener noreferrer">Website van de spot</a>' : '';
        $action = $preview
            ? '<a class="btn btn-primary" href="/spots/view?key=' . rawurlencode($spot['id']) . '">Volledige spot en reacties</a>'
            : '<a class="btn btn-warning" href="#comments">Bekijk reacties</a>';
        $hero = '<section class="spot-hero"><div>' . $image . '</div><div><p class="text-body-secondary mb-2">'
            . $type . '</p><' . $tag . '>' . $title . '</' . $tag . '><div class="tag-row">' . $chips . '</div>' . $action . '</div></section>';
        $metadata = '<div class="metadata-strip"><div><small>Spotter</small>' . h($spot['poster'])
            . '</div><div><small>Geplaatst (UTC)</small>' . h($spot['posted_at']) . '</div><div><small>Grootte</small>'
            . SpotPresentation::size((int) $spot['size']) . '</div></div>';
        $description = '<section class="card mb-3"><div class="card-header d-flex justify-content-between gap-2"><span>Omschrijving</span>' . $website
            . '</div><div class="card-body spot-description">' . nl2br(h($spot['description'])) . '</div></section>';
        $technical = '';
        foreach ($details as $name => $values) {
            $technical .= '<dt>' . h($name) . '</dt><dd>' . h(implode(', ', $values)) . '</dd>';
        }
        if ($spot['tag'] !== '') {
            $technical .= '<dt>Tag</dt><dd>' . h($spot['tag']) . '</dd>';
        }
        $nzb = count($spot['nzb_segments']) > 0
            ? count($spot['nzb_segments']) . ' NZB-segment(en). Download/forwarding wordt nog niet ondersteund.'
            : 'Geen NZB-verwijzing aanwezig.';
        $technical .= '<dt>Message-ID</dt><dd>' . h($spot['message_id']) . '</dd><dt>NZB</dt><dd>' . $nzb . '</dd>';
        $technical = '<section class="card mb-3"><div class="card-header">Technische details</div><div class="card-body"><dl class="technical">' . $technical . '</dl></div></section>';
        $notice = '<div class="alert alert-secondary small mb-0">Usenet-inhoud is onbevestigd. Spotterhandtekeningen worden nog niet geverifieerd. Opmaak wordt veilig als tekst weergegeven.</div>';
        return $hero . $metadata . ($preview ? $description . $technical : '<div class="detail-grid"><div>' . $description . '</div><div>' . $technical . '</div></div>') . $notice;
    }
}
