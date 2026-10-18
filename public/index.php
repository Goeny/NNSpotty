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
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Users\UserRepository;
use NnSpotty\UI\AdminPageRenderer;
use NnSpotty\UI\LoginPageRenderer;
use NnSpotty\UI\PreferencesPageRenderer;
use NnSpotty\UI\SpotDetailPageRenderer;
use NnSpotty\UI\SpotsPageRenderer;

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
require_once __DIR__ . '/../src/UI/HomePageRenderer.php';
require_once __DIR__ . '/../src/UI/AdminPageRenderer.php';
require_once __DIR__ . '/../src/UI/LoginPageRenderer.php';
require_once __DIR__ . '/../src/UI/PreferencesPageRenderer.php';
require_once __DIR__ . '/../src/UI/SpotDetailPageRenderer.php';
require_once __DIR__ . '/../src/UI/SpotsPageRenderer.php';

loadEnvFile(__DIR__ . '/../.env');
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

$router->get('/', static function (Request $_request) use ($config, $auth, $csrf): Response {
    $html = NnSpotty\UI\HomePageRenderer::render($config, $auth->user(), $csrf->field());

    return Response::html($html);
});

$router->get('/health', static fn (Request $_request): Response => Response::json([
    'status' => 'ok',
    'service' => 'nnspotty',
    'version' => $config->version,
    'codename' => $config->codename,
]));

$router->get('/login', static function (Request $request) use ($auth, $csrf): Response {
    if ($auth->isAuthenticated()) {
        return Response::redirect('/');
    }

    $html = LoginPageRenderer::render($request->query('status'), $csrf->field());

    return Response::html($html);
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

    return Response::redirect('/');
});

$router->post('/logout', static function (Request $request) use ($auth, $csrf): Response {
    if (!$csrf->verify($request->input('_csrf'))) {
        return Response::redirect('/login?status=' . rawurlencode('Ongeldige CSRF token.'));
    }

    $auth->logout();

    return Response::redirect('/login?status=' . rawurlencode('Uitgelogd.'));
});

$router->get('/admin', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
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
        comments: $state->getComments(),
        auditLogs: $auditLogs->recent(50),
        statusMessage: $request->query('status'),
        currentUsername: $currentUser['username'],
        csrfField: $csrf->field()
    );

    return Response::html($html);
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

    if ($server === '' || $port < 1 || $port > 65535 || $retention < 0 || !in_array($encryption, ['none', 'ssl', 'tls'], true) || $discoverMaxGroups < 0 || $maxArticlesPerGroup < 100) {
        return Response::redirect('/admin?status=' . rawurlencode('Ongeldige usenet instellingen.'));
    }

    $state->saveUsenetSettings([
        'retention' => $retention,
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

    return Response::redirect('/admin?status=' . rawurlencode('Spotter toegevoegd aan ' . $listType . '.'));
});

$router->post('/admin/comments', static function (Request $request) use ($state, $auth, $csrf, $auditLogs): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
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

    return Response::redirect('/admin?status=' . rawurlencode('Comment opgeslagen.'));
});

$router->get('/preferences', static function (Request $request) use ($state, $auth, $csrf): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    $username = $currentUser['username'];

    $html = PreferencesPageRenderer::render(
        username: $username,
        preferences: $state->getPreferences($username),
        statusMessage: $request->query('status'),
        isAdmin: $currentUser['is_admin'],
        csrfField: $csrf->field()
    );

    return Response::html($html);
});

$router->get('/spots', static function (Request $request) use ($auth, $csrf, $state, $spots): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $currentUser = $auth->user();
    if ($currentUser === null) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }

    $selectedCategoryRaw = trim((string) ($request->query('category') ?? ''));
    $selectedCategory = $selectedCategoryRaw !== '' ? $selectedCategoryRaw : null;
    $pageRaw = (string) ($request->query('page') ?? '1');
    $page = preg_match('/^\d+$/', $pageRaw) === 1 ? max(1, (int) $pageRaw) : 1;
    $preferences = $state->getPreferences($currentUser['username']);
    $perPage = max(10, min(200, (int) $preferences['items_per_page']));
    $totalItems = $spots->countSpots($selectedCategory);
    $totalPages = max(1, (int) ceil($totalItems / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $html = SpotsPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        categories: $spots->listCategoriesWithCounts(),
        spots: $spots->listSpots($selectedCategory, $perPage, $offset),
        selectedCategory: $selectedCategory,
        currentPage: $page,
        totalPages: $totalPages,
        totalItems: $totalItems,
        csrfField: $csrf->field()
    );

    return Response::html($html);
});

$router->get('/spots/view', static function (Request $request) use ($auth, $csrf, $spots): Response {
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

    $spot = $spots->getSpot($spotId);
    if ($spot === null) {
        return Response::redirect('/spots');
    }

    $html = SpotDetailPageRenderer::render(
        username: $currentUser['username'],
        isAdmin: $auth->isAdmin(),
        spot: $spot,
        comments: $spots->comments($spotId),
        statusMessage: $request->query('status'),
        csrfField: $csrf->field()
    );

    return Response::html($html);
});

$router->get('/spots/image', static function (Request $request) use ($auth, $spots, $state): Response {
    if (!$auth->isAuthenticated()) {
        return new Response(401, 'Login vereist.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
    $id = (string) ($request->query('key') ?? '');
    $spot = $spots->getSpot($id);
    if ($spot === null || $spot['image_segments'] === []) {
        return Response::notFound();
    }
    try {
        $image = (new SpotImageService($spots, $state))->load($spot);
        return new Response(200, $image['image_data'], [
            'Content-Type' => $image['mime_type'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    } catch (Throwable $exception) {
        error_log('Spot image ' . $id . ': ' . $exception->getMessage());
        return new Response(502, 'Afbeelding ophalen mislukt. Controleer de serverlog.', ['Content-Type' => 'text/plain; charset=utf-8']);
    }
});

$router->post('/spots/comment', static function (Request $request) use ($auth, $csrf, $spots, $state): Response {
    if (!$auth->isAuthenticated()) {
        return Response::redirect('/login?status=' . rawurlencode('Login vereist.'));
    }
    $id = (string) ($request->input('spot_id') ?? '');
    if ($spots->getSpot($id) === null) {
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

    if (!in_array($theme, ['default', 'plex-dark', 'plex-light'], true) || $itemsPerPage < 10 || $itemsPerPage > 200) {
        return Response::redirect('/preferences?status=' . rawurlencode('Ongeldige preference invoer.'));
    }

    $state->savePreferences($username, [
        'theme' => $theme,
        'items_per_page' => $itemsPerPage,
    ]);
    $auditLogs->log(
        actorUsername: $username,
        action: 'user.preferences.updated',
        targetType: 'user',
        targetId: $username,
        details: [
            'theme' => $theme,
            'items_per_page' => $itemsPerPage,
        ],
        ipAddress: $request->clientIp()
    );

    return Response::redirect('/preferences?status=' . rawurlencode('Preferences opgeslagen.'));
});

$request = Request::fromGlobals($_SERVER, $_GET, $_POST);
$response = $router->dispatch($request);
$response->send();
