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
        int $pendingComments = 0,
        ?array $metadata = null
    ): string {
        $id = h($spot['id']);
        $summary = self::summary($spot, false, $imagesEnabled, $showComments, $metadata, metadataLink: $metadataControls !== '');
        $statusClass = $commentError ? 'warning' : 'info';
        $statusRole = $commentError ? 'alert' : 'status';
        $status = $statusMessage !== null ? '<div class="alert alert-' . $statusClass . '" role="' . $statusRole . '">' . h($statusMessage) . '</div>' : '';
        $commentStatus = $commentError ? $status : '';
        if ($commentError && $showComments && SpotLayout::can('comments.publish')) $status = '';
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
        $profileRevision = hash('sha256', ($profile !== null && $profile['username'] === $username ? $profile['display_name'] : $username) . "\0" . $signatureText);
        $reopenComposer = $commentError ? ' data-comment-reopen' : '';
        $signaturePreview = $signatureText === '' ? '' : '<details class="comment-signature small"><summary>Handtekening</summary>'
            . '<pre class="text-wrap mb-0" data-comment-signature>' . $postingSignature . '</pre></details>';
        $commentsSection = !$showComments ? '<section class="card mt-4"><div class="card-body text-body-secondary">Reacties zijn door de beheerder uitgeschakeld.</div></section>' : <<<HTML
<section class="card mt-4" id="comments">
  <div class="card-header d-flex justify-content-between align-items-center gap-2">
    <span>Reacties (<span data-comment-count>{$count}</span>)</span>
    <button class="btn btn-sm btn-primary" type="button" data-comment-open aria-controls="comment-composer" aria-haspopup="dialog">Reactie schrijven</button>
  </div>
  <div class="card-body">
    {$retrievalNotice}
    {$commentHtml}
  </div>
</section>
<dialog class="comment-composer" id="comment-composer" aria-labelledby="comment-composer-title"{$reopenComposer}>
  <div class="modal-content">
    <div class="modal-header">
      <h2 class="modal-title h5" id="comment-composer-title">Reactie schrijven</h2>
      <button class="btn-close" type="button" data-comment-close aria-label="Sluiten"></button>
    </div>
    <form method="post" action="/spots/comment" data-comment-form>
      {$csrfField}<input type="hidden" name="spot_id" value="{$id}">
      <input type="hidden" name="publication_id" value="{$publicationId}">
      <input type="hidden" name="profile_revision" value="{$profileRevision}">
      <div class="modal-body">
        {$commentStatus}
        <p class="small text-body-secondary">Als <strong>{$postingName}</strong></p>
        <label class="form-label visually-hidden" for="comment">Je reactie</label>
        <textarea class="form-control mb-2" name="comment" id="comment" rows="10" maxlength="10000" required autofocus>{$draft}</textarea>
        {$emoticons}
        {$signaturePreview}
        <div class="form-check mt-3"><input class="form-check-input" id="publish-consent" name="publish_consent" value="1" type="checkbox" required><label class="form-check-label small" for="publish-consent">Openbaar op Usenet plaatsen; achteraf verwijderen is niet mogelijk.</label></div>
        <div class="alert alert-info mt-2 mb-0" role="status" data-comment-progress hidden><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Reactie wordt verstuurd...</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-comment-close>Annuleren</button>
        <button class="btn btn-primary" type="submit" data-comment-submit>Reactie plaatsen</button>
      </div>
    </form>
  </div>
</dialog>
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
        $canQuote = SpotLayout::can('comments.publish');
        foreach ($comments as $comment) {
            $poster = h($comment['poster']);
            if (is_string($comment['spotter_id'] ?? null) && $comment['spotter_id'] !== '') {
                $poster .= ' (' . h($comment['spotter_id']) . ')';
            }
            $quote = $canQuote ? SpotMarkup::quote((string) $comment['poster'], (string) $comment['body']) : '';
            $quoteButton = $quote === '' ? '' : '<button class="btn btn-sm btn-outline-secondary comment-quote" type="button" data-comment-quote="' . h($quote)
                . '" title="Citeren in je reactie" aria-controls="comment-composer" aria-haspopup="dialog" aria-label="Reactie van ' . h($comment['poster']) . ' citeren">'
                . '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 12a1 1 0 0 0 1-1V8.558a1 1 0 0 0-1-1h-1.388q0-.527.062-1.054.093-.558.31-.992t.559-.683q.34-.279.868-.279V3q-.868 0-1.52.372a3.3 3.3 0 0 0-1.085.992 4.9 4.9 0 0 0-.62 1.458A7.7 7.7 0 0 0 9 7.558V11a1 1 0 0 0 1 1zm-6 0a1 1 0 0 0 1-1V8.558a1 1 0 0 0-1-1H4.612q0-.527.062-1.054.094-.558.31-.992.217-.434.559-.683.34-.279.868-.279V3q-.868 0-1.52.372a3.3 3.3 0 0 0-1.085.992 4.9 4.9 0 0 0-.62 1.458A7.7 7.7 0 0 0 3 7.558V11a1 1 0 0 0 1 1z"/></svg></button>';
            $html .= '<article class="border-bottom py-3"><div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-semibold">' . $poster
                . '</div><small class="text-body-secondary">' . h($comment['posted_at']) . ' UTC &middot; ' . h($comment['source'])
                . '</small></div>' . $quoteButton . '</div><div class="comment-body spot-description mt-2">' . ($comment['source'] === 'Usenet'
                    ? SpotDescription::render($comment['body']) : SpotEmoticons::plainText($comment['body'])) . '</div></article>';
        }
        if ($html === '' && $pendingComments === 0) {
            $html = '<p class="text-body-secondary mb-0">Nog geen reacties beschikbaar.</p>';
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
        foreach (array_slice($details['Genre'] ?? [], 0, $preview ? 2 : null) as $genre) {
            $chips .= '<span class="tag-chip">' . h($genre) . '</span>';
        }
        $website = $spot['website'] !== '' ? '<a href="' . h($spot['website']) . '" target="_blank" rel="noopener noreferrer">Website van de spot</a>' : '';
        $action = $preview
            ? '<a class="btn btn-sm btn-primary" href="/spots/view?key=' . rawurlencode($spot['id']) . '">' . ($showComments ? 'Volledige spot en reacties' : 'Volledige spot') . '</a>'
            : ($showComments ? '<a class="btn btn-sm btn-warning" href="#comments">Reacties</a>' : '');
        $action = \NnSpotty\Favorites\View::button($spot) . $action;
        if ($spot['nzb_segments'] !== []) {
            if (SpotLayout::can('nzb.download')) $action .= ' <a class="btn btn-sm btn-primary" href="/spots/nzb?key=' . rawurlencode($spot['id']) . '">NZB</a>';
            if (SpotLayout::can('nzb.forward')) $action .= ' ' . SpotLayout::sendButton($spot['id']);
        }
        $link = $metadataLink && !$preview ? ($spot['category'] === 'Geluid'
            ? \NnSpotty\Music\View::link() : \NnSpotty\Metadata\View::link()) : '';
        $buttons = $action;
        $action = $link . $action;
        $hero = ($preview ? SpotPresentation::quickActions($action) : '')
            . '<section class="spot-hero' . ($preview ? ' quick-hero' : '') . '">' . SpotPresentation::backdrop($spot, 'hero-backdrop', $imagesEnabled)
            . (!$preview && $action !== '' ? '<div class="hero-actions" role="group" aria-label="Spotacties">' . $action . '</div>' : '')
            . '<div class="hero-poster">' . $image . '</div><div class="hero-copy"><p class="text-body-secondary mb-2">'
            . $type . '</p><' . $tag . '>' . $title . '</' . $tag . '><div class="tag-row">' . $chips . '</div>'
            . ($classification['type'] === 'Films' || $classification['type'] === 'Series'
                ? ($preview ? SpotReleaseQuality::compact($spot['title']) : SpotReleaseQuality::render($spot['title'])) : '') . '</div></section>';
        $people = '';
        if ($metadata !== null) {
            if (($metadata['provider'] ?? '') === 'musicbrainz') {
                $hero = \NnSpotty\Music\View::summary($spot, $metadata, $action, $imagesEnabled, $preview);
            } else {
                $hero = \NnSpotty\Metadata\View::hero($spot, $metadata, $buttons, $imagesEnabled, $preview, $link);
                if ($preview) {
                    $cast = $metadata;
                    $cast['people'] = array_slice(array_values(array_filter($metadata['people'] ?? [], static fn (array $person): bool =>
                        str_contains(strtolower((string) ($person['type'] ?? '')), 'actor')
                        || str_contains(strtolower((string) ($person['role'] ?? '')), 'actor'))), 0, 8);
                    $cast['companies'] = [];
                    if ($cast['people'] !== []) {
                        $people = '<div class="quick-cast">' . \NnSpotty\Metadata\View::people($cast, $imagesEnabled) . '</div>';
                    }
                } else {
                    $people = \NnSpotty\Metadata\View::trailers($metadata) . \NnSpotty\Metadata\View::people($metadata, $imagesEnabled);
                }
            }
        }
        if ($preview) {
            return $hero . $people;
        }
        $spotter = h($spot['poster']);
        if (is_string($spot['spotter_id'] ?? null) && $spot['spotter_id'] !== '') {
            $spotter .= ' (' . h($spot['spotter_id']) . ')';
        }
        $spotMetadata = '<div class="metadata-strip"><div><small>Spotter</small>' . $spotter . \NnSpotty\Moderation\View::badge($spot)
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
            ? count($spot['nzb_segments']) . ' NZB-segment(en). Download of stuur naar je ingestelde downloadclient (SABnzbd of NZBGet).'
            : 'Geen NZB-verwijzing aanwezig.';
        $technical .= '<dt>Message-ID</dt><dd>' . h($spot['message_id']) . '</dd><dt>NZB</dt><dd>' . $nzb . '</dd>';
        $technical = '<section class="card mb-3"><div class="card-header">Technische details</div><div class="card-body"><dl class="technical">' . $technical . '</dl></div></section>';
        $notice = '<div class="alert alert-secondary small mb-0">Usenet-inhoud is onbevestigd. Spotterhandtekeningen worden nog niet geverifieerd. Alleen veilige omschrijvingsopmaak wordt weergegeven.</div>';
        return $hero . $spotMetadata . $people . '<div class="detail-grid"><div>' . $description . '</div><div>' . $technical . '</div></div>' . $notice;
    }
}
