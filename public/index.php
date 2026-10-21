<?php

declare(strict_types=1);

use NnSpotty\Config\AppConfig;
use NnSpotty\Auth\SessionAuth;
use NnSpotty\Audit\AuditLogRepository;
use NnSpotty\Database\ConnectionFactory;
use NnSpotty\Http\Request;
use NnSpotty\Http\Response;
use NnSpotty\Security\Csrf;
use NnSpotty\Security\LoginRateLimiter;
use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\SpotRepository;
use NnSpotty\Usenet\SpotImageService;
use NnSpotty\Usenet\RetrieveService;
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Users\UserRepository;
use NnSpotty\UI\AdminPageRenderer;
use NnSpotty\UI\LoginPageRenderer;
use NnSpotty\UI\PreferencesPageRenderer;
use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotsPageRenderer;
use NnSpotty\UI\SpotColumns;
use NnSpotty\Usenet\SpotFilters;
use NnSpotty\Usenet\SpotCategories;

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Composer autoload ontbreekt. Run: composer install --no-dev --optimize-autoloader';
    exit;
}
require_once $autoloadPath;

require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/Http/Request.php';
require_once __DIR__ . '/../src/Http/Response.php';
require_once __DIR__ . '/../src/Http/Router.php';
require_once __DIR__ . '/../src/Auth/SessionAuth.php';
require_once __DIR__ . '/../src/Audit/AuditLogRepository.php';
require_once __DIR__ . '/../src/Config/AppConfig.php';
require_once __DIR__ . '/../src/Database/ConnectionFactory.php';
require_once __DIR__ . '/../src/Security/Csrf.php';
require_once __DIR__ . '/../src/Security/LoginRateLimiter.php';
require_once __DIR__ . '/../src/State/AppStateRepository.php';
require_once __DIR__ . '/../src/Users/UserRepository.php';
require_once __DIR__ . '/../src/UI/AdminPageRenderer.php';
require_once __DIR__ . '/../src/UI/LoginPageRenderer.php';
require_once __DIR__ . '/../src/UI/PreferencesPageRenderer.php';
require_once __DIR__ . '/../src/UI/SpotDetailPageRenderer.php';
require_once __DIR__ . '/../src/UI/SpotsPageRenderer.php';

loadEnvFile(__DIR__ . '/../.env');
$apiRequest = Request::fromGlobals($_SERVER, $_GET, $_POST);
if (in_array($apiRequest->path(), ['/api', '/api/'], true)) {
    try {
        $apiPdo = ConnectionFactory::fromEnvironment();
        $apiResponse = (new \NnSpotty\Newznab\Api($apiPdo, new AppStateRepository($apiPdo), AppConfig::fromEnvironment()->url))->handle($apiRequest);
    } catch (Throwable $exception) {
        error_log('Newznab startup: ' . $exception->getMessage());
        $apiResponse = \NnSpotty\Newznab\Api::error(900, 'API initialisatie mislukt. Controleer de serverlog.', 500);
    }
    ($apiRequest->method() === 'HEAD' ? $apiResponse->withoutBody() : $apiResponse)->send();
    exit;
}
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('nnspotty_session');
$sessionStartedAt = hrtime(true);
session_start();
$sessionMilliseconds = (hrtime(true) - $sessionStartedAt) / 1000000;

$config = AppConfig::fromEnvironment();
$router = new NnSpotty\Http\Router();
$databaseStartedAt = hrtime(true);
$pdo = ConnectionFactory::fromEnvironment();
$databaseMilliseconds = (hrtime(true) - $databaseStartedAt) / 1000000;
$state = new AppStateRepository($pdo);
$users = new UserRepository($pdo);
$auth = new SessionAuth($users);
$csrf = new Csrf();
$loginRateLimiter = new LoginRateLimiter($pdo);
$auditLogs = new AuditLogRepository($pdo);
$spots = new SpotRepository($pdo);

$router->get('/', static fn (Request $_request): Response => Response::redirect('/spots'));

$router->get('/health', static fn (Request $_request): Response => Response::json([
    'status' => 'ok',
    'service' => 'nnspotty',
    'version' => $config->version,
    'codename' => $config->codename,
]));

$router->get('/login', static function (Request $request) use ($auth, $csrf): Response {
    if ($auth->isAuthenticated()) {
        return Response::redirect('/spots');
    }

    $html = LoginPageRenderer::render($request->query('status'), $csrf->field());

    return Response::html($html);
});

$router->get('/retrieve/status', static function (Request $_request) use ($auth, $pdo, $state): Response {
    if (!$auth->isAuthenticated()) {
        return Response::privateJson(['error' => 'Login vereist.'], 401);
    }

    session_write_close();
    return Response::privateJson((new RetrieveService($pdo, $state))->status());
});

$router->post('/admin/retrieve', static function (Request $request) use ($auth, $csrf, $pdo, $state): Response {
    if (!$auth->can('retrieve.run')) {
        return $request->expectsJson()
            ? Response::privateJson(['error' => 'Admin login vereist.'], 403)
            : Response::redirect('/login?status=' . rawurlencode('Admin login vereist.'));
    }
    try {
        $csrfToken = $request->inputScalar('_csrf');
    } catch (InvalidArgumentException $exception) {
        return $request->expectsJson()
            ? Response::privateJson(['error' => $exception->getMessage()], 400)
            : Response::redirect('/spots');
    }
    if (!$csrf->verify($csrfToken)) {
        return $request->expectsJson()
            ? Response::privateJson(['error' => 'Ongeldige CSRF-token.'], 403)
            : Response::redirect('/spots');
    }

    session_write_close();
    try {
        $result = (new RetrieveService($pdo, $state))->run(
            'since-last',
            null,
            logger: static fn (string $message): bool => error_log($message)
        );
    } catch (DomainException $exception) {
        return $request->expectsJson()
            ? Response::privateJson(['error' => $exception->getMessage()], 409)
            : Response::redirect('/spots');
    } catch (Throwable $exception) {
        error_log('Manual Spotnet retrieve: ' . $exception->getMessage());
        return $request->expectsJson()
            ? Response::privateJson(['error' => 'Retrieve mislukt. Controleer de serverlog.'], 500)
            : Response::redirect('/spots');
    }

    return $request->expectsJson()
        ? Response::privateJson(['status' => $result['status'], 'message' => $result['message']])
        : Response::redirect('/spots');
});

