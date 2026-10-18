<?php

declare(strict_types=1);

require __DIR__ . '/Fixtures.php';
header('Content-Type: application/json');
$mode = $_POST['mode'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('NNSPOTTY_SAB_LOG');
$file = $_FILES['nzbfile']['tmp_name'] ?? null;
$entry = ['path' => $path, 'mode' => $mode, 'cat' => $_POST['cat'] ?? null,
    'title' => $_POST['nzbname'] ?? null, 'valid_nzb' => $file !== null && file_get_contents($file) === Fixtures::nzb(),
    'query' => $_SERVER['QUERY_STRING'] ?? ''];
file_put_contents($log, json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
if ($path === '/redirect/api') {
    header('Location: http://127.0.0.1:1/private', true, 302);
    exit;
}
if (($_POST['apikey'] ?? '') !== str_repeat('a', 32)) {
    echo '{"status":false,"error":"API key incorrect"}';
    exit;
}
if ($path === '/huge/api') {
    echo str_repeat('a', 2 * 1024 * 1024 + 1);
    exit;
}
if ($mode === 'addfile') {
    if ($path === '/reject/api') {
        echo '{"status":false,"error":"rejected"}';
    } elseif ($path === '/unknown/api') {
        echo 'not-json-after-acceptance';
    } else {
        echo '{"status":true,"nzo_ids":["SABnzbd_nzo_fixture"]}';
    }
} elseif ($mode === 'get_cats') {
    echo '{"categories":["*","movies","tv","music","books","xxx"]}';
} elseif ($mode === 'queue') {
    if ($path === '/invalid/api') {
        echo '{"queue":{"status":"Downloading"}}';
    } else {
        echo json_encode(['queue' => ['status' => 'Downloading', 'kbpersec' => '1024.50', 'noofslots_total' => 1,
            'secret_not_to_forward' => 'server-local-path', 'slots' => [
                ['filename' => '<script>not-code</script> SAB release', 'status' => 'Downloading', 'percentage' => '42.3',
                    'cat' => 'tv', 'timeleft' => '0:02:34', 'password' => 'secret-not-to-forward'],
            ]]], JSON_THROW_ON_ERROR);
    }
} else {
    echo '{"status":false,"error":"unknown mode"}';
}
