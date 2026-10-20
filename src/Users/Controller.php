<?php

declare(strict_types=1);

namespace NnSpotty\Users;

use NnSpotty\Auth\SessionAuth;
use NnSpotty\Audit\AuditLogRepository;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\State\AppStateRepository;
use PDO;

final class Controller
{
    public function __construct(private readonly PDO $pdo, private readonly UserRepository $users,
        private readonly SessionAuth $auth, private readonly Csrf $csrf, private readonly AppStateRepository $state) {}

    public function profile(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        if ($request->method() === 'GET') {
            return new Response(200, View::password($user, $this->csrf->field(), $request->query('status')),
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        if (!$this->csrf->verify($request->input('_csrf'))) return new Response(403, 'Ongeldige CSRF-token.');
        $action = '';
        try {
            $action = $request->inputScalar('action') ?? '';
            if ($action === 'password') {
                $current = $request->inputScalar('current_password') ?? '';
                $password = $request->inputScalar('new_password') ?? '';
                $record = $this->users->findByUsername($user['username']);
                if ($record === null || !password_verify($current, $record['password_hash'])) {
                    throw new \InvalidArgumentException('Huidig wachtwoord is onjuist.');
                }
                if ($password !== ($request->inputScalar('password_confirm') ?? '') || password_verify($password, $record['password_hash'])) {
                    throw new \InvalidArgumentException('Bevestig het nieuwe wachtwoord; het moet afwijken van het huidige.');
                }
                $this->users->updatePassword($user['username'], $password);
                $this->auth->login($user['username'], $password);
            } else {
                if ($user['must_change_password']) return Response::redirect('/account/password');
                if ($action === 'profile') {
                    $this->users->saveProfile($user['username'], $request->inputScalar('display_name') ?? '',
                        $request->inputScalar('signature') ?? '', $request->inputScalar('email') ?? '');
                } elseif ($action === 'avatar') {
                    $service = new AvatarService($this->pdo);
                    $source = $request->inputScalar('source');
                    if ($source === 'upload') {
                        $file = $_FILES['avatar'] ?? [];
                        if (!is_array($file)) throw new \InvalidArgumentException('Ongeldige upload.');
                        $service->upload($user['username'], $file);
                    } elseif ($source === 'url') {
                        $service->remote($user['username'], $request->inputScalar('avatar_url') ?? '');
                    } elseif ($source === 'gravatar') {
                        if ($request->inputScalar('gravatar_consent') !== '1') throw new \InvalidArgumentException('Bevestig het delen van de e-mailhash met Gravatar.');
                        $service->gravatar($user['username'], $user['email']);
                    } elseif ($source === 'remove') $service->remove($user['username']);
                    else throw new \InvalidArgumentException('Onbekende avatarbron.');
                } else throw new \InvalidArgumentException('Onbekende profielactie.');
            }
            $this->audit($user['username'], 'user.' . $action, $user['username'], $request);
            return Response::redirect('/preferences?status=' . rawurlencode('Profiel bijgewerkt.') . '#profile');
        } catch (\InvalidArgumentException $exception) {
            return Response::redirect(($action === 'password' ? '/account/password' : '/preferences')
                . '?status=' . rawurlencode($exception->getMessage()) . ($action === 'password' ? '' : '#profile'));
        } catch (\RuntimeException $exception) {
            error_log('Profiel bijwerken: ' . $exception->getMessage());
            return Response::redirect(($action === 'password' ? '/account/password' : '/preferences')
                . '?status=' . rawurlencode('Profiel bijwerken mislukt. Controleer de serverlog.') . ($action === 'password' ? '' : '#profile'));
        }
    }

    public function manage(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) return Response::redirect('/login');
        $allowed = $this->auth->can('users.manage') || $this->auth->can('groups.manage')
            || $this->auth->can('audit.view') || $this->auth->can('spotters.manage');
        if (!$allowed) return new Response(403, 'Geen beheerrechten.');
        if ($request->method() === 'GET') {
            $page = max(1, min(100000, (int) $request->query('page', '1')));
            $search = mb_substr($request->query('q', '') ?? '', 0, 100);
            $target = $this->auth->can('users.manage') ? $this->users->findByUsername($request->query('user', '') ?? '') : null;
            if ($target !== null && !$this->auth->isAdmin()
                && ($target['is_admin'] || array_diff($target['permissions'], $user['permissions']))) $target = null;
            $audit = $this->auth->can('audit.view') ? (new AuditLogRepository($this->pdo))->recent(50) : [];
            // Audit viewers see actions, not settings payloads which may contain private configuration.
            foreach ($audit as &$row) $row['details_json'] = '{}';
            return new Response(200, View::manage($user, $this->csrf->field(),
                $this->auth->can('users.manage') ? $this->users->page($search, $page) : ['rows' => [], 'total' => 0],
                $this->users->groups(), $target, $target === null ? [] : $this->users->memberships($target['username']),
                $audit, $request->query('status'), $search, $page,
                $this->state->getPreferences($user['username'])['theme'],
                $this->auth->can('spotters.manage') ? \NnSpotty\Moderation\View::settings(new \NnSpotty\Moderation\SpotterLists($this->pdo), $this->csrf->field()) : ''),
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
        }
        if (!$this->csrf->verify($request->input('_csrf'))) return new Response(403, 'Ongeldige CSRF-token.');
        try {
            $action = $request->inputScalar('action') ?? '';
            if (in_array($action, ['group-save', 'group-delete'], true)) {
                if (!$this->auth->can('groups.manage')) return new Response(403, 'Geen groepsbeheerrechten.');
                $id = $request->inputScalar('group_id');
                if ($id !== null && (!ctype_digit($id) || (int) $id < 1)) throw new \InvalidArgumentException('Ongeldige groep.');
                if ($action === 'group-delete') {
                    if ($request->inputScalar('confirm') !== '1') throw new \InvalidArgumentException('Bevestig verwijderen.');
                    if ($id === null) throw new \InvalidArgumentException('Selecteer een groep.');
                    $this->users->deleteGroup((int) $id);
                } else {
                    $permissions = [];
                    foreach (Permissions::LABELS as $key => $_label) {
                        $value = $request->inputScalar('permission_' . str_replace('.', '_', $key));
                        if ($value !== null && $value !== '1') throw new \InvalidArgumentException('Ongeldig recht.');
                        if ($value === '1') $permissions[] = $key;
                    }
                    $this->users->saveGroup($id === null ? null : (int) $id, $request->inputScalar('name') ?? '', $permissions);
                }
                $targetName = $id ?? 'new';
            } else {
                if (!$this->auth->can('users.manage')) return new Response(403, 'Geen gebruikersbeheerrechten.');
                $targetName = $request->inputScalar('username') ?? '';
                $target = $this->users->findByUsername($targetName);
                if ($target !== null && $target['is_admin'] && !$this->auth->isAdmin()) return new Response(403, 'Alleen beheerders beheren beheerders.');
                if ($target !== null && !$this->auth->isAdmin()
                    && array_diff($target['permissions'], $user['permissions'])) {
                    return new Response(403, 'Je mag geen account met ruimere rechten beheren.');
                }
                if ($action === 'create') {
                    $this->users->create($targetName, $request->inputScalar('password') ?? '', false, true);
                } elseif ($action === 'delete') {
                    if ($request->inputScalar('confirm') !== '1') throw new \InvalidArgumentException('Bevestig verwijderen.');
                    if ($targetName === $user['username']) throw new \InvalidArgumentException('Verwijder je eigen account niet vanuit deze sessie.');
                    $this->users->delete($targetName);
                } elseif ($action === 'reset-password') {
                    $this->users->updatePassword($targetName, $request->inputScalar('password') ?? '', true);
                } elseif ($action === 'save') {
                    if ($target === null) throw new \InvalidArgumentException('Gebruiker niet gevonden.');
                    $ids = array_map('intval', array_column($this->users->memberships($targetName), 'id'));
                    if ($this->auth->can('groups.manage')) {
                        $ids = [];
                        foreach ($this->users->groups() as $group) {
                            if ($request->inputScalar('group_' . $group['id']) === '1') {
                                if ($group['system_key'] === 'admins' && !$this->auth->isAdmin()) return new Response(403, 'Alleen beheerders mogen beheerders toewijzen.');
                                $ids[] = (int) $group['id'];
                            }
                        }
                    }
                    $active = $request->inputScalar('active');
                    if ($active !== null && $active !== '1') throw new \InvalidArgumentException('Ongeldige accountstatus.');
                    $values = UserRepository::profileValues($request->inputScalar('display_name') ?? '',
                        $request->inputScalar('signature') ?? '', $request->inputScalar('email') ?? '');
                    $this->users->manage($targetName, $active === '1', $ids, $values);
                } else throw new \InvalidArgumentException('Onbekende beheeractie.');
            }
            $this->audit($user['username'], 'accounts.' . $action, $targetName, $request);
            return Response::redirect('/manage?status=' . rawurlencode('Wijziging opgeslagen.')
                . (str_starts_with($action, 'group-') ? '#groups' : '#accounts'));
        } catch (\InvalidArgumentException $exception) {
            return Response::redirect('/manage?status=' . rawurlencode($exception->getMessage()));
        } catch (\RuntimeException $exception) {
            error_log('Accountbeheer: ' . $exception->getMessage());
            return Response::redirect('/manage?status=' . rawurlencode('Wijziging mislukt. Controleer de serverlog.'));
        }
    }

    private function audit(string $actor, string $action, string $target, Request $request): void
    {
        (new AuditLogRepository($this->pdo))->log($actor, $action, 'account', $target, [], $request->clientIp());
    }
}