$router->post('/login', static function (Request $request) use ($auth, $csrf, $loginRateLimiter): Response {
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/login?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $username = trim((string) ($request->input('username') ?? ''));
    $password = (string) ($request->input('password') ?? '');
    $clientIp = $request->clientIp();

    if ($username === '' || $password === '') {
        return Response::redirect('/login?status=' . rawurlencode('Username en password zijn verplicht.'));
    }

    if ($loginRateLimiter->isBlocked($clientIp, $username)) {
        return Response::redirect('/login?status=' . rawurlencode($loginRateLimiter->lockoutMessage()));
    }

    if (!$auth->login($username, $password)) {
        $loginRateLimiter->registerAttempt($clientIp, $username, false);
        return Response::redirect('/login?status=' . rawurlencode('Inloggen mislukt.'));
    }

    $loginRateLimiter->registerAttempt($clientIp, $username, true);

    return Response::redirect('/spots');
});

$router->post('/logout', static function (Request $request) use ($auth, $csrf): Response {
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/login?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $auth->logout();

    return Response::redirect('/login?status=' . rawurlencode('Uitgelogd.'));
});

$router->get('/admin', static function (Request $request) use ($state, $auth, $csrf, $auditLogs, $pdo): Response {
    if (!$auth->can('settings.manage')) {
        if ($auth->can('spotters.manage') || $auth->can('audit.view') || $auth->can('users.manage') || $auth->can('groups.manage')) return Response::redirect('/manage');
        return Response::redirect('/login?status=' . rawurlencode('Admin login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $html = AdminPageRenderer::render(
        settings: $state->getUsenetSettings(),
        spotterLists: $state->getSpotterLists(),
        comments: $state->spotContentSettings()['show_comments'] ? $state->getComments($state->canViewErotica($currentUser['username'])) : [],
        auditLogs: $auth->can('audit.view') ? $auditLogs->recent(50) : [],
        statusMessage: $request->query('status'),
        currentUsername: $currentUser['username'],
        csrfField: $csrf->field(),
        allowErotica: $state->eroticaAllowed(),
        theme: $state->getPreferences($currentUser['username'])['theme'],
        globalColumns: $state->globalSpotColumns(),
        spotContent: $state->spotContentSettings(),
        apiEnabled: $state->apiEnabled(),
        sabSettings: $auth->isAdmin()
            ? \NnSpotty\UI\SabSettings::render((new \NnSpotty\Downloader\SabRepository($pdo))->settings('central'), $csrf->field(), true, true)
            : '<p>De centrale SABnzbd-koppeling en categorietoewijzing zijn uitsluitend voor beheerders.</p>',
        metadataSettings: \NnSpotty\Metadata\View::settings(new \NnSpotty\Metadata\Repository($pdo), $csrf->field()),
        musicSettings: \NnSpotty\Music\View::settings(new \NnSpotty\Music\Repository($pdo), $csrf->field()),
        spotterSettings: $auth->can('spotters.manage') ? \NnSpotty\Moderation\View::settings(new \NnSpotty\Moderation\SpotterLists($pdo), $csrf->field()) : '',
        commentRetrieval: $state->commentRetrievalSettings()
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->post('/admin/content', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('settings.manage')) {
        return new Response(403, 'Admin login vereist.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    $value = $request->input('allow_erotica');
    if ($value !== null && $value !== '1') {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige zichtbaarheidsinstelling.'));
    }
    $allowed = $value === '1';
    $state->setEroticaAllowed($allowed);
    $auditLogs->log(
        actorUsername: $auth->user()['username'],
        action: 'admin.content.updated',
        targetType: 'settings',
        targetId: 'content',
        details: ['allow_erotica' => $allowed],
        ipAddress: $request->clientIp()
    );
    return Response::redirect('/admin?status=' . rawurlencode('Zichtbaarheid opgeslagen.') . '#appearance');
});

$router->post('/admin/columns', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('settings.manage')) {
        return new Response(403, 'Admin login vereist.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    try {
        $columns = SpotColumns::fromRequest($request, false);
        $state->saveGlobalSpotColumns($columns);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()) . '#columns');
    }
    $auditLogs->log($auth->user()['username'], 'admin.columns.updated', 'settings', 'spot_columns', ['columns' => $columns], $request->clientIp());
    return Response::redirect('/admin?status=' . rawurlencode('Globale kolommen opgeslagen.') . '#columns');
});

$router->post('/admin/spot-content', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('settings.manage')) {
        return new Response(403, 'Admin login vereist.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    try {
        $settings = [];
        foreach (['retrieve_comments', 'show_comments', 'images_enabled'] as $key) {
            $value = $request->inputScalar($key);
            if ($value !== null && $value !== '1') {
                throw new InvalidArgumentException('Ongeldige inhoudsinstelling: ' . $key . '.');
            }
            $settings[$key] = $value === '1';
        }
        $commentRetrieval = $state->commentRetrievalSettings();
        if ($request->hasInput('comment_mode') || $request->hasInput('comment_retention')) {
            $retention = trim($request->inputScalar('comment_retention') ?? '');
            if (preg_match('/^\d{1,5}$/D', $retention) !== 1) {
                throw new InvalidArgumentException('Reactie-retentie moet een geheel aantal dagen zijn (0 = spot-retentie).');
            }
            $commentRetrieval = ['mode' => $request->inputScalar('comment_mode') ?? '', 'retention' => (int) $retention];
            $state->saveCommentRetrievalSettings($commentRetrieval);
        }
        $state->saveSpotContentSettings($settings);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()) . '#spot-content');
    }
    $auditLogs->log($auth->user()['username'], 'admin.spot_content.updated', 'settings', 'spot_content', $settings + ['comment_retrieval' => $commentRetrieval], $request->clientIp());
    return Response::redirect('/admin?status=' . rawurlencode('Inhoudsinstellingen opgeslagen.') . '#spot-content');
});

