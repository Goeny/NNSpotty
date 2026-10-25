<?php

declare(strict_types=1);

header('Content-Type: application/json');
$path = getenv('NNSPOTTY_RELEASE_FIXTURE');
if ($path === false || !is_file($path)) {
    http_response_code(500);
    echo '{"error":"Missing fixture"}';
    exit;
}
echo file_get_contents($path);
