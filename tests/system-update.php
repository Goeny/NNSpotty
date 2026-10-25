<?php

declare(strict_types=1);

use NnSpotty\System\Process;
use NnSpotty\System\Releases;
use NnSpotty\System\Updater;

(static function (): void {
$current = \NnSpotty\Config\Release::VERSION;
$parts = explode('.', $current);
$parts[2] = (string) ((int) $parts[2] + 1);
$next = implode('.', $parts);
$tag = 'v' . $next;
$updateTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nnspotty-updater-' . bin2hex(random_bytes(8));
mkdir($updateTemp);
$process = new Process();
$git = static fn (array $args, string $cwd): string => $process->require(['git', ...$args], $cwd);
$write = static function (string $path, string $content): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    if (file_put_contents($path, $content) === false) throw new RuntimeException('Cannot write updater fixture');
};
$throwsUpdate = static function (callable $call, string $contains, string $label): void {
    try { $call(); check(false, $label); }
    catch (RuntimeException $exception) { check(str_contains($exception->getMessage(), $contains), $label . ': ' . $exception->getMessage()); }
};
try {
    $source = $updateTemp . '/source';
    mkdir($source);
    $git(['init', '-b', 'main'], $source);
    $git(['config', 'user.name', 'NNSpotty fixture'], $source);
    $git(['config', 'user.email', 'fixture@example.invalid'], $source);
    $manifest = static fn (string $version): string => "<?php\nnamespace NnSpotty\\Config;\nfinal class Release { public const VERSION = '$version'; public const CODENAME = 'Fixture $version'; }\n";
    $write($source . '/src/Config/Release.php', $manifest($current));
    $write($source . '/.gitignore', "/var/\n/composer.phar\n");
    $consoleFixture = <<<'PHP'
<?php
require dirname(__DIR__) . '/src/Config/Release.php';
$version = \NnSpotty\Config\Release::VERSION;
$step = ($argv[1] ?? '') . ':' . ($argv[2] ?? '');
file_put_contents(dirname(__DIR__) . '/var/steps', $step . ':' . $version . "\n", FILE_APPEND);
if (is_file(dirname(__DIR__) . '/var/fail-migration') && $step === 'db:migrate') { fwrite(STDERR, 'Synthetic migration failure'); exit(1); }
if ($step === 'system:verify' && ($argv[3] ?? '') !== '--version=' . $version) exit(2);
if ($step === 'db:migrate') file_put_contents(dirname(__DIR__) . '/var/migrated', $version);
echo $step . ' ' . $version;
PHP;
    $write($source . '/bin/console', $consoleFixture);
    $git(['add', '.'], $source);
    $git(['commit', '-m', 'Baseline'], $source);
    $baseline = $git(['rev-parse', 'HEAD'], $source);
    $write($source . '/src/Config/Release.php', $manifest($next));
    $git(['add', '.'], $source);
    $git(['commit', '-m', 'Release'], $source);
    $git(['tag', $tag], $source);
    $releaseCommit = $git(['rev-parse', 'HEAD'], $source);
    $write($source . '/unreleased.txt', 'Do not install development commits');
    $git(['add', '.'], $source);
    $git(['commit', '-m', 'Unreleased development work'], $source);
    $clone = static function (string $name) use ($updateTemp, $source, $baseline, $git, $write): string {
        $root = $updateTemp . '/' . $name;
        $git(['clone', '--no-local', $source, $root], $updateTemp);
        $git(['checkout', '--detach', $baseline], $root);
        $git(['remote', 'set-url', 'origin', 'https://github.com/example/project.git'], $root);
        $git(['config', 'url.' . str_replace('\\', '/', $source) . '.insteadOf', 'https://github.com/example/project.git'], $root);
        mkdir($root . '/var');
        $write($root . '/composer.phar', <<<'PHP'
<?php
if (in_array('--version', $argv, true)) { echo 'Composer fixture'; exit; }
file_put_contents(__DIR__ . '/var/composer-args', json_encode($argv));
if (is_file(__DIR__ . '/var/fail-composer')) { fwrite(STDERR, 'Synthetic Composer failure'); exit(1); }
echo 'Composer install fixture';
PHP);
        return $root;
    };
    $fetch = static fn (string $url): array => [['tag_name' => $tag, 'name' => 'Fixture', 'published_at' => '2020-01-01T00:00:00Z']];
    $releases = new Releases($process, $fetch);
    $log = static function (string $line): void {};
    $root = $clone('success');
    $gitlabRoot = $clone('gitlab-discovery');
    $git(['remote', 'set-url', 'origin', 'https://gitlab.example.invalid/group/sub/project.git'], $gitlabRoot);
    $projectSearch = 'https://gitlab.example.invalid/api/v4/projects?search=project&simple=true&per_page=100';
    $projectReleases = 'https://gitlab.example.invalid/api/v4/projects/42/releases?per_page=100';
    $requests = [];
    $gitlab = new Releases($process, static function (string $url) use (&$requests, $projectSearch, $projectReleases, $tag): array {
        $requests[] = $url;
        return match ($url) {
            $projectSearch . '&page=1' => array_fill(0, 100, ['id' => 7, 'path_with_namespace' => 'another/project']),
            $projectSearch . '&page=2' => [['id' => 42, 'path_with_namespace' => 'group/sub/project']],
            $projectReleases . '&page=1' => array_fill(0, 100, ['tag_name' => 'main']),
            $projectReleases . '&page=2' => [['tag_name' => $tag, 'released_at' => '2020-01-01T00:00:00Z']],
            default => throw new RuntimeException('Unexpected GitLab endpoint: ' . $url),
        };
    });
    check($gitlab->latest($gitlabRoot)['tag'] === $tag && count($requests) === 4,
        'GitLab matcht exact namespacepad over projectpaginas en pagineert releases via numeriek ID');
    check($gitlab->latest($gitlabRoot, $tag)['tag'] === $tag,
        'GitLab-herstel vindt oorspronkelijke releasetag via project-ID');
    foreach ([
        [[], 'niet gevonden'],
        [[['id' => 7, 'path_with_namespace' => 'another/project']], 'niet gevonden'],
        [[['id' => '42', 'path_with_namespace' => 'group/sub/project']], 'ongeldig project'],
        [['unexpected' => true], 'geen lijst'],
        [array_fill(0, 100, ['id' => 7, 'path_with_namespace' => 'another/project']), 'limiet van 1000'],
    ] as [$projects, $error]) {
        $invalidGitlab = new Releases($process, static fn (string $url): array => $projects);
        $throwsUpdate(static fn () => $invalidGitlab->latest($gitlabRoot), $error,
            'GitLab-projectselectie faalt expliciet zonder verkeerd project of branchfallback');
    }
    $unavailableGitlab = new Releases($process, static function (string $url): array { throw new RuntimeException('Project API unavailable'); });
    $throwsUpdate(static fn () => $unavailableGitlab->latest($gitlabRoot), 'Project API unavailable',
        'GitLab-project-API-fout wordt expliciet doorgegeven');
    $updater = new Updater($root, $process, $releases);
    check($updater->check()['available'] && $git(['rev-parse', 'HEAD'], $root) === $baseline, 'Releasecontrole verandert geen HEAD');
    $throwsUpdate(static fn () => $updater->update($log, $tag . '-other'), 'gewijzigd', 'Verouderde GUI-bevestiging wordt geweigerd');
    $lock = fopen($root . '/var/update.lock', 'c');
    flock($lock, LOCK_EX);
    $throwsUpdate(static fn () => $updater->update($log), 'al een update', 'Gelijktijdige updates worden geblokkeerd');
    flock($lock, LOCK_UN);
    fclose($lock);
    $write($root . '/local.txt', 'local work');
    $throwsUpdate(static fn () => $updater->update($log), 'niet schoon', 'Untracked lokale bestanden worden niet overschreven');
    unlink($root . '/local.txt');
    $updater->update($log, $tag);
    check($git(['rev-parse', 'HEAD'], $root) === $releaseCommit && !is_file($root . '/unreleased.txt'),
        'Updater installeert exacte gepubliceerde release, niet nieuwere branchcommit');
    check($git(['branch', '--show-current'], $root) === '' && !is_file($root . '/var/update-maintenance.json'),
        'Release-installatie eindigt detached en schakelt onderhoudsmodus na succes uit');
    check(file_get_contents($root . '/var/migrated') === $next
        && str_contains(file_get_contents($root . '/var/steps'), 'system:verify:' . $next),
        'Migratie en verificatie draaien met nieuwe code in verse PHP-processen');
    $composerArgs = json_decode(file_get_contents($root . '/var/composer-args'), true, 16, JSON_THROW_ON_ERROR);
    check(in_array('--no-dev', $composerArgs, true) && in_array('--no-plugins', $composerArgs, true)
        && in_array('--no-scripts', $composerArgs, true) && in_array('--optimize-autoloader', $composerArgs, true),
        'Composer installeert release-lockfile met productionflags en zonder plugins/scripts');
    foreach (['composer', 'migration'] as $failure) {
        $failedRoot = $clone('failed-' . $failure);
        $write($failedRoot . '/var/fail-' . $failure, 'fail');
        $failed = new Updater($failedRoot, $process, $releases);
        $throwsUpdate(static fn () => $failed->update($log), 'Synthetic', 'Fout in ' . $failure . ' wordt expliciet doorgegeven');
        check(is_file($failedRoot . '/var/update-maintenance.json'), 'Mislukte ' . $failure . ' laat onderhoudsmodus actief');
        unlink($failedRoot . '/var/fail-' . $failure);
        $failed->update($log);
        check(!is_file($failedRoot . '/var/update-maintenance.json') && file_get_contents($failedRoot . '/var/migrated') === $next,
            'Afgebroken ' . $failure . '-update kan veilig worden hervat');
    }
    $pinnedRoot = $clone('pinned');
    $write($pinnedRoot . '/var/fail-composer', 'fail');
    $pinned = new Updater($pinnedRoot, $process, $releases);
    $throwsUpdate(static fn () => $pinned->update($log), 'Synthetic', 'Hersteltest start met onderbroken release');
    unlink($pinnedRoot . '/var/fail-composer');
    $newer = new Releases($process, static fn (string $url): array => [
        ['tag_name' => 'v' . ((int) $parts[0] + 1) . '.0.0', 'published_at' => '2020-01-01T00:00:00Z'], ...$fetch($url),
    ]);
    $pinned = new Updater($pinnedRoot, $process, $newer);
    check($pinned->check()['release']['tag'] === $tag, 'Herstel blijft bij oorspronkelijke release als ondertussen nieuwere release verschijnt');
    $pinned->update($log);
    check(!is_file($pinnedRoot . '/var/update-maintenance.json'), 'Oorspronkelijke release wordt succesvol hersteld ondanks nieuwere publicatie');
    $movedRoot = $clone('moved');
    $write($movedRoot . '/var/update-maintenance.json', json_encode(['tag' => $tag, 'commit' => $baseline], JSON_THROW_ON_ERROR));
    $throwsUpdate(static fn () => (new Updater($movedRoot, $process, $releases))->update($log), 'verplaatst',
        'Herstel weigert releasetag waarvan de commit is gewijzigd');
    $ahead = $clone('ahead');
    $git(['checkout', '--detach', $git(['rev-parse', 'main'], $ahead)], $ahead);
    $throwsUpdate(static fn () => (new Updater($ahead, $process, $releases))->update($log), 'buiten deze release',
        'Ontwikkelclone met nieuwere commits wordt nooit gedowngraded');
    check(!is_file($ahead . '/var/update-maintenance.json'), 'Geweigerde ontwikkelupdate schakelt onderhoudsmodus niet in');
    $empty = new Releases($process, static fn (string $url): array => []);
    check((new Updater($root, $process, $empty))->check()['release'] === null, 'Geen release veroorzaakt geen Git-branchfallback');
    $apiFailure = new Releases($process, static function (string $url): array { throw new RuntimeException('API unavailable'); });
    $throwsUpdate(static fn () => (new Updater($root, $process, $apiFailure))->update($log), 'API unavailable',
        'Providerfout installeert geen branchfallback');
    check($process->run([PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 100000)); echo "stdout";'], $root)['code'] === 0,
        'Grote stdout/stderr blokkeert procesrunner niet');
    $throwsUpdate(static fn () => $process->run([PHP_BINARY, '-r', 'sleep(3);'], $root, 1), 'tijd-', 'Procesrunner begrenst vastgelopen subprocessen');
} finally {
    $resolved = realpath($updateTemp);
    if ($resolved !== false && str_starts_with(basename($resolved), 'nnspotty-updater-')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else { if (PHP_OS_FAMILY === 'Windows') chmod($entry->getPathname(), 0666); unlink($entry->getPathname()); }
        }
        rmdir($resolved);
    }
}
})();