$router->post('/preferences/columns', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/preferences?status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    $username = $auth->user()['username'];
    try {
        $columns = SpotColumns::fromRequest($request, true);
        $state->savePersonalSpotColumns($username, $columns);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/preferences?status=' . rawurlencode($exception->getMessage()) . '#columns');
    }
    $auditLogs->log($username, 'user.columns.updated', 'user', $username, ['columns' => $columns], $request->clientIp());
    return Response::redirect('/preferences?status=' . rawurlencode('Persoonlijke kolommen opgeslagen.') . '#columns');
});

$router->post('/admin/settings', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('settings.manage')) {
        return Response::redirect('/login?status=' . rawurlencode('Admin login vereist.'));
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $server = trim((string) ($request->input('server') ?? ''));
    $port = (int) ($request->input('port') ?? '0');
    $retention = (int) ($request->input('retention') ?? '-1');
    $retrieveAfter = $request->inputScalar('retrieve_after');
    $encryption = strtolower((string) ($request->input('encryption') ?? 'none'));
    $groupPattern = trim((string) ($request->input('group_pattern') ?? ''));
    $explicitGroupsRaw = trim((string) ($request->input('explicit_groups') ?? ''));
    $discoverMaxGroups = (int) ($request->input('discover_max_groups') ?? '-1');
    $maxArticlesPerGroup = (int) ($request->input('max_articles_per_group') ?? '0');
    try {
        $pipelineRaw = $request->hasInput('pipeline_depth') ? ($request->inputScalar('pipeline_depth') ?? '') : '32';
        if (!preg_match('/^(?:[1-9]|[1-9][0-9]|1[01][0-9]|12[0-8])$/D', $pipelineRaw)) {
            throw new InvalidArgumentException('Pipeline-diepte moet een geheel getal tussen 1 en 128 zijn.');
        }
        $pipelineDepth = (int) $pipelineRaw;
        $postingPortRaw = $request->hasInput('posting_port') ? ($request->inputScalar('posting_port') ?? '') : '563';
        if (!preg_match('/^[1-9][0-9]{0,4}$/D', $postingPortRaw)) {
            throw new InvalidArgumentException('Postingpoort moet een geheel getal tussen 1 en 65535 zijn.');
        }
        $postingCredentialsRaw = $request->inputScalar('posting_custom_credentials');
        if ($postingCredentialsRaw !== null && $postingCredentialsRaw !== '1') {
            throw new InvalidArgumentException('Ongeldige keuze voor de postinglogin.');
        }
        $postingSettings = [
            'posting_server' => trim($request->inputScalar('posting_server') ?? ''),
            'posting_port' => (int) $postingPortRaw,
            'posting_encryption' => $request->hasInput('posting_encryption') ? ($request->inputScalar('posting_encryption') ?? '') : 'ssl',
            'posting_custom_credentials' => $postingCredentialsRaw === '1',
            'posting_username' => trim($request->inputScalar('posting_username') ?? ''),
            'posting_password' => $request->inputScalar('posting_password') ?? '',
        ];
        \NnSpotty\Usenet\NntpClientFactory::postingSettings($postingSettings + [
            'server' => $server, 'port' => $port, 'encryption' => $encryption,
            'username' => '', 'password' => '',
        ]);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()));
    }
    $explicitGroups = str_replace(["\r\n", "\r"], "\n", $explicitGroupsRaw);
    $explicitGroups = str_replace("\n", ',', $explicitGroups);
    $explicitGroups = preg_replace('/\s*,\s*/', ',', $explicitGroups) ?? '';
    $explicitGroups = trim($explicitGroups, ',');

    try {
        SpotnetParser::groups($explicitGroups);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()));
    }

    $validRetrieveAfter = $retrieveAfter === null
        || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $retrieveAfter) === 1
            && ($parsedRetrieveAfter = DateTimeImmutable::createFromFormat('!Y-m-d', $retrieveAfter)) !== false
            && $parsedRetrieveAfter->format('Y-m-d') === $retrieveAfter);
    if ($server === '' || $port < 1 || $port > 65535 || $retention < 0 || !$validRetrieveAfter
        || !in_array($encryption, ['none', 'ssl', 'tls'], true) || $discoverMaxGroups < 0 || $maxArticlesPerGroup < 100) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige usenet instellingen.'));
    }

    $state->saveUsenetSettings([
        'retention' => $retention,
        'retrieve_after' => $retrieveAfter,
        'server' => $server,
        'port' => $port,
        'username' => trim((string) ($request->input('username') ?? '')),
        'password' => (string) ($request->input('password') ?? ''),
        'encryption' => $encryption,
        'explicit_groups' => $explicitGroups,
        'group_pattern' => $groupPattern,
        'discover_max_groups' => $discoverMaxGroups,
        'max_articles_per_group' => $maxArticlesPerGroup,
        'pipeline_depth' => $pipelineDepth,
    ] + $postingSettings);
    $currentUser = $auth->user();
    if ($currentUser !== null) {
        $auditLogs->log(
            actorUsername: $currentUser['username'],
            action: 'admin.settings.updated',
            targetType: 'usenet_settings',
            targetId: 'default',
            details: [
                'retention' => $retention,
                'retrieve_after' => $retrieveAfter,
                'server' => $server,
                'port' => $port,
                'encryption' => $encryption,
                'explicit_groups' => $explicitGroups,
                'group_pattern' => $groupPattern,
                'discover_max_groups' => $discoverMaxGroups,
                'max_articles_per_group' => $maxArticlesPerGroup,
                'pipeline_depth' => $pipelineDepth,
                'posting_server' => $postingSettings['posting_server'],
                'posting_port' => $postingSettings['posting_port'],
                'posting_encryption' => $postingSettings['posting_encryption'],
                'posting_custom_credentials' => $postingSettings['posting_custom_credentials'],
            ],
            ipAddress: $request->clientIp()
        );
    }

    return Response::redirect('/admin?status=' . rawurlencode('Usenet instellingen opgeslagen.'));
});

