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
session_start();

$config = AppConfig::fromEnvironment();
$router = new NnSpotty\Http\Router();
$pdo = ConnectionFactory::fromEnvironment();
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

    return Response::privateJson((new RetrieveService($pdo, $state))->status());
});

$router->post('/admin/retrieve', static function (Request $request) use ($auth, $csrf, $pdo, $state): Response {
    if (!$auth->isAdmin()) {
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
    if (!$auth->isAdmin()) {
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
        auditLogs: $auditLogs->recent(50),
        statusMessage: $request->query('status'),
        currentUsername: $currentUser['username'],
        csrfField: $csrf->field(),
        allowErotica: $state->eroticaAllowed(),
        theme: $state->getPreferences($currentUser['username'])['theme'],
        globalColumns: $state->globalSpotColumns(),
        spotContent: $state->spotContentSettings(),
        apiEnabled: $state->apiEnabled(),
        sabSettings: \NnSpotty\UI\SabSettings::render((new \NnSpotty\Downloader\SabRepository($pdo))->settings('central'), $csrf->field(), true, true),
        metadataSettings: \NnSpotty\Metadata\View::settings(new \NnSpotty\Metadata\Repository($pdo), $csrf->field())
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->post('/admin/content', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAdmin()) {
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
    if (!$auth->isAdmin()) {
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
    if (!$auth->isAdmin()) {
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
        $state->saveSpotContentSettings($settings);
    } catch (InvalidArgumentException $exception) {
        return Response::redirect('/admin?status=' . rawurlencode($exception->getMessage()) . '#spot-content');
    }
    $auditLogs->log($auth->user()['username'], 'admin.spot_content.updated', 'settings', 'spot_content', $settings, $request->clientIp());
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
    if (!$auth->isAdmin()) {
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
    ]);
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
            ],
            ipAddress: $request->clientIp()
        );
    }

    return Response::redirect('/admin?status=' . rawurlencode('Usenet instellingen opgeslagen.'));
});

