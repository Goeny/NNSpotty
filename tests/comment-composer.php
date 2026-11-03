<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'NnSpotty\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
    }
});
require __DIR__ . '/../src/helpers.php';

use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotLayout;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$spot = [
    'id' => str_repeat('a', 64), 'title' => 'Comment fixture', 'category' => 'Beeld', 'subcategories' => [],
    'image_segments' => [], 'image_url' => '', 'website' => '', 'nzb_segments' => [], 'poster' => 'Fixture',
    'posted_at' => '2026-11-03 00:00:00', 'size' => 1000, 'description' => '', 'tag' => '', 'message_id' => 'fixture@example.invalid',
];
$comments = [['poster' => 'Other reader', 'body' => 'A quoted comment', 'source' => 'Usenet', 'posted_at' => '2026-11-03 10:00:00']];
$render = static fn (bool $error = false): string => SpotDetailPageRenderer::render(
    'reader', false, $spot, $comments, '<input type="hidden" name="_csrf" value="fixture">',
    statusMessage: $error ? 'Publiceren mislukt; probeer opnieuw.' : null,
    commentDraft: $error ? 'Saved draft <safe>' : '',
    commentRequestId: str_repeat('b', 64), commentError: $error
);
if (PHP_SAPI === 'cli-server') {
    if (str_starts_with($_SERVER['REQUEST_URI'], '/assets/')) return false;
    header('Content-Type: text/html; charset=utf-8');
    echo $render(isset($_GET['error']));
    return;
}

$checks = 0;
function expectComposer(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}

$html = $render();
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
$xpath = new DOMXPath($dom);
expectComposer($xpath->query('//section[@id="comments"]//button[@data-comment-open]')->length === 1,
    'Write action appears above the comment list');
expectComposer($xpath->query('//section[@id="comments"]//form[@data-comment-form]')->length === 0
    && $xpath->query('//dialog[@id="comment-composer"]//form[@data-comment-form]')->length === 1,
    'Publication form is only inside a labelled dialog, not the detail comment block');
expectComposer($xpath->query('//dialog[@open]')->length === 0, 'Composer starts closed');
expectComposer($xpath->query('//button[@data-comment-quote]')->length === 1, 'Existing comments retain their quote action');
foreach (['_csrf', 'spot_id', 'publication_id', 'profile_revision', 'publish_consent', 'comment'] as $name) {
    expectComposer($xpath->query('//dialog[@id="comment-composer"]//*[@name="' . $name . '"]')->length === 1, 'Publication field retained: ' . $name);
}
expectComposer(substr_count($html, 'data-emoticon-code=') === 28, 'Composer retains all emoticons');
expectComposer(!str_contains($html, 'UTF-8-bytes') && !str_contains($html, 'comments:free.usenet')
    && !str_contains($html, 'Volledige publicatietekst'), 'Technical instructions and duplicated preview removed');
$error = $render(true);
expectComposer(str_contains($error, 'data-comment-reopen') && str_contains($error, 'Saved draft &lt;safe&gt;')
    && substr_count($error, 'Publiceren mislukt; probeer opnieuw.') === 1,
    'Failed publication reopens composer with escaped draft, same publication ID and one error');
expectComposer(str_contains($error, 'value="' . str_repeat('b', 64) . '"'), 'Idempotency token survives publication errors');
expectComposer(SpotDetailPageRenderer::commentList([], true, true) === '<p class="text-body-secondary mb-0">Nog geen reacties beschikbaar.</p>',
    'Empty state is concise, including for admins');
SpotLayout::account(['username' => 'reader', 'display_name' => 'Public reader', 'signature' => 'My signature',
    'has_avatar' => false, 'is_admin' => true, 'active' => true, 'must_change_password' => false, 'permissions' => []]);
$withSignature = $render();
expectComposer(str_contains($withSignature, 'data-comment-signature>My signature')
    && !str_contains($withSignature, '<details class="comment-signature small" open'),
    'Signature remains available but collapsed by default');
SpotLayout::account(['username' => 'reader', 'display_name' => 'Reader', 'signature' => '', 'has_avatar' => false,
    'is_admin' => false, 'active' => true, 'must_change_password' => false, 'permissions' => []]);
$denied = $render();
expectComposer(!str_contains($denied, 'id="comment-composer"') && !str_contains($denied, 'data-comment-open')
    && !str_contains($denied, 'data-comment-quote'), 'Read-only users receive neither composer nor quote actions');
SpotLayout::account(null);
expectComposer(!str_contains(SpotDetailPageRenderer::render('reader', false, $spot, [], '', showComments: false), 'id="comment-composer"'),
    'Disabled comments never render a composer');
echo "Comment composer: $checks checks passed.\n";
