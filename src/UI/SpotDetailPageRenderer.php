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
        string $theme = 'default',
        bool $showErotica = false,
        bool $showComments = true,
        bool $imagesEnabled = true,
        bool $retrieveComments = true
    ): string {
        $id = h($spot['id']);
        $summary = self::summary($spot, false, $imagesEnabled, $showComments);
        $status = $statusMessage !== null ? '<div class="alert alert-info" role="status">' . h($statusMessage) . '</div>' : '';
        $commentHtml = '';
        foreach ($showComments ? $comments : [] as $comment) {
            $commentHtml .= '<article class="border-bottom py-3"><div class="fw-semibold">' . h($comment['poster'])
                . '</div><small class="text-body-secondary">' . h($comment['posted_at']) . ' UTC &middot; ' . h($comment['source'])
                . '</small><div class="comment-body spot-description mt-2">' . ($comment['source'] === 'Usenet'
                    ? SpotDescription::render($comment['body']) : SpotEmoticons::plainText($comment['body'])) . '</div></article>';
        }
        if ($commentHtml === '') {
            $commentHtml = '<p class="text-body-secondary">Nog geen reacties opgehaald of geplaatst. Dit betekent niet dat er op Usenet geen reacties zijn.</p>';
            if ($isAdmin && $retrieveComments) {
                $commentHtml .= '<p class="small text-body-secondary">Controleer of comments:free.usenet is ingesteld. Haal reacties afzonderlijk op met '
                    . '<code>php bin/console retrieve --comments-only --days=7 --verbose</code>. Een normale run verwerkt eerst de spotgroep.</p>';
            }
        }
        $count = count($comments);
        $retrievalNotice = !$retrieveComments ? '<div class="alert alert-info small">Usenet-reacties ophalen is uitgeschakeld. Hieronder staan alleen eerder opgeslagen en lokale reacties.</div>' : '';
        $commentsSection = !$showComments ? '<section class="card mt-4"><div class="card-body text-body-secondary">Reacties zijn door de beheerder uitgeschakeld.</div></section>' : <<<HTML
<section class="card mt-4" id="comments">
  <div class="card-header">Reacties ({$count})</div>
  <div class="card-body">
    {$retrievalNotice}
    {$commentHtml}
    <form method="post" action="/spots/comment" class="mt-3">
      {$csrfField}<input type="hidden" name="spot_id" value="{$id}">
      <label class="form-label" for="comment">Lokale reactie plaatsen</label>
      <textarea class="form-control mb-2" name="comment" id="comment" rows="4" maxlength="10000" required></textarea>
      <p class="form-text">Emoticoncodes zoals [img=smile], [img=buigen], [img=klappen] en [img=respekt] worden als moderne emoji's weergegeven.</p>
      <p class="form-text">Deze reactie blijft in NNSpotty en wordt niet op Usenet gepubliceerd.</p>
      <button class="btn btn-primary" type="submit">Reactie plaatsen</button>
    </form>
  </div>
</section>
HTML;
        $content = <<<HTML
<a class="btn btn-sm btn-outline-secondary mb-3" href="/spots">Terug naar spots</a>
{$status}
{$summary}
{$commentsSection}
HTML;
        return SpotLayout::render($spot['title'], $username, $isAdmin, $csrfField, $content, $categories, $spot['category'], $theme, showErotica: $showErotica);
    }

    /** @param array<string, mixed> $spot */
    public static function summary(array $spot, bool $preview, bool $imagesEnabled = true, bool $showComments = true): string
    {
        $image = SpotPresentation::image($spot, false, $imagesEnabled);
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
            ? '<a class="btn btn-primary" href="/spots/view?key=' . rawurlencode($spot['id']) . '">' . ($showComments ? 'Volledige spot en reacties' : 'Volledige spot') . '</a>'
            : ($showComments ? '<a class="btn btn-warning" href="#comments">Bekijk reacties</a>' : '');
        if ($spot['nzb_segments'] !== []) {
            $action .= ' <a class="btn btn-primary" href="/spots/nzb?key=' . rawurlencode($spot['id']) . '">NZB downloaden</a>';
            $action .= ' <button class="btn btn-warning" type="button" data-sab-send="' . h($spot['id']) . '">Naar SABnzbd</button>';
        }
        $hero = '<section class="spot-hero">' . SpotPresentation::backdrop($spot, 'hero-backdrop', $imagesEnabled)
            . '<div class="hero-poster">' . $image . '</div><div class="hero-copy"><p class="text-body-secondary mb-2">'
            . $type . '</p><' . $tag . '>' . $title . '</' . $tag . '><div class="tag-row">' . $chips . '</div>' . $action . '</div></section>';
        $metadata = '<div class="metadata-strip"><div><small>Spotter</small>' . h($spot['poster'])
            . '</div><div><small>Geplaatst (UTC)</small>' . h($spot['posted_at']) . '</div><div><small>Grootte</small>'
            . SpotPresentation::size((int) $spot['size']) . '</div></div>';
        $description = '<section class="card mb-3"><div class="card-header d-flex justify-content-between gap-2"><span>Omschrijving</span>' . $website
            . '</div><div class="card-body spot-description">' . SpotDescription::render($spot['description']) . '</div></section>';
        $technical = '<dt>Hoofdcategorie</dt><dd>' . h($spot['category']) . '</dd>';
        foreach ($details as $name => $values) {
            $technical .= '<dt>' . h($name) . '</dt><dd>' . h(implode(', ', $values)) . '</dd>';
        }
        if ($spot['tag'] !== '') {
            $technical .= '<dt>Tag</dt><dd>' . h($spot['tag']) . '</dd>';
        }
        if (($spot['newsreader'] ?? '') !== '') {
            $technical .= '<dt>Gepost met</dt><dd>' . h($spot['newsreader']) . '</dd>';
        }
        $nzb = count($spot['nzb_segments']) > 0
            ? count($spot['nzb_segments']) . ' NZB-segment(en). Download of stuur naar je ingestelde SABnzbd-koppeling.'
            : 'Geen NZB-verwijzing aanwezig.';
        $technical .= '<dt>Message-ID</dt><dd>' . h($spot['message_id']) . '</dd><dt>NZB</dt><dd>' . $nzb . '</dd>';
        $technical = '<section class="card mb-3"><div class="card-header">Technische details</div><div class="card-body"><dl class="technical">' . $technical . '</dl></div></section>';
        $notice = '<div class="alert alert-secondary small mb-0">Usenet-inhoud is onbevestigd. Spotterhandtekeningen worden nog niet geverifieerd. Alleen veilige omschrijvingsopmaak wordt weergegeven.</div>';
        return $hero . $metadata . ($preview ? $description . $technical : '<div class="detail-grid"><div>' . $description . '</div><div>' . $technical . '</div></div>') . $notice;
    }
}
