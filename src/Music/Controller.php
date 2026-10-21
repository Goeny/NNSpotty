<?php

declare(strict_types=1);

namespace NnSpotty\Music;

use NnSpotty\Audit\AuditLogRepository;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\State\AppStateRepository;
use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\Usenet\SpotRepository;
use PDO;

final class Controller
{
    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state, private readonly string $contact) {}

    public function handle(Request $request, ?array $user, Csrf $csrf): Response
    {
        if ($user === null) return Response::privateJson(['error' => 'Login vereist.'], 401);
        if (!$csrf->verify($request->input('_csrf'))) return Response::privateJson(['error' => 'Ongeldige CSRF token.'], 403);
        $repo = new Repository($this->pdo);
        $action = '';
        try {
            $action = $request->inputScalar('action') ?? '';
            if ((in_array($action, ['settings', 'fanart-settings'], true) && !\NnSpotty\Users\Permissions::allows($user, 'settings.manage'))
                || (in_array($action, ['search', 'choose', 'reset', 'block'], true) && !\NnSpotty\Users\Permissions::allows($user, 'metadata.manage'))) {
                return Response::privateJson(['error' => 'Alleen admins beheren muziektoegang en gedeelde matches.'], 403);
            }
            if (in_array($action, ['fanart-settings', 'fanart-preference'], true)) {
                $enabled = $request->inputScalar('enabled');
                if ($enabled !== null && $enabled !== '1') throw new \InvalidArgumentException('Ongeldige fanart-schakelaar.');
                if ($action === 'fanart-settings') {
                    $clear = $request->inputScalar('clear_key');
                    if ($clear !== null && $clear !== '1') throw new \InvalidArgumentException('Ongeldige API-keywisactie.');
                    $repo->saveFanartSettings($enabled === '1', $request->inputScalar('api_key') ?? '', $clear === '1');
                } else $repo->saveFanartPreference($user['username'], $enabled === '1');
                $this->audit($user, $action, $request, 'music');
                return Response::redirect(($action === 'fanart-settings' ? '/admin' : '/preferences')
                    . '?status=' . rawurlencode('Artiest-fanartinstelling opgeslagen.') . ($action === 'fanart-settings' ? '#metadata-providers' : '#music-metadata'));
            }
            if (in_array($action, ['settings', 'preference'], true)) {
                $enabled = $request->inputScalar('enabled');
                if ($enabled !== null && $enabled !== '1') throw new \InvalidArgumentException('Ongeldige muziekschakelaar.');
                if ($action === 'settings') $repo->enable($enabled === '1');
                else $repo->savePreference($user['username'], $enabled === '1');
                $this->audit($user, $action, $request, 'music');
                return Response::redirect(($action === 'settings' ? '/admin' : '/preferences')
                    . '?status=' . rawurlencode('Muziekmetadata-instelling opgeslagen.') . ($action === 'settings' ? '#metadata-music' : '#music-metadata'));
            }
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'Muziekmetadata is globaal of persoonlijk uitgeschakeld.'], 403);
            $id = $request->inputScalar('id') ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new \InvalidArgumentException('Ongeldig spot-ID.');
            $spot = (new SpotRepository($this->pdo))->getSpot($id, $this->state->canViewErotica($user['username']));
            if ($spot === null) return Response::privateJson(['error' => 'Spot niet gevonden.'], 404);
            $spot = (new \NnSpotty\Favorites\Repository($this->pdo))->annotate([$spot], $user['username'])[0];
            if (Release::parse($spot) === null) throw new \InvalidArgumentException('Geen ondersteunde muziekspot.');
            $service = new Service($repo, new Client($repo, $this->contact));
            if ($action === 'search') {
                $candidates = $service->search($request->inputScalar('query') ?? '', $request->inputScalar('type') ?? '');
                return Response::privateJson(['candidates' => array_map(static fn (array $candidate): array =>
                    array_diff_key($candidate, ['names' => true]), $candidates)]);
            }
            if (in_array($action, ['choose', 'reset', 'block'], true)) {
                $choice = match ($action) {
                    'block' => ['blocked' => true],
                    'choose' => ['id' => $request->inputScalar('mbid') ?? '', 'type' => $request->inputScalar('type') ?? ''],
                    default => null,
                };
                $service->choose($spot, $choice);
                $this->audit($user, $action, $request, $id);
            } elseif ($action !== 'load') throw new \InvalidArgumentException('Onbekende muziekmetadata-actie.');
            $result = $service->lookup($spot);
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'Muziekmetadata is intussen uitgeschakeld.'], 403);
            $content = $this->state->spotContentSettings();
            if (isset($result['metadata']) && $content['images_enabled'] && $repo->fanartAllowed($user['username'])) {
                $result['metadata'] = (new Fanart($repo, new FanartClient($repo)))->enrich($result['metadata']);
            }
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'Muziekmetadata is intussen uitgeschakeld.'], 403);
            $content = $this->state->spotContentSettings();
            if (isset($result['metadata']) && (!$content['images_enabled'] || !$repo->fanartAllowed($user['username']))) {
                unset($result['metadata']['fanart'], $result['metadata']['fanart_warning']);
            }
            return Response::privateJson(['state' => $result['state'], 'message' => View::message($result),
                'html' => SpotDetailPageRenderer::summary($spot, false, $content['images_enabled'], $content['show_comments'], $result['metadata'] ?? null, true)],
                $result['state'] === 'error' ? 502 : 200);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($action, $exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            error_log('Muziekmetadata controller: ' . $exception->getMessage());
            return $this->error($action, 'Muziekmetadata-aanvraag mislukt. Controleer APP_URL, verbinding, aanvraaglimiet en serverlog.', 502);
        }
    }

    private function error(string $action, string $message, int $status): Response
    {
        return in_array($action, ['settings', 'fanart-settings', 'fanart-preference'], true)
            ? Response::redirect(($action === 'fanart-preference' ? '/preferences' : '/admin') . '?status=' . rawurlencode($message)
                . ['settings' => '#metadata-music', 'fanart-settings' => '#metadata-providers', 'fanart-preference' => '#music-metadata'][$action])
            : Response::privateJson(['error' => $message], $status);
    }

    private function audit(array $user, string $action, Request $request, string $id): void
    {
        (new AuditLogRepository($this->pdo))->log($user['username'], 'music.' . $action, 'metadata', $id, [], $request->clientIp());
    }
}
