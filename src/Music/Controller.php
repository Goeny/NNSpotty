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
            if (in_array($action, ['settings', 'search', 'choose', 'reset', 'block'], true) && !$user['is_admin']) {
                return Response::privateJson(['error' => 'Alleen admins beheren muziektoegang en gedeelde matches.'], 403);
            }
            if (in_array($action, ['settings', 'preference'], true)) {
                $enabled = $request->inputScalar('enabled');
                if ($enabled !== null && $enabled !== '1') throw new \InvalidArgumentException('Ongeldige muziekschakelaar.');
                if ($action === 'settings') $repo->enable($enabled === '1');
                else $repo->savePreference($user['username'], $enabled === '1');
                $this->audit($user, $action, $request, 'music');
                return Response::redirect(($action === 'settings' ? '/admin' : '/preferences')
                    . '?status=' . rawurlencode('Muziekmetadata-instelling opgeslagen.') . '#music-metadata');
            }
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'Muziekmetadata is globaal of persoonlijk uitgeschakeld.'], 403);
            $id = $request->inputScalar('id') ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new \InvalidArgumentException('Ongeldig spot-ID.');
            $spot = (new SpotRepository($this->pdo))->getSpot($id, $this->state->canViewErotica($user['username']));
            if ($spot === null) return Response::privateJson(['error' => 'Spot niet gevonden.'], 404);
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
        return $action === 'settings' ? Response::redirect('/admin?status=' . rawurlencode($message) . '#music-metadata')
            : Response::privateJson(['error' => $message], $status);
    }

    private function audit(array $user, string $action, Request $request, string $id): void
    {
        (new AuditLogRepository($this->pdo))->log($user['username'], 'music.' . $action, 'metadata', $id, [], $request->clientIp());
    }
}
