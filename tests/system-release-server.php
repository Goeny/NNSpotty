<?php

declare(strict_types=1);

header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/api/v4/projects') {
    echo '[{"id":42,"path_with_namespace":"project/app"}]';
    exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/v4/projects/42/releases') {
    http_response_code(404);
    echo '{"error":"Unknown release fixture endpoint"}';
    exit;
}
$path = getenv('NNSPOTTY_RELEASE_FIXTURE');
if ($path === false || !is_file($path)) {
    http_response_code(500);
    echo '{"error":"Missing fixture"}';
    exit;
}
echo file_get_contents($path);
