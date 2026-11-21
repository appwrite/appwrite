<?php

/*
 * The `client-http` fixture server the client cases call: a raw HTTP/1.1
 * server on Swoole coroutine sockets, so it can echo exactly what a client
 * sent (request line, headers in order and case, body) and answer with
 * exactly the bytes a case asks for, malformed ones included. Started by
 * tests/compat/client/services.sh in a throwaway container:
 *
 *   php server.php <http port> <https port> <certificate dir>
 *
 * Routes (path, then query):
 *   /echo (and any unknown path)   200 JSON {method, target, protocol, headers: [[name, value]], body | body64};
 *                                  ?status=N answers with that status, ?h[]=Name:value adds response headers,
 *                                  ?digest=1 gives the body's {length, sha256} instead of the body
 *   /raw?r=<base64>                writes those bytes verbatim, then closes the connection
 *   /redirect?to=<loc>&status=N    a redirect (302 by default) to `to`
 *   /hops/N                        a chain of N redirects ending in "hopped"
 *   /encoded?enc=gzip|deflate|br|zstd&repeat=N&type=binary
 *                                  a payload compressed with `enc` when the client offered it
 *   /slow?ms=N                     waits, then echoes
 *   /large?bytes=N                 N bytes of "abcd..." with a Content-Length
 *   /chunked?chunks=N&size=M       N chunks of M bytes, Transfer-Encoding: chunked
 *   /flaky?key=K&fail=N&status=S&retry_after=R&mode=drop
 *                                  the first N requests for K answer S (or drop the connection), then 200 "ok"
 *   /conn?key=K                    "connection <n>": which connection (per key, from 1) carried the request
 *   /drop-idle?key=K               answers with keep-alive, then closes the connection anyway
 *   /drop-second?key=K             answers the first request on a connection; reads the next and closes unanswered
 *   /count?key=K                   how many requests /flaky or /drop-second saw for K
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

$httpPort = (int) ($argv[1] ?? 8080);
$httpsPort = (int) ($argv[2] ?? 8443);
$certs = $argv[3] ?? '/tmp/compat-fs/client-certs';

/** @var array<string, int> $counters per-key request counters */
$counters = [];
/** @var array<string, int> $connections per-key connection counters */
$connections = [];

function readRequest(Connection $conn, string &$buffer): ?array
{
    while (!str_contains($buffer, "\r\n\r\n")) {
        $data = $conn->recv(30);
        if ($data === '' || $data === false) {
            return null;
        }
        $buffer .= $data;
    }
    [$head, $buffer] = explode("\r\n\r\n", $buffer, 2);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    $parts = explode(' ', $requestLine, 3);
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[] = [$name, trim($value)];
    }
    $find = static function (string $wanted) use ($headers): ?string {
        foreach ($headers as [$name, $value]) {
            if (strcasecmp($name, $wanted) === 0) {
                return $value;
            }
        }

        return null;
    };
    if (strcasecmp((string) $find('Expect'), '100-continue') === 0) {
        $conn->send("HTTP/1.1 100 Continue\r\n\r\n");
    }
    $body = '';
    $length = $find('Content-Length');
    if (strcasecmp((string) $find('Transfer-Encoding'), 'chunked') === 0) {
        while (true) {
            while (!str_contains($buffer, "\r\n")) {
                $data = $conn->recv(30);
                if ($data === '' || $data === false) {
                    return null;
                }
                $buffer .= $data;
            }
            [$size, $rest] = explode("\r\n", $buffer, 2);
            $size = hexdec(explode(';', $size)[0]);
            while (strlen($rest) < $size + 2) {
                $data = $conn->recv(30);
                if ($data === '' || $data === false) {
                    return null;
                }
                $rest .= $data;
            }
            if ($size === 0) {
                // Trailers are not used by any client here: drop up to the blank line.
                $buffer = substr($rest, 2);
                break;
            }
            $body .= substr($rest, 0, $size);
            $buffer = substr($rest, $size + 2);
        }
    } elseif ($length !== null) {
        $length = (int) $length;
        while (strlen($buffer) < $length) {
            $data = $conn->recv(30);
            if ($data === '' || $data === false) {
                return null;
            }
            $buffer .= $data;
        }
        $body = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);
    }

    return [
        'method' => $parts[0],
        'target' => $parts[1] ?? '',
        'protocol' => $parts[2] ?? '',
        'headers' => $headers,
        'body' => $body,
        'header' => $find,
    ];
}

