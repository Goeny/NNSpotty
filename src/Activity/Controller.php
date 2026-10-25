<?php

declare(strict_types=1);

namespace NnSpotty\Activity;

use InvalidArgumentException;
use NnSpotty\Auth\SessionAuth;
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
        private readonly PDO $pdo,
        private readonly SessionAuth $auth,
        private readonly Csrf $csrf,
        private readonly AppStateRepository $state
    ) {}

    public function handle(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
        $statistics = $request->path() === '/statistics';
        if ($statistics && !$this->auth->can('statistics.view')) {
            return new Response(403, 'Geen recht om statistieken te bekijken.', ['Cache-Control' => 'private, no-store']);
        }
        $csrfField = $this->csrf->field();
        session_write_close();
        try {
            $adult = $this->state->canViewErotica($user['username']);
            $repo = new Repository($this->pdo);
            if ($statistics) {
                $days = $request->queryScalar('days') ?? '30';
                if (!in_array($days, ['7', '30', '90'], true)) throw new InvalidArgumentException('Kies een periode van 7, 30 of 90 dagen.');
                $content = View::statistics($repo->statistics((int) $days, $adult));
            } else {
                $tab = $request->queryScalar('tab') ?? 'view';
                $page = $request->queryScalar('page') ?? '1';
                if (preg_match('/^[1-9][0-9]{0,5}$/D', $page) !== 1 || (int) $page > 100000) {
                    throw new InvalidArgumentException('Ongeldige historiepagina.');
                }
                $content = View::history($repo->history($user['username'], $tab, $adult, (int) $page), $tab, (int) $page);
            }
            $html = SpotLayout::render($statistics ? 'Statistieken' : 'Historie', $user['username'], (bool) $user['is_admin'],
                $csrfField, $content, theme: $this->state->getPreferences($user['username'])['theme'], showErotica: $adult,
                section: $statistics ? 'statistics' : 'history');
            return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        } catch (InvalidArgumentException $exception) {
            return new Response(400, $exception->getMessage(), ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        } catch (Throwable $exception) {
            error_log('Activity page: ' . $exception->getMessage());
            return new Response(500, 'Historie/statistieken ophalen mislukt. Controleer de serverlog.', ['Cache-Control' => 'private, no-store']);
        }
    }
}
