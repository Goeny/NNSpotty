<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

use InvalidArgumentException;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\SpotNzbService;
use NnSpotty\Usenet\SpotRepository;
use PDO;
use RuntimeException;
use Throwable;

final class SabController
{
    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    public function handle(Request $request, ?array $user, Csrf $csrf): Response
    {
        if ($user === null) {
            return Response::privateJson(['error' => 'Login vereist.'], 401);
        }
        $repo = new SabRepository($this->pdo);
        $admin = $user['is_admin'];
        $username = $user['username'];
        $selection = 'personal';
        try {
            if ($request->method() === 'POST' && !$csrf->verify($request->input('_csrf'))) {
                return Response::privateJson(['error' => 'Ongeldige CSRF token.'], 403);
            }
            $selection = ($request->method() === 'GET' ? $request->queryScalar('connection') : $request->inputScalar('connection')) ?? 'personal';
            if ($selection === 'central' && !$admin) {
                return Response::privateJson(['error' => 'De centrale koppeling is alleen voor admins.'], 403);
            }
            $scope = SabRepository::scope($username, $selection, $admin);
            if ($request->method() === 'GET' && $request->queryScalar('action') === 'history') {
                return Response::privateJson(['history' => $repo->history($scope, $this->state->canViewErotica($username))]);
            }
            if ($request->method() === 'POST') {
                $action = $request->inputScalar('action') ?? '';
                if ($action === 'mapping') {
                    if (!$admin) {
                        return Response::privateJson(['error' => 'Alleen admins beheren categorietoewijzing.'], 403);
                    }
                    $mapping = [];
                    foreach (SabRepository::TYPES as $index => $type) {
                        $mapping[$type] = $request->inputScalar('category_' . $index) ?? '';
                    }
                    $repo->saveMappings($mapping);
                    $this->audit($username, 'mapping', $selection, $request);
                    return Response::redirect('/admin#downloads');
                }
                if ($action === 'save') {
                    $enabled = $request->inputScalar('enabled');
                    if ($enabled !== null && $enabled !== '1') {
                        throw new InvalidArgumentException('Ongeldige SABnzbd-instelling.');
                    }
                    $repo->save($scope, $selection === 'central' ? null : $username,
                        $request->inputScalar('url') ?? '', $request->inputScalar('api_key') ?? '', $enabled === '1', $admin);
                    $this->audit($username, 'settings', $selection, $request);
                    return Response::redirect(($selection === 'central' ? '/admin' : '/preferences') . '?status='
                        . rawurlencode('SABnzbd-instellingen opgeslagen.') . '#downloads');
                }
                if ($action === 'delete') {
                    $repo->delete($scope);
                    $this->audit($username, 'disconnect', $selection, $request);
                    return Response::redirect(($selection === 'central' ? '/admin' : '/preferences') . '?status='
                        . rawurlencode('SABnzbd-koppeling verwijderd; historie blijft bewaard.') . '#downloads');
                }
            } else {
                $action = $request->queryScalar('action') ?? 'status';
            }
            $settings = $repo->settings($scope);
            if (!$settings['enabled']) {
                throw new InvalidArgumentException('Deze SABnzbd-koppeling is niet ingeschakeld. Stel hem eerst in via Voorkeuren of Beheerpanel.');
            }
            $client = new SabClient($settings['url'], $settings['api_key'], $admin);
            if ($request->method() === 'GET' && $action === 'prepare') {
                Endpoint::resolve($settings['url'], $admin);
                $spot = $this->spot($request->queryScalar('id') ?? '', $username);
                $category = $repo->category($spot, $settings);
                return Response::privateJson(['title' => $spot['title'], 'category' => $category === '' ? 'Standaard' : $category,
                    'token' => bin2hex(random_bytes(16)), 'revision' => $this->revision($scope, $settings, $category)]);
            }
            if ($request->method() === 'GET' && $action === 'status') {
                $offset = $request->queryScalar('offset') ?? '0';
                if (!ctype_digit($offset) || strlen($offset) > 6 || (int) $offset > 100000) {
                    throw new InvalidArgumentException('Ongeldige wachtrijpositie.');
                }
                $response = $client->call('queue', ['start' => $offset, 'limit' => 30]);
                $queue = $response['queue'] ?? null;
                if (!is_array($queue) || !isset($queue['status'], $queue['kbpersec'], $queue['slots'], $queue['noofslots_total'])
                    || !is_string($queue['status']) || !is_array($queue['slots']) || !array_is_list($queue['slots'])
                    || !is_numeric($queue['kbpersec']) || !is_finite((float) $queue['kbpersec']) || (float) $queue['kbpersec'] < 0
                    || !(is_int($queue['noofslots_total']) || is_string($queue['noofslots_total']))
                    || !ctype_digit((string) $queue['noofslots_total'])) {
                    throw new RuntimeException('SABnzbd gaf een onvolledige wachtrij.');
                }
                $slots = [];
                foreach (array_slice($queue['slots'], 0, 30) as $slot) {
                    if (!is_array($slot) || !isset($slot['filename'], $slot['status'], $slot['percentage'], $slot['cat'], $slot['timeleft'])
                        || !is_string($slot['filename']) || !is_string($slot['status']) || !is_string($slot['cat']) || !is_string($slot['timeleft'])
                        || !is_numeric($slot['percentage']) || !is_finite((float) $slot['percentage'])) {
                        throw new RuntimeException('SABnzbd gaf een onvolledig wachtrij-item.');
                    }
                    $slots[] = ['title' => (string) $slot['filename'], 'status' => (string) $slot['status'],
                        'percentage' => max(0, min(100, (float) $slot['percentage'])), 'category' => (string) $slot['cat'], 'eta' => (string) $slot['timeleft']];
                }
                return Response::privateJson(['status' => (string) $queue['status'], 'speed' => (float) $queue['kbpersec'] * 1024,
                    'total' => (int) $queue['noofslots_total'], 'slots' => $slots, 'offset' => (int) $offset,
                    'history' => $repo->history($scope, $this->state->canViewErotica($username))]);
            }
            if ($request->method() === 'POST' && $action === 'test') {
                return Response::privateJson(['message' => 'SABnzbd is bereikbaar; categorieen opgehaald.', 'categories' => $client->categories()]);
            }
            if ($request->method() === 'POST' && $action === 'send') {
                $spot = $this->spot($request->inputScalar('id') ?? '', $username);
                $token = $request->inputScalar('token') ?? '';
                if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
                    throw new InvalidArgumentException('Ongeldige downloadbevestiging.');
                }
                $category = $repo->category($spot, $settings);
                if (!hash_equals($this->revision($scope, $settings, $category), $request->inputScalar('revision') ?? '')) {
                    throw new InvalidArgumentException('De koppeling of categorie is gewijzigd. Open de downloadbevestiging opnieuw.');
                }
                $categories = $client->categories();
                if ($category !== '' && !in_array($category, $categories, true)) {
                    throw new InvalidArgumentException('De toegewezen categorie bestaat niet op deze SABnzbd-host. Laat de admin de mapping aanpassen of maak de categorie aan in SABnzbd.');
                }
                $nzb = (new SpotNzbService($this->pdo, $this->state))->load($spot);
                $attempt = $repo->begin($scope, $username, $spot, $category, $token);
                if (!$attempt['fresh']) {
                    return Response::privateJson(['message' => 'Deze bevestiging is al verwerkt (' . $attempt['status'] . '). Controleer de wachtrij/historie voordat je opnieuw verstuurt.',
                        'status' => $attempt['status']], 409);
                }
                try {
                    $result = $client->call('addfile', ['nzbname' => $spot['title'], 'cat' => $category === '' ? '*' : $category], $nzb);
                    $ids = $result['nzo_ids'] ?? null;
                    if (($result['status'] ?? null) !== true || !is_array($ids) || count($ids) !== 1
                        || !is_string($ids[0]) || strlen($ids[0]) > 200 || !preg_match('/^[a-zA-Z0-9_-]+$/D', $ids[0])) {
                        throw new RuntimeException('SABnzbd heeft geen geldig download-ID bevestigd.');
                    }
                    $repo->finish((int) $attempt['id'], 'sent', $ids[0]);
                } catch (Throwable $exception) {
                    $repo->finish((int) $attempt['id'], $exception instanceof SabRejected ? 'failed' : 'unknown');
                    throw new RuntimeException('Verzending niet bevestigd. Controleer SABnzbd en de NNSpotty-historie voordat je opnieuw verstuurt.', 0, $exception);
                }
                $this->audit($username, 'sent', $selection, $request);
                return Response::privateJson(['message' => 'NZB geaccepteerd door SABnzbd.', 'status' => 'sent'], 201);
            }
            return Response::privateJson(['error' => 'Onbekende downloaderactie.'], 400);
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $selection, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('SABnzbd ' . $username . ': ' . $exception->getMessage());
            return $this->error($request, $selection, 'SABnzbd-aanvraag mislukt. Controleer host, API-key en serverlog. Na een verzendpoging eerst de wachtrij controleren; de uitkomst kan onbekend zijn.', 502);
        }
    }

    private function error(Request $request, string $selection, string $message, int $status): Response
    {
        if ($request->method() === 'POST' && in_array($request->input('action'), ['save', 'delete', 'mapping'], true)) {
            return Response::redirect(($selection === 'central' ? '/admin' : '/preferences') . '?status=' . rawurlencode($message) . '#downloads');
        }
        return Response::privateJson(['error' => $message], $status);
    }

    private function spot(string $id, string $username): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Ongeldig spot-ID.');
        }
        $spot = (new SpotRepository($this->pdo))->getSpot($id, $this->state->canViewErotica($username));
        if ($spot === null || $spot['nzb_segments'] === []) {
            throw new InvalidArgumentException('Spot/NZB niet beschikbaar voor jouw account.');
        }
        return $spot;
    }

    private function audit(string $username, string $action, string $selection, Request $request): void
    {
        (new \NnSpotty\Audit\AuditLogRepository($this->pdo))->log($username, 'sabnzbd.' . $action, 'downloader', $selection, [], $request->clientIp());
    }

    private function revision(string $scope, array $settings, string $category): string
    {
        return hash('sha256', $scope . "\0" . $settings['url'] . "\0" . $settings['api_key'] . "\0" . $category);
    }
}
