<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

use InvalidArgumentException;
use NnSpotty\Audit\AuditLogRepository;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\State\AppStateRepository;
use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\Usenet\SpotRepository;
use PDO;
use Throwable;

final class Controller
{
    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    public function handle(Request $request, ?array $user, Csrf $csrf): Response
    {
        if ($user === null) return Response::privateJson(['error' => 'Login vereist.'], 401);
        if (!$csrf->verify($request->input('_csrf'))) return Response::privateJson(['error' => 'Ongeldige CSRF token.'], 403);
        $repo = new Repository($this->pdo);
        $action = '';
        try {
            $action = $request->inputScalar('action') ?? '';
            if (in_array($action, ['settings', 'test', 'search', 'choose', 'reset', 'block'], true) && !$user['is_admin']) {
                return Response::privateJson(['error' => 'Alleen admins mogen TVDB-toegang of gedeelde matches beheren.'], 403);
            }
            if ($action === 'preference') {
                $repo->savePreference($user['username'], $this->boolean($request, 'enabled'));
                $this->audit($user, 'preference', $request);
                return Response::redirect('/preferences?status=' . rawurlencode('TVDB-voorkeur opgeslagen.') . '#metadata');
            }
            if ($action === 'settings') {
                $repo->saveSettings($this->boolean($request, 'enabled'), $request->inputScalar('api_key') ?? '',
                    $request->inputScalar('pin') ?? '', $request->inputScalar('language') ?? '', $this->boolean($request, 'clear_pin'));
                $this->audit($user, 'settings', $request);
                return Response::redirect('/admin?status=' . rawurlencode('TVDB-instellingen opgeslagen.') . '#metadata');
            }
            if ($action === 'test') {
                (new Client($repo))->get('/artwork/types');
                return Response::redirect('/admin?status=' . rawurlencode('TVDB-toegang geslaagd; authenticatie en artworktypen opgehaald.') . '#metadata');
            }
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'TVDB is globaal of voor jouw account uitgeschakeld.'], 403);
            $id = $request->inputScalar('id') ?? '';
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Ongeldig spot-ID.');
            $spot = (new SpotRepository($this->pdo))->getSpot($id, $this->state->canViewErotica($user['username']));
            if ($spot === null) return Response::privateJson(['error' => 'Spot niet gevonden.'], 404);
            $spot = (new \NnSpotty\Favorites\Repository($this->pdo))->annotate([$spot], $user['username'])[0];
            if (Release::parse($spot) === null) throw new InvalidArgumentException('Deze spot is geen herkenbare film of serie.');
            $service = new Service($repo, new Client($repo));
            if ($action === 'search') {
                $candidates = $service->search($request->inputScalar('query') ?? '', $request->inputScalar('type') ?? '');
                return Response::privateJson(['candidates' => array_map(static fn ($row): array => array_intersect_key($row, array_flip(['id', 'type', 'name', 'year'])), $candidates)]);
            }
            if (in_array($action, ['choose', 'reset', 'block'], true)) {
                $choice = null;
                if ($action === 'block') $choice = ['blocked' => true];
                if ($action === 'choose') {
                    $chosenId = $this->number($request, 'tvdb_id', 1, 1000000000);
                    $type = $request->inputScalar('type') ?? '';
                    if ($chosenId === null || !in_array($type, ['movie', 'series'], true)) throw new InvalidArgumentException('Selecteer een TVDB-resultaat.');
                    $season = $this->number($request, 'season', 0, 999);
                    $episode = $this->number($request, 'episode', 1, 9999);
                    if ($type === 'series' && $episode !== null && $season === null) throw new InvalidArgumentException('Een aflevering vereist ook een seizoen.');
                    $choice = ['id' => $chosenId, 'type' => $type, 'season' => $type === 'series' ? $season : null,
                        'episode' => $type === 'series' ? $episode : null, 'strict_episode' => true];
                }
                $service->choose($spot, $choice);
                $this->audit($user, $action, $request, $id);
            } elseif ($action !== 'load') {
                throw new InvalidArgumentException('Onbekende metadata-actie.');
            }
            $result = $service->lookup($spot);
            if (!$repo->allowed($user['username'])) return Response::privateJson(['error' => 'TVDB is intussen uitgeschakeld.'], 403);
            $content = $this->state->spotContentSettings();
            return Response::privateJson(['state' => $result['state'], 'message' => View::message($result),
                'html' => SpotDetailPageRenderer::summary($spot, false, $content['images_enabled'], $content['show_comments'], $result['metadata'] ?? null, true)],
                $result['state'] === 'error' ? 502 : 200);
        } catch (InvalidArgumentException $exception) {
            return $this->error($action, $exception->getMessage(), 400);
        } catch (Throwable $exception) {
            error_log('TVDB controller: ' . $exception->getMessage());
            return $this->error($action, 'TVDB-aanvraag mislukt. Controleer key, PIN, toegang en serverlog. De oorspronkelijke spot blijft beschikbaar.', 502);
        }
    }

    private function error(string $action, string $message, int $status): Response
    {
        return in_array($action, ['settings', 'test'], true)
            ? Response::redirect('/admin?status=' . rawurlencode($message) . '#metadata')
            : Response::privateJson(['error' => $message], $status);
    }

    private function boolean(Request $request, string $name): bool
    {
        $value = $request->inputScalar($name);
        if ($value !== null && $value !== '1') throw new InvalidArgumentException('Ongeldige TVDB-schakelaar.');
        return $value === '1';
    }

    private function number(Request $request, string $name, int $min, int $max): ?int
    {
        $value = $request->inputScalar($name) ?? '';
        if ($value === '') return null;
        if (!ctype_digit($value) || strlen($value) > 10 || (int) $value < $min || (int) $value > $max) {
            throw new InvalidArgumentException('Ongeldige waarde voor ' . $name . '.');
        }
        return (int) $value;
    }

    private function audit(array $user, string $action, Request $request, string $id = 'tvdb'): void
    {
        (new AuditLogRepository($this->pdo))->log($user['username'], 'tvdb.' . $action, 'metadata', $id, [], $request->clientIp());
    }
}
