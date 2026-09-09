<?php

declare(strict_types=1);

// Local HTTP fixture: capture real cURL requests and return the test's responses.
if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'ready';
    return;
}

$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'path' => $path,
    'query' => $_GET,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
];
file_put_contents(getenv('CATALOG_TEST_REQUESTS'), json_encode($request) . "\n", FILE_APPEND | LOCK_EX);
$routes = json_decode(file_get_contents(getenv('CATALOG_TEST_ROUTES')), true);
$reply = $routes[$request['method'] . ' ' . $path] ?? [
    'status' => 500,
    'body' => ['code' => 500, 'message' => 'unexpected test route'],
];
http_response_code($reply['status'] ?? 200);
header('Content-Type: application/json');
echo array_key_exists('raw', $reply) ? $reply['raw'] : json_encode($reply['body']);
