<?php

declare(strict_types=1);

(static function () use ($pdo, $dsn, $database): void {
$current = \NnSpotty\Config\Release::VERSION;
$parts = explode('.', $current);
$parts[2] = (string) ((int) $parts[2] + 1);
$next = implode('.', $parts);
$tag = 'v' . $next;
$systemTemp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nnspotty-updater-http-' . bin2hex(random_bytes(8));
mkdir($systemTemp);
$systemProcess = new \NnSpotty\System\Process();
$systemGit = static fn (array $args, string $cwd): string => $systemProcess->require(['git', ...$args], $cwd);
$systemChildren = [];
$systemLogs = [];
$systemUserRepo = new \NnSpotty\Users\UserRepository($pdo);
$systemWrite = static function (string $path, string $content): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    if (file_put_contents($path, $content) === false) throw new RuntimeException('Cannot write release HTTP fixture');
};
$systemPort = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException($error, $errno);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
};
function systemHttp(int $port, string $path, string &$cookie, ?array $form = null): array
{
    $context = stream_context_create(['http' => ['method' => $form === null ? 'GET' : 'POST',
        'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => $form === null ? '' : http_build_query($form), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120]]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) $cookie = $match[1];
    return ['status' => (int) explode(' ', $http_response_header[0])[1], 'body' => $body, 'headers' => $http_response_header];
}
try {
    $apiPort = $systemPort();
    $webPort = $systemPort();
    $fixtureJson = $systemTemp . '/releases.json';
    $releasePayload = json_encode([['tag_name' => $tag, 'name' => 'Fixture Release', 'released_at' => '2020-01-01T00:00:00Z']], JSON_THROW_ON_ERROR);
    $systemWrite($fixtureJson, $releasePayload);
    $root = $systemTemp . '/app';
    mkdir($root);
    foreach (['src', 'public', 'bin', 'vendor'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen(dirname(__DIR__) . '/' . $folder) + 1);
                $systemWrite($root . '/' . $folder . '/' . $relative, file_get_contents($entry->getPathname()));
            }
        }
    }
    foreach (['composer.json', 'composer.lock', '.gitignore'] as $file) copy(dirname(__DIR__) . '/' . $file, $root . '/' . $file);
    mkdir($root . '/var');
    $systemWrite($root . '/composer.phar', <<<'PHP'
<?php
if (in_array('--version', $argv, true)) { echo 'Composer fixture'; exit; }
file_put_contents(__DIR__ . '/var/composer-ran', json_encode($argv));
echo 'Composer fixture installed';
PHP);
    $systemGit(['init', '-b', 'main'], $root);
    $systemGit(['config', 'user.name', 'Release fixture'], $root);
    $systemGit(['config', 'user.email', 'fixture@example.invalid'], $root);
    $systemGit(['add', '.'], $root);
    $systemGit(['commit', '-m', 'Baseline updater fixture'], $root);
    $baseline = $systemGit(['rev-parse', 'HEAD'], $root);
    $releaseCode = file_get_contents($root . '/src/Config/Release.php');
    $systemWrite($root . '/src/Config/Release.php', str_replace(["VERSION = '$current'", "CODENAME = '" . \NnSpotty\Config\Release::CODENAME . "'"],
        ["VERSION = '$next'", "CODENAME = 'Fixture GT'"], $releaseCode));
    $migratorCode = file_get_contents($root . '/src/Database/Migrator.php');
    $at = strrpos($migratorCode, '        ];');
    if ($at === false) throw new RuntimeException('Cannot inject fixture migration');
    $systemWrite($root . '/src/Database/Migrator.php', substr_replace($migratorCode,
        "            '20261025_0026_release_fixture' => ['CREATE TABLE release_update_fixture (id INT PRIMARY KEY)'],\n", $at, 0));
    $systemGit(['add', '.'], $root);
    $systemGit(['commit', '-m', 'Published fixture release with new schema'], $root);
    $systemGit(['tag', $tag], $root);
    $releaseCommit = $systemGit(['rev-parse', 'HEAD'], $root);
    $source = $systemTemp . '/source.git';
    $systemGit(['clone', '--bare', $root, $source], $systemTemp);
    $systemGit(['checkout', '--detach', $baseline], $root);
    $remote = 'http://127.0.0.1:' . $apiPort . '/project/app.git';
    $systemGit(['remote', 'add', 'origin', $remote], $root);
    $systemGit(['config', 'url.' . str_replace('\\', '/', $source) . '.insteadOf', $remote], $root);
    $dbConfig = [];
    foreach (explode(';', substr($dsn, 6)) as $parameter) {
        $parts = explode('=', $parameter, 2);
        if (count($parts) === 2) $dbConfig[$parts[0]] = $parts[1];
    }
    $environment = array_merge(getenv(), [
        'DB_HOST' => $dbConfig['host'] ?? '127.0.0.1', 'DB_PORT' => $dbConfig['port'] ?? '3306',
        'DB_DATABASE' => $database, 'DB_USERNAME' => getenv('NNSPOTTY_TEST_USER') ?: 'root',
        'DB_PASSWORD' => getenv('NNSPOTTY_TEST_PASSWORD') ?: '', 'DB_CHARSET' => 'utf8mb4',
        'APP_URL' => 'http://127.0.0.1:' . $webPort, 'NNSPOTTY_RELEASE_FIXTURE' => $fixtureJson,
    ]);
    if (PHP_OS_FAMILY === 'Windows' && $environment['DB_PASSWORD'] === '') throw new RuntimeException('HTTP tests need a nonempty DB password on Windows');
    foreach ([[$apiPort, [dirname(__DIR__) . '/tests/system-release-server.php']], [$webPort, ['-t', $root . '/public']]] as [$port, $args]) {
        $log = tmpfile();
        if ($log === false) throw new RuntimeException('Cannot open HTTP log');
        $systemLogs[] = $log;
        $child = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, ...$args], [['pipe', 'r'], $log, $log], $pipes, $root, $environment);
        if (!is_resource($child)) throw new RuntimeException('Cannot start release HTTP server');
        fclose($pipes[0]);
        $systemChildren[] = $child;
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, .1);
            if ($probe !== false) { fclose($probe); $ready = true; break; }
            usleep(100000);
        }
        check($ready, 'Release HTTP fixture is bereikbaar: ' . $port);
    }
    $systemUserRepo->create('system-http-admin', 'synthetic-release-password', true);
    $systemUserRepo->create('system-http-reader', 'synthetic-release-password', false);
    $cookies = ['admin' => '', 'reader' => '', 'anonymous' => ''];
    $tokens = [];
    foreach (['admin', 'reader'] as $role) {
        $login = systemHttp($webPort, '/login', $cookies[$role]);
        preg_match('/name="_csrf" value="([^"]+)"/', $login['body'], $token);
        $tokens[$role] = $token[1];
        check(systemHttp($webPort, '/login', $cookies[$role], ['_csrf' => $token[1],
            'username' => 'system-http-' . $role, 'password' => 'synthetic-release-password'])['status'] === 302,
            'Release GUI fixture login: ' . $role);
    }
    check(systemHttp($webPort, '/system/update', $cookies['anonymous'])['status'] === 302
        && systemHttp($webPort, '/system/update', $cookies['reader'])['status'] === 403
        && systemHttp($webPort, '/system/update/status', $cookies['reader'])['status'] === 403,
        'Updaterpagina en status vereisen login en apart recht');
    $page = systemHttp($webPort, '/system/update', $cookies['admin']);
    check($page['status'] === 200 && str_contains($page['body'], 'Fixture Release')
        && str_contains($page['body'], 'data-system-update') && in_array('Cache-Control: private, no-store', $page['headers'], true),
        'GUI vindt echte gepubliceerde API-release en toont beveiligd installatieformulier');
    $status = json_decode(systemHttp($webPort, '/system/update/status', $cookies['admin'])['body'], true, 32, JSON_THROW_ON_ERROR);
    check($status['available'] && $status['release']['version'] === $next, 'Sidebarstatus adverteert nieuwe release');
    $systemWrite($fixtureJson, 'invalid-json');
    systemHttp($webPort, '/system/update', $cookies['admin'], ['_csrf' => $tokens['admin'], 'action' => 'check']);
    $failedStatus = json_decode(systemHttp($webPort, '/system/update/status', $cookies['admin'])['body'], true, 32, JSON_THROW_ON_ERROR);
    check($failedStatus['error'] !== null && !$failedStatus['available']
        && !str_contains(systemHttp($webPort, '/system/update', $cookies['admin'])['body'], 'data-system-update'),
        'Echte release-API-fout blijft zichtbaar en biedt geen branchfallback/installatieknop');
    $systemWrite($fixtureJson, $releasePayload);
    systemHttp($webPort, '/system/update', $cookies['admin'], ['_csrf' => $tokens['admin'], 'action' => 'check']);
    check(json_decode(systemHttp($webPort, '/system/update/status', $cookies['admin'])['body'], true)['available'],
        'Handmatig opnieuw controleren herstelt foutcache na providerherstel');
    check(systemHttp($webPort, '/system/update', $cookies['admin'], ['action' => 'update', 'tag' => $tag, 'backup' => '1'])['status'] === 403,
        'GUI-update weigert ontbrekende CSRF');
    check(systemHttp($webPort, '/system/update', $cookies['admin'], ['_csrf' => $tokens['admin'], 'action' => 'update', 'tag' => $tag])['status'] === 400,
        'GUI-update vereist expliciete backupbevestiging');
    $systemUserRepo->saveGroup(null, 'Release HTTP updater', ['system.update']);
    $group = (int) $pdo->query("SELECT id FROM user_groups WHERE name = 'Release HTTP updater'")->fetchColumn();
    $systemUserRepo->manage('system-http-reader', true, [$group]);
    check(systemHttp($webPort, '/system/update', $cookies['reader'])['status'] === 200
        && str_contains(systemHttp($webPort, '/history', $cookies['reader'])['body'], 'data-update-notice'),
        'Apart groepsrecht geeft niet-admin direct updater en sidebarlink');
    $systemWrite($root . '/var/update-maintenance.json', json_encode(['tag' => $tag, 'commit' => $releaseCommit], JSON_THROW_ON_ERROR));
    check(systemHttp($webPort, '/spots', $cookies['reader'])['status'] === 503
        && systemHttp($webPort, '/api?t=caps', $cookies['anonymous'])['status'] === 503
        && systemHttp($webPort, '/system/update', $cookies['reader'])['status'] === 200,
        'Onderhoud blokkeert normale UI/API maar laat bevoegde herstelpagina bereikbaar');
    check(in_array('Location: /system/update', systemHttp($webPort, '/login', $cookies['reader'])['headers'], true),
        'Bevoegde gebruiker komt tijdens onderhoud via login direct op herstelpagina');
    $cli = proc_open([PHP_BINARY, $root . '/bin/console', 'retrieve', '--since-last'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $cliPipes, $root, $environment);
    if (!is_resource($cli)) throw new RuntimeException('Cannot start maintenance CLI test');
    fclose($cliPipes[0]);
    $cliOutput = stream_get_contents($cliPipes[1]) . stream_get_contents($cliPipes[2]);
    fclose($cliPipes[1]);
    fclose($cliPipes[2]);
    check(proc_close($cli) === 1 && str_contains($cliOutput, 'Onderhoudsmodus actief'),
        'CLI-retrieve start niet tijdens releaseonderhoud');
    unlink($root . '/var/update-maintenance.json');
    $lockName = 'nns:' . substr(hash('sha256', $database), 0, 60);
    $pdo->prepare('SELECT GET_LOCK(?, 0)')->execute([$lockName]);
    try {
        $busy = systemHttp($webPort, '/system/update', $cookies['reader'], ['_csrf' => $tokens['reader'],
            'action' => 'update', 'tag' => $tag, 'backup' => '1']);
        check($busy['status'] === 500 && $systemGit(['rev-parse', 'HEAD'], $root) === $baseline,
            'Actieve retrieve blokkeert GUI-update voordat code of schema veranderen');
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
    $response = systemHttp($webPort, '/system/update', $cookies['reader'], ['_csrf' => $tokens['reader'],
        'action' => 'update', 'tag' => $tag, 'backup' => '1']);
    check($response['status'] === 200 && str_contains($response['body'], 'Update afgerond'),
        'GUI voert echte CLI-releaseupdate uit');
    check($systemGit(['rev-parse', 'HEAD'], $root) === $releaseCommit && is_file($root . '/var/composer-ran')
        && !is_file($root . '/var/update-maintenance.json'), 'GUI installeert releasetag, Composer en verlaat onderhoudsmodus');
    check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'release_update_fixture'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE name = '20261025_0026_release_fixture'")->fetchColumn() === 1,
        'Werkelijke nieuwe databasemigratie uit release draait in vers PHP-proces');
    $newStatus = json_decode(systemHttp($webPort, '/system/update/status', $cookies['reader'])['body'], true, 32, JSON_THROW_ON_ERROR);
    check($newStatus['current'] === $next && $newStatus['codename'] === 'Fixture GT' && !$newStatus['available'],
        'Volgende request toont werkelijk geinstalleerde versie/codenaam zonder valse updatemelding');
    check((int) $pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE actor_username = 'system-http-reader' AND action = 'system.update.completed'")->fetchColumn() === 1,
        'GUI-update wordt met actor en release geaudit zonder credentials');
    $systemUserRepo->manage('system-http-reader', true, [(int) $pdo->query("SELECT id FROM user_groups WHERE system_key = 'users'")->fetchColumn()]);
    check(systemHttp($webPort, '/system/update', $cookies['reader'])['status'] === 403,
        'Intrekken updaterrecht werkt direct in bestaande sessie');
    $systemUserRepo->deleteGroup($group);
} finally {
    foreach ($systemChildren as $child) { proc_terminate($child); proc_close($child); }
    foreach ($systemLogs as $log) fclose($log);
    $pdo->exec("DELETE FROM app_users WHERE username IN ('system-http-admin', 'system-http-reader')");
    $pdo->exec('DROP TABLE IF EXISTS release_update_fixture');
    $pdo->exec("DELETE FROM migrations WHERE name = '20261025_0026_release_fixture'");
    $resolved = realpath($systemTemp);
    if ($resolved !== false && str_starts_with(basename($resolved), 'nnspotty-updater-http-')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else { if (PHP_OS_FAMILY === 'Windows') chmod($entry->getPathname(), 0666); unlink($entry->getPathname()); }
        }
        rmdir($resolved);
    }
}
})();