$router->post('/admin/spotters', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAdmin()) {
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

$router->post('/admin/comments', static function (Request $request) use ($state, $auth, $csrf, $auditLogs, $spots): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$state->spotContentSettings()['show_comments']) {
        return new Response(403, 'Reacties zijn door de beheerder uitgeschakeld.');
    }
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $postId = trim((string) ($request->input('post_id') ?? ''));
    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    $comment = trim((string) ($request->input('comment') ?? ''));
    if ($spots->getSpot($postId, $state->canViewErotica($currentUser['username'])) === null) {
        return Response::notFound();
    }

    if ($postId === '' || $comment === '') {
        return Response::redirect('/admin?status=' . rawurlencode('Comment kon niet worden opgeslagen: onvolledige invoer.'));
    }

    $state->addComment($postId, $currentUser['username'], $comment);
    $auditLogs->log(
        actorUsername: $currentUser['username'],
        action: 'post.comment.added',
        targetType: 'post',
        targetId: $postId,
        details: [
            'comment_length' => strlen($comment),
        ],
        ipAddress: $request->clientIp()
    );

    return Response::redirect('/admin?status=' . rawurlencode('Comment opgeslagen.') . '#moderation');
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
        apiEnabled: $state->apiEnabled(),
        hasApiKey: (new \NnSpotty\Newznab\ApiKeys($pdo))->exists($username),
        newApiKey: is_array($newKey) && $newKey['username'] === $username ? $newKey['key'] : null,
        sabSettings: \NnSpotty\UI\SabSettings::render((new \NnSpotty\Downloader\SabRepository($pdo))->settings('user:' . $username), $csrf->field(), false, $currentUser['is_admin']),
        metadataSettings: \NnSpotty\Metadata\View::settings(new \NnSpotty\Metadata\Repository($pdo), $csrf->field(), $username)
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

$router->post('/admin/newznab', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAdmin()) {
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

$router->get('/spots', static function (Request $request) use ($auth, $csrf, $state, $spots): Response {
    $isBatch = $request->query('batch') === '1';
    if (!$auth->isAuthenticated()) {
        return $isBatch ? Response::privateJson(['error' => 'Login vereist.'], 401) : Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

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
        $batch = $spots->batch($selectedCategory, (int) $preferences['items_per_page'], $showErotica, $filters, $search, $cursor);
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
            'next' => SpotsPageRenderer::nextUrl(['category' => $selectedCategory, 'subcategory' => implode(',', $filters), 'q' => $search], $batch['next']),
        ]);
    }

    $newSince = $auth->newSpotsSince();
    $facets = $selectedCategory === null
        ? []
        : $spots->facets($selectedCategory, $showErotica, $search, $filters, $newSince);
    $html = SpotsPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        spots: $batch['spots'],
        selectedCategory: $selectedCategory,
        csrfField: $csrf->field(),
        facets: $facets,
        filters: $filters,
        search: $search,
        theme: $preferences['theme'],
        showErotica: $showErotica,
        columns: $columns,
        nextCursor: $batch['next']
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->get('/spots/preview', static function (Request $request) use ($auth, $state, $spots): Response {
    if (!$auth->isAuthenticated()) {
        return Response::privateJson(['error' => 'Login vereist.'], 401);
    }
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
    return $spot === null ? Response::privateJson(['error' => 'Spot niet gevonden.'], 404)
        : Response::privateJson(['html' => SpotDetailPageRenderer::summary($spot, true, $content['images_enabled'], $content['show_comments'])]);
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

    $content = $state->spotContentSettings();
    $html = SpotDetailPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        spot: $spot,
        comments: $content['show_comments'] ? $spots->comments($spotId) : [],
        statusMessage: $request->query('status'),
        csrfField: $csrf->field(),
        categories: $spots->listCategoriesWithCounts($state->canViewErotica($currentUser['username'])),
        theme: $state->getPreferences($currentUser['username'])['theme'],
        showErotica: $state->canViewErotica($currentUser['username']),
        showComments: $content['show_comments'],
        imagesEnabled: $content['images_enabled'],
        retrieveComments: $content['retrieve_comments'],
        metadataControls: (new \NnSpotty\Metadata\Repository($pdo))->allowed($currentUser['username'])
            ? \NnSpotty\Metadata\View::controls($spot, $currentUser['is_admin'], $csrf->field()) : '',
        facets: $spots->facets($spot['category'], $state->canViewErotica($currentUser['username']), newSince: $auth->newSpotsSince())
    );

    return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
});

$router->get('/spots/image', static function (Request $request) use ($auth, $spots, $state): Response {
    if (!$auth->isAuthenticated()) {
        return new Response(401, 'Login vereist.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
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

$router->post('/spots/comment', static function (Request $request) use ($auth, $csrf, $spots, $state): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    if (!$state->spotContentSettings()['show_comments']) {
        return new Response(403, 'Reacties zijn door de beheerder uitgeschakeld.');
    }
    $id = (string) ($request->input('spot_id') ?? '');
    if ($spots->getSpot($id, $state->canViewErotica($auth->user()['username'])) === null) {
        return Response::notFound();
    }
    $location = '/spots/view?key=' . rawurlencode($id);
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect($location . '&status=' . rawurlencode('Ongeldige CSRF token.'));
    }
    $body = trim((string) ($request->input('comment') ?? ''));
    if ($body === '' || strlen($body) > 10000 || preg_match('//u', $body) !== 1) {
        return Response::redirect($location . '&status=' . rawurlencode('Een reactie moet geldige tekst bevatten en maximaal 10000 bytes lang zijn.'));
    }
    $user = $auth->user();
    if ($user === null) {
        return Response::redirect('/login');
    }
    $state->addComment($id, $user['username'], $body);
    return Response::redirect($location . '&status=' . rawurlencode('Reactie lokaal geplaatst; niet naar Usenet verstuurd.'));
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

$request = Request::fromGlobals($_SERVER, $_GET, $_POST);
$response = $router->dispatch($request);
$response->send();
