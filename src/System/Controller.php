<?php

declare(strict_types=1);

namespace NnSpotty\System;

use NnSpotty\Audit\AuditLogRepository;
use NnSpotty\Auth\SessionAuth;
use NnSpotty\Config\Release;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\State\AppStateRepository;
use NnSpotty\UI\SpotLayout;
use PDO;
use Throwable;

final class Controller
{
    public function __construct(
        private readonly string $root,
        private readonly PDO $pdo,
        private readonly SessionAuth $auth,
        private readonly Csrf $csrf,
        private readonly AppStateRepository $state
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if (!$this->auth->can('system.update')) return Response::privateJson(['error' => 'Geen recht om het systeem bij te werken.'], 403);
        $csrfField = $this->csrf->field();
        if ($request->method() === 'POST' && !$this->csrf->verify($request->input('_csrf'))) {
            return Response::privateJson(['error' => 'Ongeldige CSRF-token.'], 403);
        }
        session_write_close();
        try {
            $process = new Process();
            if ($request->method() === 'POST') {
                $action = $request->inputScalar('action');
                if ($action === 'check') {
                    $this->status($process, true);
                    return Response::redirect('/system/update');
                }
                if ($action !== 'update' || $request->inputScalar('backup') !== '1') {
                    return Response::privateJson(['error' => 'Bevestig eerst dat je een actuele database- en bestandenback-up hebt.'], 400);
                }
                $tag = $request->inputScalar('tag') ?? '';
                if (preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+$/D', $tag) !== 1) {
                    return Response::privateJson(['error' => 'Ongeldige releasetag. Controleer opnieuw.'], 400);
                }
                $php = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : PHP_BINDIR . '/php';
                if (!is_file($php)) throw new \RuntimeException('PHP CLI ontbreekt naast de PHP-webinstallatie. Gebruik de CLI-updater met de juiste PHP-versie.');
                $audit = new AuditLogRepository($this->pdo);
                $audit->log($user['username'], 'system.update.started', 'release', $tag, [], $request->clientIp());
                ignore_user_abort(true);
                set_time_limit(0);
                $result = $process->run([$php, $this->root . '/bin/console', 'system', 'update', '--tag=' . $tag], $this->root, 3000);
                if ($result['code'] !== 0) {
                    error_log('Web updater: ' . $result['output']);
                    $audit->log($user['username'], 'system.update.failed', 'release', $tag, [], $request->clientIp());
                    return Response::privateJson(['error' => 'Update mislukt. Bekijk var/update.log of de serverlog en herstel via de CLI. '
                        . 'Bij een onderbroken installatie blijft onderhoudsmodus actief; start niet blind opnieuw.'], 500);
                }
                if (function_exists('opcache_reset')) opcache_reset();
                $audit->log($user['username'], 'system.update.completed', 'release', $tag, [], $request->clientIp());
                return Response::privateJson(['message' => 'Update afgerond. De pagina wordt opnieuw geladen.']);
            }
            $status = $this->status($process);
            if ($request->path() === '/system/update/status') return Response::privateJson($status);
            $html = SpotLayout::render('Systeem bijwerken', $user['username'], (bool) $user['is_admin'], $csrfField,
                View::render($status, $csrfField), theme: $this->state->getPreferences($user['username'])['theme'], section: 'update');
            return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException $exception) {
            return Response::privateJson(['error' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            error_log('Release updater: ' . $exception->getMessage());
            return Response::privateJson(['error' => 'Releasecontrole/updater mislukt. Controleer Git, PHP CLI, Composer, rechten en de serverlog.'], 500);
        }
    }

    private function status(Process $process, bool $refresh = false): array
    {
        $path = $this->root . '/var/update-cache.json';
        $origin = $process->require(['git', 'config', '--get', 'remote.origin.url'], $this->root);
        $key = hash('sha256', $origin . Release::VERSION);
        if (!$refresh && !is_file($this->root . '/var/update-maintenance.json') && is_file($path)) {
            $cache = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (($cache['key'] ?? '') === $key && ($cache['expires'] ?? 0) > time()) {
                $status = $cache['status'];
                $status['recovery'] = is_file($this->root . '/var/update-maintenance.json');
                return $status;
            }
        }
        try {
            $status = (new Updater($this->root, $process, new Releases($process)))->check() + ['error' => null, 'checked_at' => gmdate(DATE_ATOM)];
        } catch (Throwable $exception) {
            error_log('Release check: ' . $exception->getMessage());
            $status = ['current' => Release::VERSION, 'codename' => Release::CODENAME, 'available' => false,
                'release' => null, 'recovery' => is_file($this->root . '/var/update-maintenance.json'),
                'error' => 'Releasecontrole mislukt. Controleer de serverlog; er wordt niet teruggevallen op branchcommits.', 'checked_at' => gmdate(DATE_ATOM)];
        }
        Updater::write($path, json_encode(['key' => $key, 'expires' => time() + ($status['error'] === null ? 21600 : 300), 'status' => $status], JSON_THROW_ON_ERROR));
        return $status;
    }
}
