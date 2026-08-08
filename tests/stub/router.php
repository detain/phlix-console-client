<?php

// Stub HTTP server for CI smoke test
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/api/v1/libraries' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"libraries":[]}';
    return;
}
http_response_code(404);
echo 'Not Found';