$router->post('/admin/spotter-lists', static function (Request $request) use ($pdo, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('spotters.manage')) return new Response(403, 'Spotterbeheerrecht vereist.');
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.') . '#moderation');
    }
    $actor = $auth->user()['username'];
    session_write_close();
    try {
        $lists = new \NnSpotty\Moderation\SpotterLists($pdo);
        $action = $request->inputScalar('action');
        if ($action === 'sync') {
            $lists->sync(true);
            $details = ['counts' => $lists->totals()];
            $message = 'Spotlist whitelist en blacklist bijgewerkt.';
        } elseif ($action === 'settings') {
            $enabled = $request->inputScalar('enabled');
            if ($enabled !== null && $enabled !== '1') throw new InvalidArgumentException('Ongeldige spotterlijstinstelling.');
            $lists->enable($enabled === '1');
            $details = ['enabled' => $enabled === '1'];
            $message = 'Spotterlijstinstellingen opgeslagen.';
        } else {
            throw new InvalidArgumentException('Onbekende spotterlijstactie.');
        }
        $auditLogs->log($actor, 'admin.spotter_lists.' . $action, 'settings', 'spotter_lists', $details, $request->clientIp());
    } catch (InvalidArgumentException $exception) {
        $message = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Spotterlijsten: ' . $exception->getMessage());
        $message = 'Spotterlijstactie mislukt. Controleer de synchronisatiestatus en serverlog.';
    }
    return Response::redirect('/admin?status=' . rawurlencode($message) . '#moderation');
});

$router->post('/admin/spotters', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('spotters.manage')) {
        return Response::redirect('/login?status=' . rawurlencode('Admin login vereist.'));
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $listType = strtolower((string) ($request->input('list_type') ?? ''));
    $spotter = trim((string) ($request->input('spotter') ?? ''));

    if ($spotter === '' || !in_array($listType, ['whitelist', 'blacklist'], true)) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige spotter invoer.'));
    }

    $state->addSpotter($listType, $spotter);
    $currentUser = $auth->user();
    if ($currentUser !== null) {
        $auditLogs->log(
            actorUsername: $currentUser['username'],
            action: 'admin.spotter.added',
            targetType: 'spotter_list',
            targetId: $listType,
            details: [
                'spotter' => $spotter,
            ],
            ipAddress: $request->clientIp()
        );
    }

    return Response::redirect('/admin?status=' . rawurlencode('Spotter toegevoegd aan ' . $listType . '.') . '#moderation');
});

$router->post('/admin/comments', static function (Request $request) use ($state, $auth, $csrf): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$state->spotContentSettings()['show_comments']) {
        return new Response(403, 'Reacties zijn door de beheerder uitgeschakeld.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    return new Response(410, 'Lokale reactieplaatsing is vervallen. Publiceer via de detailpagina van de spot op Usenet.',
        ['Content-Type' => 'text/plain; charset=utf-8']);
});

$router->get('/preferences', static function (Request $request) use ($state, $auth, $csrf, $pdo): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    $username = $currentUser['username'];
    $newKey = $_SESSION['new_api_key'] ?? null;
    unset($_SESSION['new_api_key']);

    $html = PreferencesPageRenderer::render(
        username: $username,
        preferences: $state->getPreferences($username),
        statusMessage: $request->query('status'),
        isAdmin: $currentUser['is_admin'],
        csrfField: $csrf->field(),
        allowErotica: $state->eroticaAllowed(),
        globalColumns: $state->globalSpotColumns(),
        personalColumns: $state->personalSpotColumns($username),
        apiEnabled: $state->apiEnabled() && $auth->can('api.use'),
        hasApiKey: (new \NnSpotty\Newznab\ApiKeys($pdo))->exists($username),
        newApiKey: is_array($newKey) && $newKey['username'] === $username ? $newKey['key'] : null,
        sabSettings: $auth->can('nzb.forward')
            ? \NnSpotty\UI\SabSettings::render((new \NnSpotty\Downloader\SabRepository($pdo))->settings('user:' . $username), $csrf->field(), false, $currentUser['is_admin'])
            : '<p>Je account heeft geen recht om NZB-bestanden door te sturen.</p>',
        metadataSettings: \NnSpotty\Metadata\View::settings(new \NnSpotty\Metadata\Repository($pdo), $csrf->field(), $username),
        musicSettings: \NnSpotty\Music\View::settings(new \NnSpotty\Music\Repository($pdo), $csrf->field(), $username),
        profileSettings: \NnSpotty\Users\View::profile($currentUser, $csrf->field())
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
});

$sabHandler = static function (Request $request) use ($pdo, $state, $auth, $csrf): Response {
    $user = $auth->user();
    // Release the browser session before provider requests and uploads.
    session_write_close();
    return (new \NnSpotty\Downloader\SabController($pdo, $state))->handle($request, $user, $csrf);
};
$router->get('/downloader', $sabHandler);
$router->post('/downloader', $sabHandler);
$router->post('/metadata', static function (Request $request) use ($pdo, $state, $auth, $csrf): Response {
    $user = $auth->user();
    session_write_close();
    return (new \NnSpotty\Metadata\Controller($pdo, $state))->handle($request, $user, $csrf);
});
$router->post('/music-metadata', static function (Request $request) use ($pdo, $state, $auth, $csrf): Response {
    $user = $auth->user();
    session_write_close();
    return (new \NnSpotty\Music\Controller($pdo, $state, \NnSpotty\Config\AppConfig::fromEnvironment()->url))->handle($request, $user, $csrf);
});