/**
 * @param list<array{0: string, 1: string}> $headers
 */
function respond(Connection $conn, int $status, array $headers, string $body, bool $head, bool $keepAlive = true): void
{
    $out = "HTTP/1.1 {$status} " . reason($status) . "\r\n";
    $hasLength = false;
    foreach ($headers as [$name, $value]) {
        $out .= "{$name}: {$value}\r\n";
        $hasLength = $hasLength || strcasecmp($name, 'Content-Length') === 0 || strcasecmp($name, 'Transfer-Encoding') === 0;
    }
    if (!$hasLength && $status !== 204 && $status !== 304) {
        $out .= 'Content-Length: ' . strlen($body) . "\r\n";
    }
    if (!$keepAlive) {
        $out .= "Connection: close\r\n";
    }
    $out .= "\r\n";
    if (!$head && $status !== 204 && $status !== 304) {
        $out .= $body;
    }
    $conn->send($out);
}

function reason(int $status): string
{
    return [
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        418 => "I'm a teapot", 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ][$status] ?? 'Unknown';
}

function compress(string $encoding, string $payload): string
{
    return match ($encoding) {
        'gzip' => gzencode($payload),
        'deflate' => gzcompress($payload),
        'br' => brotli_compress($payload),
        'zstd' => zstd_compress($payload),
        default => $payload,
    };
}

/**
 * Answers one request; returns false when the connection must close.
 */
