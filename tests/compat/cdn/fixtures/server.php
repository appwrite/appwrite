<?php

/*
 * The `cdn-http` fixture server: a stand-in for the Cloudflare and Fastly
 * APIs that the providers reach through their default HTTP client, so no CDN
 * is ever called. Run by PHP's built-in server in a throwaway container
 * (tests/compat/cdn/services.sh).
 *
 * Every run has its own namespace, the first path segment:
 *   POST /_queue/<ns>   append the JSON list of responses ({status, headers, body}) to answer with
 *   GET  /_take/<ns>    the requests received since the last take, as JSON, and forget them
 *   ANY  /<ns>/<path>   record the request (method, path and query without the
 *                       namespace, the headers the providers set, the body) and
 *                       answer with the next queued response (500 without a body
 *                       once the queue is empty)
 */

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$state = sys_get_temp_dir() . '/compat-cdn-http';
@mkdir($state, 0777, true);

$file = static fn (string $ns, string $kind): string => $state . '/' . bin2hex($ns) . '.' . $kind;
$read = static function (string $path): array {
    $contents = @file_get_contents($path);

    return $contents === false || $contents === '' ? [] : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
};

header('Content-Type: application/json');

if (str_starts_with($uri, '/_queue/')) {
    $ns = substr($uri, 8);
    $queued = $read($file($ns, 'queue'));
    foreach (json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR) as $response) {
        $queued[] = $response;
    }
    file_put_contents($file($ns, 'queue'), json_encode($queued));
    echo 'true';

    return true;
}

if (str_starts_with($uri, '/_take/')) {
    $ns = substr($uri, 7);
    echo json_encode($read($file($ns, 'requests')));
    @unlink($file($ns, 'requests'));
    @unlink($file($ns, 'queue'));

    return true;
}

[$ns, $rest] = explode('/', substr($uri, 1), 2) + [1 => ''];

$headers = [];
foreach (getallheaders() as $name => $value) {
    $name = strtolower($name);
    if (in_array($name, ['accept', 'authorization', 'content-type', 'fastly-key', 'fastly-soft-purge', 'user-agent'], true)) {
        $headers[$name] = $value;
    }
}
ksort($headers);

$requests = $read($file($ns, 'requests'));
$requests[] = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'url' => '/' . $rest,
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
];
file_put_contents($file($ns, 'requests'), json_encode($requests));

$queued = $read($file($ns, 'queue'));
$next = array_shift($queued) ?? ['status' => 500];
file_put_contents($file($ns, 'queue'), json_encode($queued));

header_remove('Content-Type');
http_response_code((int) ($next['status'] ?? 200));
foreach ($next['headers'] ?? [] as $name => $value) {
    header($name . ': ' . $value);
}
echo array_key_exists('json', $next) ? json_encode($next['json']) : ($next['body'] ?? '');

return true;