$router->post('/admin/newznab', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->can('settings.manage')) {
        return new Response(403, 'Admin login vereist.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return new Response(403, 'Ongeldige CSRF token.');
    }
    try {
        $enabled = $request->inputScalar('enabled');
        if ($enabled !== null && $enabled !== '1') {
            throw new InvalidArgumentException('Ongeldige API-instelling.');
        }
        $state->setApiEnabled($enabled === '1');
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()) . '#newznab');
    }
    $auditLogs->log($auth->user()['username'], 'admin.newznab.updated', 'settings', 'newznab',
        ['enabled' => $enabled === '1'], $request->clientIp());
    return Response::redirect('/admin?status=' . rawurlencode('Newznab-instelling opgeslagen.') . '#newznab');
});

$router->post('/preferences/api-key', static function (Request $request) use ($state, $pdo, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAuthenticated()) {
        return new Response(401, 'Login vereist.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return new Response(403, 'Ongeldige CSRF token.');
    }
    $username = $auth->user()['username'];
    try {
        $action = $request->inputScalar('action');
        $keys = new \NnSpotty\Newznab\ApiKeys($pdo);
        if ($action === 'revoke') {
            $keys->revoke($username);
            unset($_SESSION['new_api_key']);
        } elseif ($action === 'rotate' && $state->apiEnabled()) {
            $_SESSION['new_api_key'] = ['username' => $username, 'key' => $keys->rotate($username)];
        } else {
            throw new InvalidArgumentException('Ongeldige actie of API is uitgeschakeld.');
        }
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/preferences?status=' . rawurlencode($exception->getMessage()) . '#api-key');
    }
    $auditLogs->log($username, 'user.api_key.' . $action, 'user', $username, [], $request->clientIp());
    return Response::redirect('/preferences?status=' . rawurlencode('API-key bijgewerkt.') . '#api-key');
});

$router->post('/favorites', static function (Request $request) use ($auth, $csrf, $state, $spots, $pdo): Response {
    $user = $auth->user();
    if ($user === null) return Response::privateJson(['error' => 'Login vereist.'], 401);
    if (!$csrf->verify($request->input('_csrf'))) return Response::privateJson(['error' => 'Ongeldige CSRF token.'], 403);
    session_write_close();
    try {
        $id = $request->inputScalar('id') ?? '';
        $favorite = $request->inputScalar('favorite');
        if (!preg_match('/^[a-f0-9]{64}$/D', $id) || !in_array($favorite, ['0', '1'], true)) {
            throw new InvalidArgumentException('Ongeldige favorietactie.');
        }
        if ($spots->getSpot($id, $state->canViewErotica($user['username'])) === null) {
            return Response::privateJson(['error' => 'Spot niet gevonden.'], 404);
        }
        (new \NnSpotty\Favorites\Repository($pdo))->set($user['username'], $id, $favorite === '1');
        return Response::privateJson(['id' => $id, 'favorite' => $favorite === '1']);
    } catch (InvalidArgumentException $exception) {
        return Response::privateJson(['error' => $exception->getMessage()], 400);
    } catch (Throwable $exception) {
        error_log('Favorieten opslaan: ' . $exception->getMessage());
        return Response::privateJson(['error' => 'Favoriet opslaan mislukt. Probeer opnieuw.'], 500);
    }
});

