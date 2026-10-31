<?php

declare(strict_types=1);

require __DIR__ . '/Fixtures.php';
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request = json_decode((string) file_get_contents('php://input'), true) ?: [];
$method = $request['method'] ?? '';
$params = $request['params'] ?? [];
$headers = array_change_key_case(getallheaders());
$entry = ['path' => $path, 'method' => $method, 'http' => $_SERVER['REQUEST_METHOD'], 'query' => $_SERVER['QUERY_STRING'] ?? '',
    'auth' => ($headers['authorization'] ?? '') === 'Basic ' . base64_encode('fixture-user:fixture-pass')];
if ($method === 'append') {
    $entry += ['name' => $params[0] ?? null, 'valid_nzb' => base64_decode((string) ($params[1] ?? ''), true) === Fixtures::nzb(),
        'category' => $params[2] ?? null, 'params' => count($params)];
}
file_put_contents(getenv('NNSPOTTY_NZBGET_LOG'), json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
$result = static function (mixed $value): never {
    echo json_encode(['version' => '1.1', 'result' => $value], JSON_THROW_ON_ERROR);
    exit;
};
if ($path === '/redirect/jsonrpc') {
    header('Location: http://127.0.0.1:1/private', true, 302);
    exit;
}
if (!$entry['auth']) {
    http_response_code(401);
    echo 'Access denied: secret-not-to-forward';
    exit;
}
if ($path === '/old/jsonrpc' && $method === 'version') {
    $result('15.0');
}
if ($method === 'version') {
    $result('24.3');
}
if ($method === 'config') {
    if ($path === '/restricted/jsonrpc') {
        echo json_encode(['version' => '1.1', 'error' => ['name' => 'JsonRpcError', 'code' => 1, 'message' => 'Access denied secret-not-to-forward']]);
        exit;
    }
    $result([['Name' => 'ControlPassword', 'Value' => 'secret-config-value'], ['Name' => 'Category1.Name', 'Value' => 'movies'],
        ['Name' => 'Category1.DestDir', 'Value' => 'server-local-path'], ['Name' => 'Category2.Name', 'Value' => 'tv'], ['Name' => 'Category3.Name', 'Value' => '']]);
}
if ($method === 'status') {
    $result(['DownloadRate' => 2097152, 'DownloadPaused' => false, 'ServerStandBy' => false, 'ControlPassword' => 'secret-not-to-forward']);
}
if ($method === 'listgroups') {
    if ($path === '/invalid/jsonrpc') {
        $result([['NZBName' => 'incomplete']]);
    }
    $result([
        ['NZBID' => 41, 'NZBName' => '<script>not-code</script> NZBGet release', 'Status' => 'DOWNLOADING', 'Category' => 'tv',
            'FileSizeMB' => 100, 'RemainingSizeMB' => 60, 'DestDir' => 'server-local-path'],
        ['NZBID' => 42, 'NZBName' => 'Second release', 'Status' => 'QUEUED', 'Category' => '', 'FileSizeMB' => 50, 'RemainingSizeMB' => 50],
    ]);
}
if ($method === 'append') {
    if ($path === '/reject/jsonrpc') {
        $result(0);
    }
    if ($path === '/unknown/jsonrpc') {
        echo 'not-json-after-acceptance';
        exit;
    }
    $result(4242);
}
echo json_encode(['version' => '1.1', 'error' => ['name' => 'JsonRpcError', 'code' => 1, 'message' => 'Invalid procedure']]);