function route(Connection $conn, array $request, int $connection, array &$counters, array &$connectionsSeen): bool
{
    $target = $request['target'];
    $path = (string) parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $head = $request['method'] === 'HEAD';
    $text = [['Content-Type', 'text/plain;charset=UTF-8']];

    if ($path === '/raw') {
        $conn->send((string) base64_decode((string) ($query['r'] ?? ''), true));

        return false;
    }

    if ($path === '/redirect') {
        respond($conn, (int) ($query['status'] ?? 302), [['Location', (string) ($query['to'] ?? '')], ...$text], 'redirect', $head);

        return true;
    }

    if (str_starts_with($path, '/hops/')) {
        $remaining = (int) substr($path, 6);
        if ($remaining <= 0) {
            respond($conn, 200, $text, 'hopped', $head);
        } else {
            respond($conn, 302, [['Location', '/hops/' . ($remaining - 1)], ...$text], 'redirect', $head);
        }

        return true;
    }

    if ($path === '/encoded') {
        $accept = (string) ($request['header'])('Accept-Encoding');
        $repeat = max(1, (int) ($query['repeat'] ?? 64));
        $binary = ($query['type'] ?? '') === 'binary';
        $payload = $binary ? str_repeat(implode('', array_map('chr', range(0, 255))), $repeat) : str_repeat('utopia ', $repeat);
        $encoding = (string) ($query['enc'] ?? 'gzip');
        $headers = [['Content-Type', $binary ? 'application/octet-stream' : 'text/plain;charset=UTF-8'], ['X-Accept-Encoding', $accept]];
        if ($encoding !== 'identity' && str_contains($accept, $encoding)) {
            $headers[] = ['Content-Encoding', $encoding];
            $payload = compress($encoding, $payload);
        }
        respond($conn, 200, $headers, $payload, $head);

        return true;
    }

    if ($path === '/slow') {
        Coroutine::sleep(((int) ($query['ms'] ?? 1000)) / 1000);
    }

    if ($path === '/large') {
        $bytes = (int) ($query['bytes'] ?? 262144);
        respond($conn, 200, [['Content-Type', 'application/octet-stream']], substr(str_repeat('abcd', intdiv($bytes, 4) + 1), 0, $bytes), $head);

        return true;
    }

    if ($path === '/chunked') {
        $chunks = (int) ($query['chunks'] ?? 5);
        $size = (int) ($query['size'] ?? 8);
        $conn->send("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nTransfer-Encoding: chunked\r\n\r\n");
        if (!$head) {
            for ($i = 0; $i < $chunks; $i++) {
                $chunk = substr(str_repeat("chunk{$i}-", $size), 0, $size);
                $conn->send(dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                Coroutine::sleep(0.005);
            }
            $conn->send("0\r\n\r\n");
        }

        return true;
    }

    if ($path === '/flaky') {
        $key = (string) ($query['key'] ?? '');
        $count = $counters[$key] = ($counters[$key] ?? 0) + 1;
        if ($count <= (int) ($query['fail'] ?? 1)) {
            if (($query['mode'] ?? '') === 'drop') {
                return false;
            }
            $headers = $text;
            if (isset($query['retry_after'])) {
                $headers[] = ['Retry-After', (string) $query['retry_after']];
            }
            respond($conn, (int) ($query['status'] ?? 503), $headers, "failure {$count}", $head);

            return true;
        }
        respond($conn, 200, $text, "ok after {$count}", $head);

        return true;
    }

    if ($path === '/count') {
        respond($conn, 200, $text, (string) ($counters[(string) ($query['key'] ?? '')] ?? 0), $head);

        return true;
    }

    if ($path === '/drop-second') {
        $key = (string) ($query['key'] ?? '');
        $counters[$key] = ($counters[$key] ?? 0) + 1;
        $id = 'requests#' . $connection;
        $counters[$id] = ($counters[$id] ?? 0) + 1;
        if ($counters[$id] >= 2) {
            return false;
        }
        respond($conn, 200, $text, 'answered', $head);

        return true;
    }

    if ($path === '/conn' || $path === '/drop-idle') {
        $key = (string) ($query['key'] ?? '');
        $id = $key . '#' . $connection;
        if (!isset($connectionsSeen[$id])) {
            $connectionsSeen[$id] = \count(array_filter(array_keys($connectionsSeen), static fn (string $seen) => str_starts_with($seen, $key . '#'))) + 1;
        }
        respond($conn, 200, $text, 'connection ' . $connectionsSeen[$id], $head);

        return $path === '/conn';
    }

    $headers = [['Content-Type', 'application/json']];
    foreach ((array) ($query['h'] ?? []) as $header) {
        [$name, $value] = array_pad(explode(':', (string) $header, 2), 2, '');
        $headers[] = [$name, ltrim($value)];
    }
    $echo = [
        'method' => $request['method'],
        'target' => $request['target'],
        'protocol' => $request['protocol'],
        'headers' => $request['headers'],
    ];
    if (isset($query['digest'])) {
        $echo['length'] = \strlen($request['body']);
        $echo['sha256'] = hash('sha256', $request['body']);
    } elseif (mb_check_encoding($request['body'], 'UTF-8')) {
        $echo['body'] = $request['body'];
    } else {
        $echo['body64'] = base64_encode($request['body']);
    }
    // Chunked, so no Content-Length tells the runtimes apart: the echo quotes each one's own Host.
    $json = (string) json_encode($echo, JSON_UNESCAPED_SLASHES);
    $headers[] = ['Transfer-Encoding', 'chunked'];
    respond($conn, (int) ($query['status'] ?? 200), $headers, dechex(\strlen($json)) . "\r\n" . $json . "\r\n0\r\n\r\n", $head);

    return true;
}

Coroutine\run(function () use ($httpPort, $httpsPort, $certs, &$counters, &$connections) {
    $next = 0;
    $serve = function (Server $server) use (&$counters, &$connections, &$next) {
        $server->handle(function (Connection $conn) use (&$counters, &$connections, &$next) {
            $connection = ++$next;
            $buffer = '';
            while (true) {
                $request = readRequest($conn, $buffer);
                if ($request === null) {
                    break;
                }
                $keep = route($conn, $request, $connection, $counters, $connections);
                if (!$keep || strcasecmp((string) ($request['header'])('Connection'), 'close') === 0) {
                    break;
                }
            }
            $conn->close();
        });
        $server->start();
    };

    $http = new Server('0.0.0.0', $httpPort, false, true);
    Coroutine::create(fn () => $serve($http));

    if (is_file("{$certs}/server.pem")) {
        $https = new Server('0.0.0.0', $httpsPort, true, true);
        $https->set([
            'ssl_cert_file' => "{$certs}/server.pem",
            'ssl_key_file' => "{$certs}/server.key",
        ]);
        Coroutine::create(fn () => $serve($https));
    }
});
