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

final class DownloaderController
{
    private const PAGE = 30;

    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    public function handle(Request $request, ?array $user, Csrf $csrf): Response
    {
        if ($user === null) {
            return Response::privateJson(['error' => 'Login vereist.'], 401);
        }
        $admin = $user['is_admin'];
        $username = $user['username'];
        $get = $request->method() === 'GET';
        $selection = 'personal';
        $client = 'sabnzbd';
        $label = Targets::CLIENTS[$client];
        try {
            if (!$get && !$csrf->verify($request->input('_csrf'))) {
                return Response::privateJson(['error' => 'Ongeldige CSRF token.'], 403);
            }
            $action = ($get ? $request->queryScalar('action') : $request->inputScalar('action')) ?? ($get ? 'status' : '');
            if (!$get && $action === 'default') {
                (new Targets($this->pdo))->saveDefault($username, $request->inputScalar('target') ?? '', $admin);
                $this->audit($username, 'downloader.default', 'personal', $request);
                return Response::redirect('/preferences?status=' . rawurlencode('Standaard downloadclient opgeslagen.') . '#downloads');
            }
            $client = Targets::client(($get ? $request->queryScalar('client') : $request->inputScalar('client')) ?? 'sabnzbd');
            $label = Targets::CLIENTS[$client];
            $selection = ($get ? $request->queryScalar('connection') : $request->inputScalar('connection')) ?? 'personal';
            if ($selection === 'central' && !$admin) {
                return Response::privateJson(['error' => 'De centrale koppeling is alleen voor admins.'], 403);
            }
            $scope = SabRepository::scope($username, $selection, $admin);
            $repo = Targets::repository($this->pdo, $client);
            $history = new DownloadHistory($this->pdo);
            if ($get && $action === 'history') {
                return Response::privateJson(['history' => $history->history($scope, $client, $this->state->canViewErotica($username))]);
            }
            if (!$get) {
                $response = $this->manage($request, $repo, $client, $selection, $scope, $username, $admin);
                if ($response !== null) {
                    return $response;
                }
            }
            $settings = $repo->settings($scope);
            if (!$settings['enabled']) {
                throw new InvalidArgumentException('Deze ' . $label . '-koppeling is niet ingeschakeld. Stel hem eerst in via Voorkeuren of Beheerpanel.');
            }
            $downloader = $repo->client($settings, $admin);
            if ($get && $action === 'prepare') {
                Endpoint::resolve($settings['url'], $admin, $client === 'nzbget' ? '/jsonrpc' : '/api');
                $spot = $this->spot($request->queryScalar('id') ?? '', $username);
                $category = $repo->category($spot, $settings);
                return Response::privateJson(['title' => $spot['title'], 'client' => $label,
                    'category' => $category === '' ? ($client === 'nzbget' ? 'Geen (NZBGet-standaard)' : 'Standaard') : $category,
                    'token' => bin2hex(random_bytes(16)), 'revision' => $this->revision($client, $scope, $repo, $settings, $category)]);
            }
            if ($get && $action === 'status') {
                $offset = $request->queryScalar('offset') ?? '0';
                if (!ctype_digit($offset) || strlen($offset) > 6 || (int) $offset > 100000) {
                    throw new InvalidArgumentException('Ongeldige wachtrijpositie.');
                }
                $queue = $downloader->queue((int) $offset, self::PAGE);
                return Response::privateJson($queue + ['client' => $label, 'offset' => (int) $offset,
                    'history' => $history->history($scope, $client, $this->state->canViewErotica($username))]);
            }
            if (!$get && $action === 'test') {
                return Response::privateJson($this->test($downloader));
            }
            if (!$get && $action === 'send') {
                return $this->send($request, $downloader, $repo, $history, $client, $selection, $scope, $username, $settings);
            }
            return Response::privateJson(['error' => 'Onbekende downloaderactie.'], 400);
        } catch (InvalidArgumentException $exception) {
            return $this->error($request, $selection, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log($label . ' ' . $username . ': ' . $exception->getMessage());
            return $this->error($request, $selection, $label . '-aanvraag mislukt. Controleer host, inloggegevens en serverlog. Na een verzendpoging eerst de wachtrij controleren; de uitkomst kan onbekend zijn.', 502);
        }
    }

    private function manage(Request $request, SabRepository|NzbgetRepository $repo, string $client, string $selection, string $scope, string $username, bool $admin): ?Response
    {
        $action = $request->inputScalar('action') ?? '';
        $label = Targets::CLIENTS[$client];
        $page = $selection === 'central' ? '/admin' : '/preferences';
        if ($action === 'mapping') {
            if (!$admin) {
                return Response::privateJson(['error' => 'Alleen admins beheren categorietoewijzing.'], 403);
            }
            $mapping = [];
            foreach (ConnectionRepository::TYPES as $index => $type) {
                $mapping[$type] = $request->inputScalar('category_' . $index) ?? '';
            }
            $repo->saveMappings($mapping);
            $this->audit($username, $client . '.mapping', $selection, $request);
            return Response::redirect('/admin?status=' . rawurlencode($label . '-categorieen opgeslagen.') . '#downloads');
        }
        if ($action === 'save') {
            $enabled = $request->inputScalar('enabled');
            if ($enabled !== null && $enabled !== '1') {
                throw new InvalidArgumentException('Ongeldige ' . $label . '-instelling.');
            }
            $owner = $selection === 'central' ? null : $username;
            if ($repo instanceof NzbgetRepository) {
                $repo->save($scope, $owner, $request->inputScalar('url') ?? '', $request->inputScalar('control_username') ?? '',
                    $request->inputScalar('control_password') ?? '', $enabled === '1', $admin);
            } else {
                $repo->save($scope, $owner, $request->inputScalar('url') ?? '', $request->inputScalar('api_key') ?? '', $enabled === '1', $admin);
            }
            $this->audit($username, $client . '.settings', $selection, $request);
            return Response::redirect($page . '?status=' . rawurlencode($label . '-instellingen opgeslagen.') . '#downloads');
        }
        if ($action === 'delete') {
            $repo->delete($scope);
            $this->audit($username, $client . '.disconnect', $selection, $request);
            return Response::redirect($page . '?status=' . rawurlencode($label . '-koppeling verwijderd; historie blijft bewaard.') . '#downloads');
        }
        return null;
    }

    /** @return array{message: string, categories: list<string>} */
    private function test(DownloadClient $downloader): array
    {
        if (!$downloader instanceof NzbgetClient) {
            return ['message' => 'SABnzbd is bereikbaar; categorieen opgehaald.', 'categories' => $downloader->categories()];
        }
        $version = $downloader->version();
        try {
            $categories = $downloader->categories();
        } catch (NzbgetRejected) {
            return ['message' => 'NZBGet ' . $version . ' is bereikbaar, maar deze gebruiker mag de categorieen niet lezen. Verzenden zonder categorie werkt wel.', 'categories' => []];
        }
        return ['message' => 'NZBGet ' . $version . ' is bereikbaar; categorieen opgehaald.', 'categories' => $categories];
    }

    private function send(Request $request, DownloadClient $downloader, SabRepository|NzbgetRepository $repo, DownloadHistory $history,
        string $client, string $selection, string $scope, string $username, array $settings): Response
    {
        $label = Targets::CLIENTS[$client];
        $spot = $this->spot($request->inputScalar('id') ?? '', $username);
        $token = $request->inputScalar('token') ?? '';
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Ongeldige downloadbevestiging.');
        }
        $category = $repo->category($spot, $settings);
        if (!hash_equals($this->revision($client, $scope, $repo, $settings, $category), $request->inputScalar('revision') ?? '')) {
            throw new InvalidArgumentException('De koppeling of categorie is gewijzigd. Open de downloadbevestiging opnieuw.');
        }
        // NZBGet without a category needs no category lookup; restricted NZBGet users may not read the configuration.
        if ($client === 'sabnzbd' || $category !== '') {
            $categories = $downloader->categories();
            if ($category !== '' && !in_array($category, $categories, true)) {
                throw new InvalidArgumentException('De toegewezen categorie bestaat niet op deze ' . $label . '-host. Laat de admin de mapping aanpassen of maak de categorie aan in ' . $label . '.');
            }
        }
        $nzb = (new SpotNzbService($this->pdo, $this->state))->load($spot);
        $attempt = $history->begin($scope, $client, $username, $spot, $category, $token);
        if (!$attempt['fresh']) {
            return Response::privateJson(['message' => 'Deze bevestiging is al verwerkt (' . $attempt['status'] . '). Controleer de wachtrij/historie voordat je opnieuw verstuurt.',
                'status' => $attempt['status']], 409);
        }
        try {
            $remoteId = $downloader->add($spot['title'], $nzb, $category);
            $history->finish((int) $attempt['id'], 'sent', $remoteId);
        } catch (Throwable $exception) {
            $history->finish((int) $attempt['id'], $exception instanceof ClientRejected ? 'failed' : 'unknown');
            throw new RuntimeException('Verzending niet bevestigd. Controleer ' . $label . ' en de NNSpotty-historie voordat je opnieuw verstuurt.', 0, $exception);
        }
        $this->audit($username, $client . '.sent', $selection, $request);
        return Response::privateJson(['message' => 'NZB geaccepteerd door ' . $label . '.', 'status' => 'sent'], 201);
    }

    private function error(Request $request, string $selection, string $message, int $status): Response
    {
        if ($request->method() === 'POST' && in_array($request->input('action'), ['save', 'delete', 'mapping', 'default'], true)) {
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
        (new \NnSpotty\Audit\AuditLogRepository($this->pdo))->log($username, $action, 'downloader', $selection, [], $request->clientIp());
    }

    private function revision(string $client, string $scope, ConnectionRepository $repo, array $settings, string $category): string
    {
        return hash('sha256', $client . "\0" . $scope . "\0" . $settings['url'] . "\0" . $repo->revisionSecret($settings) . "\0" . $category);
    }
}
