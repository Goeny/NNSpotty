<?php

declare(strict_types=1);

namespace NnSpotty\UI;

use NnSpotty\Usenet\SpotCategories;

final class SpotDetailPageRenderer
{
    /** @param array<string, mixed> $spot
     *  @param list<array<string, mixed>> $comments
     *  @param list<array<string, mixed>> $categories
     *  @param list<array<string, mixed>>|null $facets
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
        bool $retrieveComments = true,
        string $metadataControls = '',
        ?array $facets = [],
        string $commentDraft = '',
        ?string $commentRequestId = null,
        bool $commentError = false,
        int $pendingComments = 0
    ): string {
        $id = h($spot['id']);
        $summary = self::summary($spot, false, $imagesEnabled, $showComments, metadataLink: $metadataControls !== '');
        $statusClass = $commentError ? 'warning' : 'info';
        $statusRole = $commentError ? 'alert' : 'status';
        $status = $statusMessage !== null ? '<div class="alert alert-' . $statusClass . '" role="' . $statusRole . '">' . h($statusMessage) . '</div>' : '';
        $commentHtml = '<div data-comment-list>' . self::commentList($showComments ? $comments : [], $isAdmin, $retrieveComments, $pendingComments) . '</div>'
            . self::commentLoader($spot['id'], $pendingComments, $csrfField);
        $count = count($comments);
        $emoticons = SpotEmoticons::toolbar('comment');
        $draft = h($commentDraft);
        $publicationId = h($commentRequestId ?? bin2hex(random_bytes(32)));
        $retrievalNotice = !$retrieveComments ? '<div class="alert alert-info small">Usenet-reacties ophalen is uitgeschakeld. Hieronder staan alleen eerder opgeslagen en lokale reacties.</div>' : '';
        $profile = SpotLayout::currentAccount();
        $postingName = h($profile !== null && $profile['username'] === $username ? $profile['display_name'] : $username);
        $signatureText = $profile !== null && $profile['username'] === $username ? $profile['signature'] : '';
        $postingSignature = h($signatureText);
        $fullDraft = h(trim($commentDraft) . ($signatureText !== '' ? "\n\n-- \n" . $signatureText : ''));
        $profileRevision = hash('sha256', ($profile !== null && $profile['username'] === $username ? $profile['display_name'] : $username) . "\0" . $signatureText);
        $commentsSection = !$showComments ? '<section class="card mt-4"><div class="card-body text-body-secondary">Reacties zijn door de beheerder uitgeschakeld.</div></section>' : <<<HTML
<section class="card mt-4" id="comments">
  <div class="card-header">Reacties (<span data-comment-count>{$count}</span>)</div>
  <div class="card-body">
    {$retrievalNotice}
    {$commentHtml}
    <form method="post" action="/spots/comment" class="mt-3" data-comment-form>
      {$csrfField}<input type="hidden" name="spot_id" value="{$id}">
      <input type="hidden" name="publication_id" value="{$publicationId}">
      <input type="hidden" name="profile_revision" value="{$profileRevision}">
      <label class="form-label" for="comment">Reactie op Usenet plaatsen</label>
      <textarea class="form-control mb-2" name="comment" id="comment" rows="4" maxlength="10000" aria-describedby="comment-help" required>{$draft}</textarea>
      {$emoticons}
      <p class="form-text" id="comment-help">Klik op een emoticon om de bijbehorende [img=...]-code in te voegen. Maximaal 10000 UTF-8-bytes en 900 bytes per regel; West-Europese tekst voor klassieke Spotnet-readers.</p>
      <div class="alert alert-warning small">Je reactie, publieke naam en ingestelde handtekening worden openbaar op Usenet, ook voor andere Spotnet-/Spotweb-gebruikers. Publicatie is niet via NNSpotty in te trekken; verspreiding hangt af van de provider.</div>
      <p class="small">Publiceren als: <strong>{$postingName}</strong>. Publieke namen zijn niet uniek; een naam bewijst geen identiteit.</p>
      <details class="mb-3" open><summary>Volledige publicatietekst</summary><pre class="text-wrap" data-comment-preview>{$fullDraft}</pre></details>
      <div class="small mb-3">Vaste handtekening (altijd toegevoegd indien ingesteld):<pre class="text-wrap" data-comment-signature>{$postingSignature}</pre></div>
      <div class="form-check mb-3"><input class="form-check-input" id="publish-consent" name="publish_consent" value="1" type="checkbox" required><label class="form-check-label" for="publish-consent">Ik wil deze reactie openbaar op Usenet publiceren.</label></div>
      <button class="btn btn-primary" type="submit" data-comment-submit>Op Usenet publiceren</button>
      <div class="alert alert-info mt-2" role="status" data-comment-progress hidden><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Reactie ondertekenen en naar Usenet versturen...</div>
    </form>
  </div>
</section>
HTML;
        if ($showComments && !SpotLayout::can('comments.publish')) {
            $commentsSection = '<section class="card mt-4" id="comments"><div class="card-header">Reacties</div><div class="card-body">'
                . $retrievalNotice . $commentHtml . '<p class="text-body-secondary">Je account heeft geen recht om reacties te publiceren.</p></div></section>';
        }
        $content = <<<HTML
<a class="btn btn-sm btn-outline-secondary mb-3" href="/spots">Terug naar spots</a>
{$status}
<div data-tvdb-content>{$summary}</div>
{$commentsSection}
{$metadataControls}
HTML;
        return SpotLayout::render($spot['title'], $username, $isAdmin, $csrfField, $content, $categories, $spot['category'], $theme, facets: $facets, showErotica: $showErotica);
    }

    /** @param list<array<string, mixed>> $comments */
    public static function commentList(array $comments, bool $isAdmin, bool $retrieveComments, int $pendingComments = 0): string
    {
        $html = '';
        foreach ($comments as $comment) {
            $html .= '<article class="border-bottom py-3"><div class="fw-semibold">' . h($comment['poster'])
                . '</div><small class="text-body-secondary">' . h($comment['posted_at']) . ' UTC &middot; ' . h($comment['source'])
                . '</small><div class="comment-body spot-description mt-2">' . ($comment['source'] === 'Usenet'
                    ? SpotDescription::render($comment['body']) : SpotEmoticons::plainText($comment['body'])) . '</div></article>';
        }
        if ($html === '' && $pendingComments === 0) {
            $html = '<p class="text-body-secondary">Nog geen reacties opgehaald of geplaatst. Dit betekent niet dat er op Usenet geen reacties zijn.</p>';
            if ($isAdmin && $retrieveComments) {
                $html .= '<p class="small text-body-secondary">Controleer of comments:free.usenet is ingesteld. Haal reacties afzonderlijk op met '
                    . '<code>php bin/console retrieve --comments-only --days=7 --verbose</code>. Een normale run verwerkt eerst de spotgroep.</p>';
            }
        }
        return $html;
    }

