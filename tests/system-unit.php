<?php

declare(strict_types=1);

use NnSpotty\Config\Release;
use NnSpotty\System\Releases;
use NnSpotty\System\View as SystemView;

check(Releases::origin('https://github.com/Goeny/NNSpotty.git')['api'] === 'https://api.github.com/repos/Goeny/NNSpotty/releases?per_page=100',
    'GitHub-origin gebruikt gepubliceerde Releases API');
check(Releases::origin('git@github.com:Goeny/NNSpotty.git')['provider'] === 'github'
    && Releases::origin('ssh://git@github.com/Goeny/NNSpotty.git')['provider'] === 'github',
    'SSH-clones gebruiken HTTPS voor releasecontrole');
check(Releases::origin('https://gitlab.example.invalid/group/sub/project.git')['api']
    === 'https://gitlab.example.invalid/api/v4/projects?search=project&simple=true&per_page=100',
    'GitLab-projectzoekactie vermijdt geencodeerde slashes in URL-paden');
foreach (['file:///private/repo', 'https://token@github.com/owner/project', 'https://user:password@gitlab.example.invalid/a/b',
    'https://github.com/a/b?token=secret', 'https://github.com/a/../b', 'https://github.com/only-owner',
    'http://github.com/a/b', '-upload-pack=malicious'] as $invalidOrigin) {
    try { Releases::origin($invalidOrigin); check(false, 'Onveilige origin moet falen'); }
    catch (RuntimeException) { check(true, 'Onveilige/ongeldige origin afgewezen'); }
}
$releaseFixture = static fn (string $tag, array $extra = []): array => $extra + [
    'tag_name' => $tag, 'name' => 'Release <' . $tag . '>', 'published_at' => '2020-01-01T00:00:00Z',
    'released_at' => '2020-01-01T00:00:00Z',
];
$releases = [$releaseFixture('v0.0.2'), $releaseFixture('0.10.0'), $releaseFixture('v0.2.0'),
    $releaseFixture('v100.0.0', ['draft' => true]), $releaseFixture('v200.0.0', ['prerelease' => true]),
    $releaseFixture('v300.0.0', ['upcoming_release' => true]), $releaseFixture('v400.0.0', ['published_at' => '2099-01-01T00:00:00Z']),
    $releaseFixture('v500.0.0-beta.1'), $releaseFixture('main'), $releaseFixture('v01.0.0')];
$latest = Releases::select($releases, 'github', 'https://github.com/example/project');
check($latest['version'] === '0.10.0' && $latest['tag'] === '0.10.0',
    'SemVer-vergelijking negeert drafts, prereleases, toekomstige en ongeldige tags');
check(Releases::select([], 'github', 'https://github.com/example/project') === null,
    'Geen releases is expliciet leeg, geen branchfallback');
check(Releases::select([$releaseFixture('v1.0.0')], 'gitlab', 'https://gitlab.example.invalid/a/b')['url']
    === 'https://gitlab.example.invalid/a/b/-/releases/v1.0.0', 'GitLab-releasekoppeling is correct');
try { Releases::select([['tag_name' => 'v1.0.0']], 'github', 'https://github.com/example/project'); check(false, 'Datum moet verplicht zijn'); }
catch (RuntimeException) { check(true, 'Onvolledige release geeft expliciete fout'); }
$oldVersion = getenv('APP_VERSION');
putenv('APP_VERSION=999.999.999');
check(\NnSpotty\Config\AppConfig::fromEnvironment()->version === Release::VERSION,
    'Verouderde env-versie kan geinstalleerde releaseversie niet overschrijven');
putenv($oldVersion === false ? 'APP_VERSION' : 'APP_VERSION=' . $oldVersion);
$status = ['current' => Release::VERSION, 'codename' => Release::CODENAME, 'release' => $latest,
    'available' => true, 'error' => null, 'recovery' => false];
$html = SystemView::render($status, '<input name="_csrf" value="fixture">');
check(str_contains($html, 'Release &lt;0.10.0&gt;') && str_contains($html, 'name="backup"')
    && str_contains($html, 'data-system-update') && str_contains($html, 'name="_csrf"'),
    'GUI escaping, expliciete backupbevestiging en CSRF in installatieformulier');
check(!str_contains(SystemView::render(array_replace($status, ['available' => false]), ''), 'data-system-update'),
    'GUI biedt geen onnodige herinstallatie zonder nieuwe release of herstel');
check(!str_contains(SystemView::render(array_replace($status, ['error' => 'Controle mislukt']), ''), 'data-system-update'),
    'Releasecontrolefout wordt geen installeerbaar succes');
$updateAccount = ['username' => 'reader', 'active' => true, 'is_admin' => false, 'must_change_password' => false,
    'permissions' => [], 'display_name' => 'Reader', 'has_avatar' => false];
\NnSpotty\UI\SpotLayout::account($updateAccount);
check(!str_contains(\NnSpotty\UI\SpotLayout::render('Test', 'reader', false, '', ''), 'data-update-notice'),
    'Geen updaterlink/script zonder groepsrecht');
\NnSpotty\UI\SpotLayout::account(array_replace($updateAccount, ['permissions' => ['system.update']]));
$updateLayout = \NnSpotty\UI\SpotLayout::render('Test', 'reader', false, '', '');
check(str_contains($updateLayout, 'data-update-notice') && str_contains($updateLayout, '/assets/updater.js')
    && str_contains($updateLayout, Release::VERSION . ' &middot; ' . Release::CODENAME),
    'Toekenbaar groepsrecht toont releasemelding naast centrale versie/codenaam');
\NnSpotty\UI\SpotLayout::account(null);
