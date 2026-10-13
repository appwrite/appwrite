<?php

/*
 * The `agents-http` fixture server the agents cases call instead of a real AI
 * provider: a raw HTTP/1.1 server on Swoole coroutine sockets that records the
 * exact requests it receives (request line, headers in order and case, body)
 * and answers each with the next response a case queued, streamed chunk by
 * chunk when asked. Started by tests/compat/agents/services.sh in a throwaway
 * container:
 *
 *   php server.php <port>
 *
 * Routes:
 *   POST /queue/<key>     queues responses for <key>: a JSON list of
 *                         {status, headers?: [[name, value]], body?, chunks?, delay?}
 *                         (body and each chunk a string or {"$bytes": base64});
 *                         chunks are sent as separate HTTP chunks, `delay`
 *                         milliseconds apart (default 40)
 *   * /serve/<key>[/...]  records the request under <key> and answers with the
 *                         next queued response (500 "no response queued" when none)
 *   GET /requests/<key>   the requests recorded under <key>, oldest first, as JSON
 *                         {method, target, protocol, headers: [[name, value]], body}
 *                         (body {"$bytes": base64} when not UTF-8), then forgets them
 *   GET /health           "ok"
 */

// Its own namespace: the client fixture server defines global functions of
// the same names (readRequest, respond) with other signatures.
namespace Tests\Compat\Agents\Fixtures;

use Swoole\Coroutine;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

$port = (int) ($argv[1] ?? 8080);

/** @var array<string, list<array<string, mixed>>> $queues */
$queues = [];
/** @var array<string, list<array<string, mixed>>> $logs */
$logs = [];

/**
 * @return array<string, mixed>|null
 */
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
    $parts = explode(' ', (string) array_shift($lines), 3);
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
                $buffer = substr($rest, 2);
                break;
            }
            $body .= substr($rest, 0, $size);
            $buffer = substr($rest, $size + 2);
        }
    } elseif (($length = $find('Content-Length')) !== null) {
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
        'close' => strcasecmp((string) $find('Connection'), 'close') === 0,
    ];
}

function decodeBytes(mixed $value): string
{
    if (\is_array($value) && isset($value['$bytes'])) {
        return (string) base64_decode((string) $value['$bytes'], true);
    }

    return (string) $value;
}

function encodeBytes(string $value): mixed
{
    return mb_check_encoding($value, 'UTF-8') ? $value : ['$bytes' => base64_encode($value)];
}

/**
 * @param array<int, array{0: string, 1: string}> $headers
 */
function head(int $status, array $headers): string
{
    $out = "HTTP/1.1 {$status} " . match ($status) {
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => 'Status',
    } . "\r\n";
    foreach ($headers as [$name, $value]) {
        $out .= "{$name}: {$value}\r\n";
    }

    return $out . "\r\n";
}

/**
 * @param array<string, mixed> $response
 */
function respond(Connection $conn, array $response): void
{
    $status = (int) ($response['status'] ?? 200);
    $headers = $response['headers'] ?? [];
    if (isset($response['chunks'])) {
        $has = static fn (string $name): bool => array_any($headers, fn ($h) => strcasecmp($h[0], $name) === 0);
        if (!$has('Content-Type')) {
            $headers[] = ['Content-Type', 'text/event-stream'];
        }
        $headers[] = ['Transfer-Encoding', 'chunked'];
        $conn->send(head($status, $headers));
        $delay = (int) ($response['delay'] ?? 40);
        foreach ($response['chunks'] as $chunk) {
            $chunk = decodeBytes($chunk);
            if ($chunk === '') {
                continue;
            }
            Coroutine::sleep($delay / 1000);
            $conn->send(dechex(\strlen($chunk)) . "\r\n" . $chunk . "\r\n");
        }
        Coroutine::sleep($delay / 1000);
        $conn->send("0\r\n\r\n");

        return;
    }
    $body = decodeBytes($response['body'] ?? '');
    $has = static fn (string $name): bool => array_any($headers, fn ($h) => strcasecmp($h[0], $name) === 0);
    if (!$has('Content-Type')) {
        $headers[] = ['Content-Type', 'application/json'];
    }
    $headers[] = ['Content-Length', (string) \strlen($body)];
    $conn->send(head($status, $headers) . $body);
}

Coroutine\run(function () use ($port, &$queues, &$logs): void {
    $server = new Server('0.0.0.0', $port, false, true);
    $server->handle(function (Connection $conn) use (&$queues, &$logs): void {
        $buffer = '';
        while (($request = readRequest($conn, $buffer)) !== null) {
            $path = explode('?', $request['target'], 2)[0];
            $segments = explode('/', ltrim($path, '/'));
            $route = $segments[0];
            $key = $segments[1] ?? '';
            if ($route === 'queue' && $request['method'] === 'POST') {
                foreach ((array) json_decode($request['body'], true) as $response) {
                    $queues[$key][] = $response;
                }
                respond($conn, ['status' => 200, 'body' => 'ok', 'headers' => [['Content-Type', 'text/plain']]]);
            } elseif ($route === 'requests') {
                $recorded = $logs[$key] ?? [];
                unset($logs[$key]);
                respond($conn, ['status' => 200, 'body' => json_encode($recorded, JSON_UNESCAPED_SLASHES)]);
            } elseif ($route === 'serve') {
                $logs[$key][] = [
                    'method' => $request['method'],
                    'target' => $request['target'],
                    'protocol' => $request['protocol'],
                    'headers' => $request['headers'],
                    'body' => encodeBytes($request['body']),
                ];
                $response = array_shift($queues[$key]);
                respond($conn, $response ?? ['status' => 500, 'body' => 'no response queued', 'headers' => [['Content-Type', 'text/plain']]]);
            } else {
                respond($conn, ['status' => 200, 'body' => 'ok', 'headers' => [['Content-Type', 'text/plain']]]);
            }
            if ($request['close']) {
                break;
            }
        }
        $conn->close();
    });
    $server->start();
});