$router->get('/spots', static function (Request $request) use ($auth, $csrf, $state, $spots, $pdo): Response {
    $isBatch = $request->query('batch') === '1';
    if (!$auth->isAuthenticated()) {
        return $isBatch ? Response::privateJson(['error' => 'Login vereist.'], 401) : Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $csrfField = $isBatch ? '' : $csrf->field();
    if (!$isBatch) {
        $auth->newSpotsSince();
    }
    session_write_close();
    $preferences = $state->getPreferences($currentUser['username']);
    $showErotica = $state->canViewErotica($currentUser['username']);
    try {
        $selectedCategory = trim($request->queryScalar('category') ?? '') ?: null;
        $filters = SpotFilters::parse($request->queryScalar('subcategory'));
        $search = trim($request->queryScalar('q') ?? '');
        if (strlen($search) > 200 || preg_match('//u', $search) !== 1) {
            throw new InvalidArgumentException('Zoektekst moet geldige tekst van maximaal 200 bytes zijn.');
        }
        $cursor = $request->queryScalar('cursor');
        $favorites = $request->queryScalar('favorites');
        if ($favorites !== null && $favorites !== '1') throw new InvalidArgumentException('Ongeldig favorietenfilter.');
        $favoritesOnly = $favorites === '1';
        $batch = $spots->batch($selectedCategory, (int) $preferences['items_per_page'], $showErotica, $filters, $search, $cursor,
            $favoritesOnly ? $currentUser['username'] : null);
        $batch['spots'] = (new \NnSpotty\Favorites\Repository($pdo))->annotate($batch['spots'], $currentUser['username']);
    } catch (InvalidArgumentException $exception) {
        return Response::privateJson(['error' => $exception->getMessage()], 400);
    }
    $columns = $state->effectiveSpotColumns($currentUser['username'], $selectedCategory);
    if (!$state->spotContentSettings()['images_enabled']) {
        $columns = array_values(array_diff($columns, ['cover']));
    }
    if ($isBatch) {
        return Response::privateJson([
            'html' => $batch['spots'] === [] ? '' : SpotsPageRenderer::rows($batch['spots'], $columns),
            'count' => count($batch['spots']),
            'next' => SpotsPageRenderer::nextUrl(['category' => $selectedCategory, 'subcategory' => implode(',', $filters), 'q' => $search]
                + ($favoritesOnly ? ['favorites' => '1'] : []), $batch['next']),
        ]);
    }

    $html = SpotsPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        spots: $batch['spots'],
        selectedCategory: $selectedCategory,
        csrfField: $csrfField,
        facets: null,
        filters: $filters,
        search: $search,
        theme: $preferences['theme'],
        showErotica: $showErotica,
        columns: $columns,
        nextCursor: $batch['next'],
        favoritesOnly: $favoritesOnly
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->get('/spots/facets', static function (Request $request) use ($auth, $state, $spots): Response {
    $user = $auth->user();
    if ($user === null) {
        return Response::privateJson(['error' => 'Login vereist.'], 401);
    }
    $newSince = $auth->newSpotsBoundary();
    session_write_close();
    if ($newSince === null) {
        return Response::privateJson(['error' => 'Open eerst het spotsoverzicht om de bezoekgrens vast te leggen.'], 409);
    }
    try {
        $category = trim($request->queryScalar('category') ?? '');
        $filters = SpotFilters::parse($request->queryScalar('subcategory'));
        $search = trim($request->queryScalar('q') ?? '');
        $favorites = $request->queryScalar('favorites');
        if ($favorites !== null && $favorites !== '1') throw new InvalidArgumentException('Ongeldig favorietenfilter.');
        if (SpotCategories::headForCategory($category) === null || strlen($search) > 200 || preg_match('//u', $search) !== 1) {
            throw new InvalidArgumentException('Ongeldige categorie of zoektekst voor nieuwtellers.');
        }
        $facets = $spots->facets($category, $state->canViewErotica($user['username']), $search, $filters, $newSince,
            $favorites === '1' ? $user['username'] : null);
        $counts = [];
        foreach ($facets as $facet) {
            $counts[$facet['code']] = (int) $facet['total'];
        }
        return Response::privateJson(['counts' => (object) $counts]);
    } catch (InvalidArgumentException $exception) {
        return Response::privateJson(['error' => $exception->getMessage()], 400);
    } catch (Throwable $exception) {
        error_log('Spot facets: ' . $exception->getMessage());
        return Response::privateJson(['error' => 'Nieuwtellers ophalen mislukt. Controleer de serverlog.'], 500);
    }
});

$router->get('/spots/preview', static function (Request $request) use ($auth, $state, $spots, $pdo): Response {
    if (!$auth->isAuthenticated()) {
        return Response::privateJson(['error' => 'Login vereist.'], 401);
    }
    session_write_close();
    try {
        $id = $request->queryScalar('key') ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new InvalidArgumentException('Ongeldige spot.');
        }
    } catch (InvalidArgumentException $exception) {
        return Response::privateJson(['error' => $exception->getMessage()], 400);
    }
    $spot = $spots->getSpot($id, $state->canViewErotica($auth->user()['username']));
    $content = $state->spotContentSettings();
    if ($spot === null) return Response::privateJson(['error' => 'Spot niet gevonden.'], 404);
    $spot = (new \NnSpotty\Favorites\Repository($pdo))->annotate([$spot], $auth->user()['username'])[0];
    $metadata = $spot['category'] === 'Geluid'
        ? (new \NnSpotty\Music\Repository($pdo))->previewMetadata($spot, $auth->user()['username'], $content['images_enabled'])
        : (new \NnSpotty\Metadata\Repository($pdo))->previewMetadata($spot, $auth->user()['username']);
    return Response::privateJson(['html' => SpotDetailPageRenderer::summary(
        $spot, true, $content['images_enabled'], $content['show_comments'], $metadata
    )]);
});

$router->get('/spots/view', static function (Request $request) use ($auth, $csrf, $spots, $state, $pdo): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $spotId = trim((string) ($request->query('key') ?? ''));
    if ($spotId === '') {
        return Response::redirect('/spots');
    }

    $spot = $spots->getSpot($spotId, $state->canViewErotica($currentUser['username']));
    if ($spot === null) {
        return Response::notFound();
    }

    $csrfField = $csrf->field();
    $auth->newSpotsSince();
    session_write_close();
    $spot = (new \NnSpotty\Favorites\Repository($pdo))->annotate([$spot], $currentUser['username'])[0];
    $content = $state->spotContentSettings();
    $html = SpotDetailPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        spot: $spot,
        comments: $content['show_comments'] ? $spots->comments($spotId) : [],
        statusMessage: $request->query('status'),
        csrfField: $csrfField,
        theme: $state->getPreferences($currentUser['username'])['theme'],
        showErotica: $state->canViewErotica($currentUser['username']),
        showComments: $content['show_comments'],
        imagesEnabled: $content['images_enabled'],
        retrieveComments: $content['retrieve_comments'],
        metadataControls: $spot['category'] === 'Geluid'
            ? ((new \NnSpotty\Music\Repository($pdo))->allowed($currentUser['username'])
                ? \NnSpotty\Music\View::controls($spot, $auth->can('metadata.manage'), $csrfField) : '')
            : ((new \NnSpotty\Metadata\Repository($pdo))->allowed($currentUser['username'])
                ? \NnSpotty\Metadata\View::controls($spot, $auth->can('metadata.manage'), $csrfField) : ''),
        facets: null,
        pendingComments: $content['show_comments'] ? $spots->pendingCommentCount($spotId) : 0
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->get('/spots/image', static function (Request $request) use ($auth, $spots, $state): Response {
    if (!$auth->isAuthenticated()) {
        return new Response(401, 'Login vereist.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
    session_write_close();
    if (!$state->spotContentSettings()['images_enabled']) {
        return new Response(403, 'Afbeeldingen zijn door de beheerder uitgeschakeld.', ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }
    $id = (string) ($request->query('key') ?? '');
    $spot = $spots->getSpot($id, $state->canViewErotica($auth->user()['username']));
    if ($spot === null || $spot['image_segments'] === []) {
        return Response::notFound();
    }
    try {
        $image = (new SpotImageService($spots, $state))->load($spot);
        return new Response(200, $image['image_data'], [
            'Content-Type' => $image['mime_type'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    } catch (Throwable $exception) {
        error_log('Spot image ' . $id . ': ' . $exception->getMessage());
        return new Response(502, 'Afbeelding ophalen mislukt. Controleer de serverlog.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
});

$router->get('/spots/nzb', static function (Request $request) use ($auth, $spots, $state, $pdo): Response {
    if (!$auth->isAuthenticated()) {
        return new Response(401, 'Login vereist.');
    }
    session_write_close();
    try {
        $id = $request->queryScalar('key') ?? '';
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Ongeldig spot-ID.');
        }
    } catch (InvalidArgumentException $exception) {
        return new Response(400, $exception->getMessage(), ['Cache-Control' => 'private, no-store']);
    }
    $spot = $spots->getSpot($id, $state->canViewErotica($auth->user()['username']));
    if ($spot === null || $spot['nzb_segments'] === []) {
        return Response::notFound();
    }
    try {
        return \NnSpotty\Usenet\SpotNzbService::download((new \NnSpotty\Usenet\SpotNzbService($pdo, $state))->load($spot), $id);
    } catch (Throwable $exception) {
        error_log('Spot NZB ' . $id . ': ' . $exception->getMessage());
        return new Response(502, 'NZB ophalen mislukt. Controleer de serverlog.', [
            'Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store',
        ]);
    }
});

$router->post('/spots/comment', static function (Request $request) use ($auth, $csrf, $spots, $state, $pdo): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$state->spotContentSettings()['show_comments']) {
        return new Response(403, 'Reacties zijn door de beheerder uitgeschakeld.');
    }
    $id = (string) ($request->input('spot_id') ?? '');
    $spot = $spots->getSpot($id, $state->canViewErotica($auth->user()['username']));
    if ($spot === null) {
        return Response::notFound();
    }
    $location = '/spots/view?key=' . rawurlencode($id);
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect($location . '&status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    $user = $auth->user();
    if ($user === null) {
        return Response::redirect('/login');
    }
    $csrfField = $csrf->field();
    $preferences = $state->getPreferences($user['username']);
    session_write_close();
    $body = '';
    $publicationId = null;
    try {
        $body = $request->inputScalar('comment') ?? '';
        $publicationId = $request->inputScalar('publication_id');
        if ($request->inputScalar('publish_consent') !== '1') {
            throw new InvalidArgumentException('Bevestig dat je de reactie openbaar op Usenet wilt publiceren.');
        }
        $identityRevision = hash('sha256', $user['display_name'] . "\0" . $user['signature']);
        if ($request->inputScalar('profile_revision') !== null && !hash_equals($identityRevision, $request->inputScalar('profile_revision'))) {
            throw new InvalidArgumentException('Je profiel is gewijzigd. Controleer de nieuwe publicatienaam en handtekening voordat je opnieuw bevestigt.');
        }
        (new \NnSpotty\Comments\Publisher($pdo, $state))->publish($user['username'], $spot, $body, $publicationId ?? '');
        return Response::redirect($location . '&status=' . rawurlencode('Reactie door de Usenet-provider geaccepteerd. Verspreiding naar andere readers kan even duren.') . '#comments');
    } catch (\NnSpotty\Comments\PublicationException | InvalidArgumentException $exception) {
        $message = $exception->getMessage();
        $status = $exception instanceof InvalidArgumentException ? 422 : 502;
    } catch (\Throwable $exception) {
        error_log('Usenet-reactiepublicatie mislukt: ' . $exception->getMessage());
        $message = 'Publiceren kon niet worden afgerond door een serverfout. Controleer de serverlog en de publicatiestatus voordat je opnieuw probeert.';
        $status = 500;
    }
    $content = $state->spotContentSettings();
    $spot = (new \NnSpotty\Favorites\Repository($pdo))->annotate([$spot], $user['username'])[0];
    $html = SpotDetailPageRenderer::render(
        $user['username'], $user['is_admin'], $spot, $spots->comments($id), $csrfField,
        $message, theme: $preferences['theme'], showErotica: $state->canViewErotica($user['username']),
        showComments: $content['show_comments'], imagesEnabled: $content['images_enabled'], retrieveComments: $content['retrieve_comments'],
        metadataControls: $spot['category'] === 'Geluid'
            ? ((new \NnSpotty\Music\Repository($pdo))->allowed($user['username']) ? \NnSpotty\Music\View::controls($spot, \NnSpotty\Users\Permissions::allows($user, 'metadata.manage'), $csrfField) : '')
            : ((new \NnSpotty\Metadata\Repository($pdo))->allowed($user['username']) ? \NnSpotty\Metadata\View::controls($spot, \NnSpotty\Users\Permissions::allows($user, 'metadata.manage'), $csrfField) : ''),
        facets: null,
        commentDraft: strlen($body) <= 10000 && preg_match('//u', $body) === 1 ? $body : '',
        commentRequestId: $publicationId !== null && preg_match('/^[a-f0-9]{64}$/D', $publicationId) ? $publicationId : null,
        commentError: true,
        pendingComments: $content['show_comments'] ? $spots->pendingCommentCount($id) : 0
    );
    return new Response($status, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->post('/spots/comments/load', static function (Request $request) use ($auth, $csrf, $spots, $state, $pdo): Response {
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    $user = $auth->user();
    if ($user === null) {
        return $wantsJson ? Response::privateJson(['error' => 'Login vereist.'], 401) : Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    $id = $request->inputScalar('spot_id') ?? '';
    $location = '/spots/view?key=' . rawurlencode($id);
    $reply = static fn (string $message, int $status) => $wantsJson
        ? Response::privateJson(['error' => $message], $status)
        : Response::redirect($location . '&status=' . rawurlencode($message) . '#comments');
    if (!$csrf->verify($request->input('_csrf'))) return $reply('Ongeldige CSRF token.', 403);
    $csrfField = $csrf->field();
    session_write_close();
    if (!$auth->can('comments.load')) return $reply('Je account heeft geen recht om reacties van Usenet te laden.', 403);
    $content = $state->spotContentSettings();
    if (!$content['show_comments']) return $reply('Reacties zijn door de beheerder uitgeschakeld.', 403);
    if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1 || $spots->getSpot($id, $state->canViewErotica($user['username'])) === null) {
        return $wantsJson ? Response::privateJson(['error' => 'Spot niet gevonden.'], 404) : Response::notFound();
    }
    try {
        $result = (new \NnSpotty\Comments\BodyLoader($pdo, $state))->load($id);
    } catch (Throwable $exception) {
        error_log('Usenet-reacties laden (' . $id . '): ' . $exception::class . ': ' . $exception->getMessage());
        return $reply('Reacties konden niet van Usenet worden geladen. Controleer de Usenet-instellingen en serverlog.', 502);
    }
    $message = $result['loaded'] . ' reacties geladen.'
        . ($result['unavailable'] + $result['invalid'] > 0 ? ' ' . ($result['unavailable'] + $result['invalid']) . ' niet meer beschikbaar of ongeldig.' : '')
        . ($result['remaining'] > 0 ? ' Nog ' . $result['remaining'] . ' te laden.' : '');
    if (!$wantsJson) {
        return Response::redirect($location . '&status=' . rawurlencode($message) . '#comments');
    }
    $comments = $spots->comments($id);
    return Response::privateJson([
        'message' => $message,
        'count' => count($comments),
        'remaining' => $result['remaining'],
        'html' => SpotDetailPageRenderer::commentList($comments, $user['is_admin'], $content['retrieve_comments'], $result['remaining']),
        'loader' => SpotDetailPageRenderer::commentLoader($id, $result['remaining'], $csrfField),
    ]);
});

$router->post('/preferences', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/preferences?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $username = $currentUser['username'];
    $theme = trim((string) ($request->input('theme') ?? 'default'));
    $itemsPerPage = (int) ($request->input('items_per_page') ?? '0');
    $eroticaInput = $request->input('show_erotica');
    if ($eroticaInput !== null && $eroticaInput !== '1') {
        return Response::redirect('/preferences?status=' . rawurlencode('Ongeldige zichtbaarheidsinstelling.'));
    }
    $showErotica = $state->eroticaAllowed()
        ? $eroticaInput === '1'
        : $state->getPreferences($username)['show_erotica'];

    if (!in_array($theme, ['default', 'plex-dark', 'plex-light'], true) || $itemsPerPage < 10 || $itemsPerPage > 200) {
        return Response::redirect('/preferences?status=' . rawurlencode('Ongeldige preference invoer.'));
    }

    $state->savePreferences($username, [
        'theme' => $theme,
        'items_per_page' => $itemsPerPage,
        'show_erotica' => $showErotica,
    ]);
    $auditLogs->log(
        actorUsername: $username,
        action: 'user.preferences.updated',
        targetType: 'user',
        targetId: $username,
        details: [
            'theme' => $theme,
            'items_per_page' => $itemsPerPage,
            'show_erotica' => $showErotica,
        ],
        ipAddress: $request->clientIp()
    );

    return Response::redirect('/preferences?status=' . rawurlencode('Preferences opgeslagen.'));
});

$accountController = new \NnSpotty\Users\Controller($pdo, $users, $auth, $csrf, $state);
$router->get('/account/password', $accountController->profile(...));
$router->post('/account/profile', $accountController->profile(...));
$router->get('/manage', $accountController->manage(...));
$router->post('/manage', $accountController->manage(...));
$router->get('/account/avatar', static function (Request $request) use ($auth, $pdo): Response {
    if (!$auth->isAuthenticated()) return new Response(401, 'Login vereist.');
    $avatar = (new \NnSpotty\Users\AvatarService($pdo))->get($request->query('user', '') ?? '');
    return $avatar === null ? Response::notFound() : new Response(200, $avatar['data'], [
        'Content-Type' => $avatar['mime'], 'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'",
    ]);
});
$request = Request::fromGlobals($_SERVER, $_GET, $_POST);
\NnSpotty\UI\SpotLayout::account($auth->user());
$account = $auth->user();
if ($account !== null && $account['must_change_password']
    && !in_array($request->path(), ['/account/password', '/account/profile', '/logout', '/health'], true)) {
    Response::redirect('/account/password')->send();
    exit;
}
$requiredPermission = match ($request->path()) {
    '/spots/comment' => 'comments.publish',
    '/spots/nzb' => 'nzb.download',
    '/downloader' => 'nzb.forward',
    '/preferences/api-key' => $request->input('action') === 'revoke' ? null : 'api.use',
    default => null,
};
if ($account !== null && $requiredPermission !== null && !$auth->can($requiredPermission)) {
    ($request->expectsJson() ? Response::privateJson(['error' => 'Geen recht voor deze functie.'], 403) : new Response(403, 'Geen recht voor deze functie.'))->send();
    exit;
}
$applicationStartedAt = hrtime(true);
$response = $router->dispatch($request);
if ($auth->isAdmin() && filter_var(envValue('APP_TIMING', 'false'), FILTER_VALIDATE_BOOL)) {
    header(sprintf('Server-Timing: session;dur=%.2f, database;dur=%.2f, application;dur=%.2f',
        $sessionMilliseconds, $databaseMilliseconds, (hrtime(true) - $applicationStartedAt) / 1000000));
}
$response->send();
