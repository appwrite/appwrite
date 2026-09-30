<?php

/**
 * Router for PHP's built-in server, standing in for the endpoints a webhook posts to.
 *
 * Every request is appended to the JSON-lines file named by WEBHOOK_RECEIVER_LOG. Each
 * path is one endpoint, and its first segment picks the answer: /ok/<name> accepts,
 * /fail/<name> answers 500 every time, /flaky/<name> answers 500 to its first request
 * and accepts every one after.
 */

$log = \getenv('WEBHOOK_RECEIVER_LOG');
$path = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$seen = 0;
foreach (\file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if ((\json_decode($line, true)['path'] ?? null) === $path) {
        $seen++;
    }
}

\file_put_contents($log, \json_encode([
    'path' => $path,
    'headers' => \getallheaders(),
    'body' => \file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$status = match (\explode('/', $path)[1] ?? '') {
    'ok' => 200,
    'flaky' => $seen === 0 ? 500 : 200,
    default => 500,
};

\http_response_code($status);
\header('Content-Type: application/json');
echo \json_encode(['status' => $status]);