    public static function commentLoader(string $spotId, int $pendingComments, string $csrfField): string
    {
        if ($pendingComments < 1) {
            return '';
        }
        $label = $pendingComments === 1 ? '1 reactie' : $pendingComments . ' reacties';
        if (!SpotLayout::can('comments.load')) {
            return '<p class="text-body-secondary small mt-3" data-comment-loader>' . $label
                . ' van Usenet nog niet geladen. Je account heeft geen recht om reacties van Usenet te laden.</p>';
        }
        $id = h($spotId);
        return <<<HTML
<form method="post" action="/spots/comments/load" class="mt-3" data-comment-loader>
  {$csrfField}<input type="hidden" name="spot_id" value="{$id}">
  <button class="btn btn-outline-primary btn-sm" type="submit" data-comment-load-button>{$label} van Usenet laden</button>
  <div class="alert alert-info small mt-2 mb-0" role="status" data-comment-load-progress hidden><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Reacties worden van Usenet geladen...</div>
  <div class="alert alert-warning small mt-2 mb-0" role="alert" data-comment-load-error hidden></div>
</form>
HTML;
    }

    /** @param array<string, mixed> $spot */
    public static function summary(array $spot, bool $preview, bool $imagesEnabled = true, bool $showComments = true, ?array $metadata = null, bool $metadataLink = false): string
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
            ? '<a class="btn btn-sm btn-primary" href="/spots/view?key=' . rawurlencode($spot['id']) . '">' . ($showComments ? 'Volledige spot en reacties' : 'Volledige spot') . '</a>'
            : ($showComments ? '<a class="btn btn-sm btn-warning" href="#comments">Reacties</a>' : '');
        $action = \NnSpotty\Favorites\View::button($spot) . $action;
        if ($spot['nzb_segments'] !== []) {
            if (SpotLayout::can('nzb.download')) $action .= ' <a class="btn btn-sm btn-primary" href="/spots/nzb?key=' . rawurlencode($spot['id']) . '">NZB</a>';
            if (SpotLayout::can('nzb.forward')) $action .= ' <button class="btn btn-sm btn-warning" type="button" data-sab-send="' . h($spot['id']) . '">SABnzbd</button>';
        }
        if ($metadataLink && !$preview) $action = ($spot['category'] === 'Geluid'
            ? \NnSpotty\Music\View::link() : \NnSpotty\Metadata\View::link()) . $action;
        $hero = '<section class="spot-hero">' . SpotPresentation::backdrop($spot, 'hero-backdrop', $imagesEnabled)
            . ($action !== '' ? '<div class="hero-actions" role="group" aria-label="Spotacties">' . $action . '</div>' : '')
            . '<div class="hero-poster">' . $image . '</div><div class="hero-copy"><p class="text-body-secondary mb-2">'
            . $type . '</p><' . $tag . '>' . $title . '</' . $tag . '><div class="tag-row">' . $chips . '</div>'
            . ($classification['type'] === 'Films' || $classification['type'] === 'Series' ? SpotReleaseQuality::render($spot['title']) : '') . '</div></section>';
        $people = '';
        if ($metadata !== null) {
            if (($metadata['provider'] ?? '') === 'musicbrainz') {
                $hero = \NnSpotty\Music\View::summary($spot, $metadata, $action, $imagesEnabled, $preview);
            } else {
                $hero = \NnSpotty\Metadata\View::hero($spot, $metadata, $action, $imagesEnabled, $preview);
                $people = ($preview ? '' : \NnSpotty\Metadata\View::trailers($metadata)) . \NnSpotty\Metadata\View::people($metadata, $imagesEnabled);
            }
        }
        $spotMetadata = '<div class="metadata-strip"><div><small>Spotter</small>' . h($spot['poster']) . \NnSpotty\Moderation\View::badge($spot)
            . '</div><div><small>Geplaatst (UTC)</small>' . h($spot['posted_at']) . '</div><div><small>Grootte</small>'
            . SpotPresentation::size((int) $spot['size']) . '</div></div>';
        $description = '<section class="card mb-3"><div class="card-header d-flex justify-content-between gap-2"><span>Omschrijving</span>' . $website
            . '</div><div class="card-body spot-description">' . SpotDescription::render($spot['description']) . '</div></section>';
        $technical = '<dt>Hoofdcategorie</dt><dd>' . h($spot['category']) . '</dd>';
        $signature = ($spot['signature_state'] ?? 'missing') === 'verified'
            ? 'Message-ID-handtekening gecontroleerd; inhoud niet gecontroleerd'
            : 'Identiteit niet bevestigd (handtekening ontbreekt, is ongeldig of wordt niet ondersteund)';
        $technical .= '<dt>Spotteridentiteit</dt><dd>' . h($signature) . '</dd>';
        if (($spot['signature_state'] ?? '') === 'verified' && isset($spot['spotter_key'])) {
            $technical .= '<dt>Sleutelfingerprint (SHA256)</dt><dd class="text-break"><code>' . h($spot['spotter_key']) . '</code></dd>';
        }
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
        return $hero . $spotMetadata . $people . ($preview ? $description . $technical : '<div class="detail-grid"><div>' . $description . '</div><div>' . $technical . '</div></div>') . $notice;
    }
}
