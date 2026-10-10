<?php

/*
 * The `domains-http` fixture server for `api.call` (Utopia\Domains\Adapter::call(),
 * which sends with curl and cannot be given a transport). Run by PHP's
 * built-in server in a throwaway container:
 *
 *   docker run -d --rm --network appwrite --name compat-domains-http -p 9590:8080 \
 *     -v "$PWD/tests:/usr/src/code/tests" --entrypoint php appwrite-dev \
 *     -S 0.0.0.0:8080 /usr/src/code/tests/compat/domains/fixtures/server.php
 *
 * It echoes the request (method, path, query, the headers the client sets,
 * the body or the multipart fields), as JSON or text. The path picks the answer:
 *   /status/<code>/...   answer with that status
 *   .../json             Content-Type: application/json; charset=utf-8
 *   .../bare-json        Content-Type: application/json (no parameters)
 *   .../text             Content-Type: text/plain
 *   .../redirect         302 to /json
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_ends_with($path, '/redirect')) {
    header('Location: /json?redirected=1', true, 302);

    return true;
}

$status = 200;
if (str_starts_with($path, '/status/')) {
    $status = (int) explode('/', $path)[2];
}

$headers = [];
foreach (['HTTP_AUTHORIZATION', 'HTTP_ACCEPT', 'CONTENT_TYPE', 'HTTP_X_CUSTOM'] as $key) {
    if (isset($_SERVER[$key])) {
        $value = (string) $_SERVER[$key];
        // A multipart boundary is random.
        $headers[strtolower(str_replace(['HTTP_', '_'], ['', '-'], $key))] = explode('; boundary=', $value)[0];
    }
}

$echo = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'query' => $_SERVER['QUERY_STRING'] ?? '',
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
    'fields' => $_POST,
];

http_response_code($status);
if (str_ends_with($path, '/text')) {
    header('Content-Type: text/plain');
    echo 'echo ' . json_encode($echo);
} elseif (str_ends_with($path, '/bare-json')) {
    header('Content-Type: application/json');
    echo json_encode($echo);
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($echo);
}

return true;
