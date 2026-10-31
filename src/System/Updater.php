<?php

declare(strict_types=1);

namespace NnSpotty\System;

use NnSpotty\Config\Release;
use RuntimeException;
use Throwable;

final class Updater
{
    public function __construct(
        private readonly string $root,
        private readonly Process $process,
        private readonly Releases $releases,
        private readonly string $php = PHP_BINARY
    ) {}

    public function check(): array
    {
        $recovery = $this->recovery();
        $release = $this->releases->latest($this->root, $recovery['tag'] ?? null);
        if ($recovery !== null && $release === null) throw new RuntimeException('De te herstellen release is niet meer gepubliceerd. Controleer de releasebron en updatelog.');
        return ['current' => Release::VERSION, 'codename' => Release::CODENAME, 'release' => $release,
            'available' => $release !== null && version_compare($release['version'], Release::VERSION, '>'),
            'recovery' => $recovery !== null];
    }

    public function update(callable $log, ?string $expectedTag = null): void
    {
        $lock = fopen($this->root . '/var/update.lock', 'c');
        if ($lock === false) throw new RuntimeException('Kan updatelock niet openen; controleer schrijfrechten op var.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Er wordt al een update uitgevoerd.');
        }
        $maintenance = $this->root . '/var/update-maintenance.json';
        try {
            $state = $this->check();
            $release = $state['release'];
            if ($release === null) throw new RuntimeException('Geen gepubliceerde stabiele release beschikbaar.');
            if ($expectedTag !== null && $expectedTag !== $release['tag']) throw new RuntimeException('De nieuwste release is gewijzigd. Controleer en bevestig opnieuw.');
            if (!$state['available'] && !$state['recovery']) { $log('Geen nieuwe release beschikbaar.'); return; }
            if (version_compare($release['version'], Release::VERSION, '<')) throw new RuntimeException('Downgraden wordt niet ondersteund.');
            if ($this->process->require(['git', 'status', '--porcelain', '--untracked-files=normal'], $this->root) !== '') {
                throw new RuntimeException('Werkmap is niet schoon. Bewaar lokale wijzigingen eerst; de updater overschrijft niets.');
            }
            $composer = self::composerCommand($this->root, $this->php);
            $this->process->require([...$composer, '--version', '--no-ansi'], $this->root);
            $log('Release ' . $release['tag'] . ' ophalen...');
            $this->process->require(['git', 'fetch', '--no-tags', 'origin', 'refs/tags/' . $release['tag']], $this->root, 180);
            $target = $this->process->require(['git', 'rev-parse', '--verify', 'FETCH_HEAD^{commit}'], $this->root);
            if (preg_match('/^[a-f0-9]{40,64}$/D', $target) !== 1) throw new RuntimeException('Ongeldige releasecommit.');
            $recovery = $this->recovery();
            if ($recovery !== null && $recovery['commit'] !== $target) throw new RuntimeException('Releasetag is verplaatst sinds de onderbroken update. Herstel handmatig; geen andere code geinstalleerd.');
            $ancestor = $this->process->run(['git', 'merge-base', '--is-ancestor', 'HEAD', $target], $this->root);
            if ($ancestor['code'] !== 0) throw new RuntimeException('Lokale HEAD bevat commits buiten deze release. Update afgebroken; geen downgrade/reset van ontwikkelwerk.');
            $manifest = $this->process->require(['git', 'show', $target . ':src/Config/Release.php'], $this->root);
            if (preg_match("/public const VERSION = '([^']+)';/", $manifest, $match) !== 1 || $match[1] !== $release['version']) {
                throw new RuntimeException('Versie in releasecode komt niet overeen met de releasetag.');
            }
            $manifestJson = $this->process->run(['git', 'show', $target . ':composer.json'], $this->root);
            if ($manifestJson['code'] === 0) $this->assertPhpSupports($manifestJson['output']);
            $log('Onderhoudsmodus inschakelen. Zorg dat je databaseback-up beschikbaar is.');
            self::write($maintenance, json_encode(['tag' => $release['tag'], 'commit' => $target, 'started_at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR));
            $this->process->require(['git', '-c', 'advice.detachedHead=false', 'checkout', '--detach', $target], $this->root);
            $log('Composer dependencies installeren...');
            $this->process->require([...$composer, 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
                '--no-interaction', '--no-progress', '--no-ansi', '--no-scripts', '--no-plugins'], $this->root, 600);
            foreach (['migrate', 'health', 'status'] as $action) {
                $log('Database ' . $action . ' uitvoeren met de nieuwe releasecode...');
                $log($this->process->require([$this->php, $this->root . '/bin/console', 'db', $action], $this->root, $action === 'migrate' ? 600 : 120));
            }
            $this->process->require([$this->php, $this->root . '/bin/console', 'system', 'verify', '--version=' . $release['version']], $this->root);
            if (!unlink($maintenance)) throw new RuntimeException('Kan onderhoudsmodus niet uitschakelen.');
            $cache = $this->root . '/var/update-cache.json';
            if (is_file($cache) && !unlink($cache)) throw new RuntimeException('Kan releasecache niet vernieuwen.');
            $log('Release ' . $release['tag'] . ' succesvol geinstalleerd.');
        } catch (Throwable $exception) {
            $log('Update mislukt: ' . $exception->getMessage());
            if (is_file($maintenance)) $log('Onderhoudsmodus blijft actief. Verhelp de fout en voer system update opnieuw uit. Geen automatische databasedowngrade.');
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Runs Composer with the updater's own PHP binary. A bare `composer` on PATH would resolve its
     * `#!/usr/bin/env php` shebang to the system default PHP, which may be older than the app requires.
     *
     * @return list<string>
     */
    public static function composerCommand(string $root, string $php, ?string $path = null): array
    {
        if (is_file($root . '/composer.phar')) return [$php, $root . '/composer.phar'];
        $windows = DIRECTORY_SEPARATOR === '\\';
        $extensions = $windows ? ['.bat', '.cmd', '.exe', '.phar'] : [''];
        foreach (explode(PATH_SEPARATOR, $path ?? (string) getenv('PATH')) as $directory) {
            $directory = trim($directory, " \t\"");
            if ($directory === '') continue;
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'composer' . $extension;
                if (!is_file($candidate)) continue;
                if ($windows && in_array($extension, ['.bat', '.cmd'], true)) {
                    $phar = dirname($candidate) . DIRECTORY_SEPARATOR . 'composer.phar';
                    return is_file($phar) ? [$php, $phar] : [$candidate];
                }
                return self::isPhpScript($candidate) ? [$php, $candidate] : [$candidate];
            }
        }
        throw new RuntimeException('Composer niet gevonden. Installeer Composer in PATH of plaats composer.phar in de installatiemap.');
    }

    private static function isPhpScript(string $file): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) return false;
        $head = (string) fread($handle, 256);
        fclose($handle);
        $line = strtok($head, "\n");
        return str_starts_with($head, '<?php') || ($line !== false && str_starts_with($line, '#!') && str_contains($line, 'php'));
    }

    private function assertPhpSupports(string $composerJson): void
    {
        $constraint = json_decode($composerJson, true, 64, JSON_THROW_ON_ERROR)['require']['php'] ?? null;
        if (!is_string($constraint) || preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $match) !== 1) return;
        $version = $this->process->require([$this->php, '-r', 'echo PHP_VERSION;'], $this->root);
        if (version_compare($version, $match[1], '<')) {
            throw new RuntimeException('Release vereist PHP ' . $constraint . ', maar de updater draait met PHP ' . $version . ' (' . $this->php
                . '). Start de update met de juiste PHP CLI-versie; er is niets gewijzigd.');
        }
    }

    public static function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Kan updaterstatus niet opslaan.');
    }

    private function recovery(): ?array
    {
        $path = $this->root . '/var/update-maintenance.json';
        if (!is_file($path)) return null;
        $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['tag'] ?? null) || !is_string($data['commit'] ?? null)
            || preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+$/D', $data['tag']) !== 1
            || preg_match('/^[a-f0-9]{40,64}$/D', $data['commit']) !== 1) throw new RuntimeException('Ongeldige herstelstatus. Controleer var/update-maintenance.json.');
        return $data;
    }
}
