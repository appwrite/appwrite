<?php

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$host = $_SERVER['HTTP_HOST'] ?? '';
$path = \parse_url($uri, PHP_URL_PATH) ?: '/';

$log = \getenv('FIXTURE_LOG');
if ($log !== false && $log !== '') {
    \file_put_contents($log, $method.' '.$uri.' '.$host."\n", FILE_APPEND | LOCK_EX);
}

if ($path === '/echo') {
    \header('Content-Type: application/json; charset=utf-8');
    echo \json_encode(['method' => $method, 'host' => $host, 'uri' => $uri]);

    return;
}

if ($path === '/redirect') {
    \header('Location: '.($_GET['to'] ?? '/echo'), true, 302);

    return;
}

\http_response_code(404);
