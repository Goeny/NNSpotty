<?php

declare(strict_types=1);

use NnSpotty\Usenet\NntpClientFactory;
use NnSpotty\UI\AdminPageRenderer;

$readingConnection = ['server' => 'news.example.invalid', 'port' => 119, 'encryption' => 'none',
    'username' => 'reader', 'password' => 'read-secret'];
check(NntpClientFactory::postingSettings($readingConnection) === $readingConnection,
    'Bestaande installaties publiceren via de standaardverbinding zonder nieuwe instellingen');
$separatePosting = ['posting_server' => 'post.example.invalid', 'posting_port' => 563, 'posting_encryption' => 'ssl',
    'posting_custom_credentials' => false, 'posting_username' => 'ignored', 'posting_password' => 'ignored-secret'];
$postingConnection = NntpClientFactory::postingSettings($readingConnection + $separatePosting);
check($postingConnection === ['server' => 'post.example.invalid', 'port' => 563, 'encryption' => 'ssl',
    'username' => 'reader', 'password' => 'read-secret'],
    'Aparte postingserver gebruikt eigen transport en standaard de bestaande login');
check(NntpClientFactory::postingSettings(array_replace($readingConnection, $separatePosting,
    ['username' => 'changed', 'password' => 'changed-secret']))['password'] === 'changed-secret',
    'Overgenomen postinglogin volgt wijzigingen aan de standaardlogin');
$customPosting = array_replace($readingConnection, $separatePosting,
    ['posting_custom_credentials' => true, 'posting_username' => 'poster', 'posting_password' => 'post-secret']);
check(NntpClientFactory::postingSettings($customPosting)['username'] === 'poster'
    && NntpClientFactory::postingSettings($customPosting)['password'] === 'post-secret',
    'Expliciete postinglogin vervangt beide standaardcredentials');
check(NntpClientFactory::postingSettings(array_replace($customPosting, ['posting_server' => '  '])) === $readingConnection,
    'Lege postingserver schakelt ook afwijkende credentials volledig uit');
foreach ([
    ['posting_port' => 0], ['posting_port' => 65536], ['posting_port' => '563'],
    ['posting_encryption' => 'invalid'], ['posting_custom_credentials' => '1'],
    ['posting_server' => "post.invalid\r\nQUIT"], ['posting_server' => 'ssl://post.invalid'],
    ['posting_server' => 'post.invalid/path'], ['posting_server' => ['post.invalid']],
    ['posting_username' => ''], ['posting_password' => ''],
    ['posting_username' => "poster\r\nQUIT"], ['posting_password' => "secret\nQUIT"],
] as $invalidPosting) {
    rejects(static fn () => NntpClientFactory::postingSettings(array_replace($customPosting, $invalidPosting)),
        'Ongeldige postinginstelling expliciet geweigerd: ' . array_key_first($invalidPosting));
}
foreach ([1, 65535] as $postingBoundaryPort) {
    check(NntpClientFactory::postingSettings(array_replace($customPosting, ['posting_port' => $postingBoundaryPort]))['port'] === $postingBoundaryPort,
        'Postingpoort accepteert grens ' . $postingBoundaryPort);
}
$postingAdminSettings = $readingConnection + $customPosting + [
    'retention' => 30, 'retrieve_after' => null, 'explicit_groups' => 'spots:free.pt,comments:free.usenet',
    'group_pattern' => '', 'discover_max_groups' => 0, 'max_articles_per_group' => 100,
];
$postingAdminPage = AdminPageRenderer::render($postingAdminSettings, ['whitelist' => [], 'blacklist' => []], [], [], null, 'admin', '');
check(str_contains($postingAdminPage, 'name="posting_server" type="text" value="post.example.invalid"')
    && str_contains($postingAdminPage, 'name="posting_custom_credentials" type="checkbox" value="1" checked')
    && str_contains($postingAdminPage, 'name="posting_password" type="password" value="post-secret"'),
    'Admin toont postingtransport en expliciet gekozen postinglogin in hetzelfde opslagformulier');
$postingAdminPage = AdminPageRenderer::render(array_replace($postingAdminSettings,
    ['posting_server' => '"><script>', 'posting_password' => '"><script>']), ['whitelist' => [], 'blacklist' => []], [], [], null, 'admin', '');
check(!str_contains($postingAdminPage, 'value=""><script>') && str_contains($postingAdminPage, '&quot;&gt;&lt;script&gt;'),
    'Postingvelden zijn HTML-escaped');
