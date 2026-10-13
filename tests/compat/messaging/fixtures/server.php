<?php

/*
 * The `messaging-http` fixture server: a stand-in for every provider API
 * the messaging adapters call (Twilio, Mailgun, SES, FCM, APNs, Meta, ...),
 * so no provider is ever reached. A raw HTTP/1.1 server on Swoole coroutine
 * sockets, so it records exactly what a client sent (header names in order
 * and case, the body as bytes). Started by tests/compat/messaging/services.sh:
 *
 *   php server.php <port> <keys dir>
 *
 * Each runtime's run has its own namespace, the first path segment. The
 * adapters' provider URLs are rewritten by the drivers to
 * /<ns>/<scheme>/<host>/<path>?<query> (the Mock SMS adapter's endpoint is
 * given in that form directly).
 *
 *   POST /_queue/<ns>    append replies (a JSON list) to answer with, in order; a reply is
 *                        {status, headers: {name: value}, body | json, match?, drop?}: `json`
 *                        is sent encoded, `match` (a substring of the request's path and body)
 *                        reserves a reply for the request it matches (concurrent sends), and
 *                        `drop` closes the connection without answering
 *   GET  /_take/<ns>     the requests received since the last take, then forgets them and
 *                        the replies left; ?sort=1 sorts them by URL and body (concurrent sends)
 *   ANY  /<ns>/...       records the request and answers with the next reply (200 `{}`
 *                        once the queue is empty)
 *
 * A recorded request is {method, url, headers: [[name, value]], body}, with what differs
 * between runs normalised, and checked where it can be:
 *   - Host (the fixture's own address), and the HTTP/2 upgrade PHP's cURL adds in cleartext
 *     (Connection, Upgrade, HTTP2-Settings; over TLS HTTP/2 is negotiated by ALPN instead),
 *     are left out;
 *   - a JWT (an `Authorization: Bearer` header, a form's `assertion`) becomes `<jwt>`, and
 *     `jwt` lists each one decoded: header, payload with `iat` as `<now>` and `exp` as
 *     `<iat+N>`, and whether the signature verifies with the fixture key of its algorithm
 *     (keys/<alg>.pub.pem);
 *   - an AWS SigV4 `Authorization` is recomputed with the secret of its access key
 *     (compat-access-key / compat-secret-key) and its signature becomes `<valid>` or
 *     `<invalid>`, its date `<date>`; `X-Amz-Date` becomes `<amz-date>`;
 *   - a multipart boundary becomes `<boundary>`;
 *   - a raw MIME message in a JSON body (SES's Content.Raw.Data) becomes `<mime>`, and `mime`
 *     holds it decoded with its boundaries, Date and Message-ID normalised;
 *   - Telesign's uniqid() external IDs become `<uniqid>`.
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Server;
use Swoole\Coroutine\Server\Connection;

$port = (int) ($argv[1] ?? 8080);
$keys = $argv[2] ?? __DIR__ . '/keys';

/** @var array<string, list<array<string, mixed>>> $queues */
$queues = [];
/** @var array<string, list<array<string, mixed>>> $recorded */
$recorded = [];

const SECRETS = ['compat-access-key' => 'compat-secret-key'];

function readRequest(Connection $conn, string &$buffer): ?array
{
    while (!str_contains($buffer, "\r\n\r\n")) {
        $data = $conn->recv(60);
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
                $data = $conn->recv(60);
                if ($data === '' || $data === false) {
                    return null;
                }
                $buffer .= $data;
            }
            [$size, $rest] = explode("\r\n", $buffer, 2);
            $size = (int) hexdec(explode(';', $size)[0]);
            while (strlen($rest) < $size + 2) {
                $data = $conn->recv(60);
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
            $data = $conn->recv(60);
            if ($data === '' || $data === false) {
                return null;
            }
            $buffer .= $data;
        }
        $body = substr($buffer, 0, $length);
        $buffer = substr($buffer, $length);
    }

    return ['method' => $parts[0], 'target' => $parts[1] ?? '', 'headers' => $headers, 'body' => $body, 'header' => $find];
}

function respond(Connection $conn, int $status, array $headers, string $body): void
{
    $out = "HTTP/1.1 {$status} Fixture\r\n";
    foreach ($headers as $name => $value) {
        $out .= "{$name}: {$value}\r\n";
    }
    $out .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    $conn->send($out);
}

function base64url(string $value): string|false
{
    return base64_decode(strtr($value, '-_', '+/'), true);
}

/** ECDSA r||s as DER, for openssl_verify(). */
function der(string $rs): string
{
    $half = intdiv(strlen($rs), 2);
    $int = static function (string $v): string {
        $v = ltrim($v, "\0");
        if ($v === '' || ord($v[0]) > 0x7f) {
            $v = "\0" . $v;
        }

        return "\x02" . chr(strlen($v)) . $v;
    };
    $seq = $int(substr($rs, 0, $half)) . $int(substr($rs, $half));

    return "\x30" . chr(strlen($seq)) . $seq;
}

/**
 * A JWT decoded: its header, its payload with iat/exp relative, and whether
 * the fixture key of its algorithm verifies it.
 */
function jwtView(string $token, string $keys): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['malformed' => $token];
    }
    $header = json_decode((string) base64url($parts[0]), true);
    $payload = json_decode((string) base64url($parts[1]), true);
    $signature = (string) base64url($parts[2]);
    $alg = is_array($header) ? (string) ($header['alg'] ?? '') : '';
    $file = $keys . '/' . strtolower($alg) . '.pub.pem';
    $verified = false;
    if (is_file($file)) {
        $digest = str_ends_with($alg, '384') ? OPENSSL_ALGO_SHA384 : (str_ends_with($alg, '512') ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256);
        $signed = $parts[0] . '.' . $parts[1];
        $verified = openssl_verify($signed, str_starts_with($alg, 'ES') ? der($signature) : $signature, (string) file_get_contents($file), $digest) === 1;
    }
    if (is_array($payload) && is_int($payload['iat'] ?? null) && abs($payload['iat'] - time()) <= 600) {
        if (is_int($payload['exp'] ?? null)) {
            $payload['exp'] = '<iat+' . ($payload['exp'] - $payload['iat']) . '>';
        }
        $payload['iat'] = '<now>';
    }

    return ['header' => $header, 'payload' => $payload, 'verified' => $verified];
}

/** MIME text with random boundaries renamed in order, Date and Message-ID masked. */
function normalizeMime(string $mime): string
{
    $names = [];
    $offset = 0;
    while (($at = strpos($mime, '=_', $offset)) !== false) {
        $candidate = substr($mime, $at, 34);
        if (strlen($candidate) === 34 && strspn($candidate, '0123456789abcdef', 2) === 32) {
            $names[$candidate] ??= '=_BOUNDARY' . (count($names) + 1);
            $offset = $at + 34;
        } else {
            $offset = $at + 1;
        }
    }
    $mime = strtr($mime, $names);
    $mime = (string) preg_replace('/^Date: [^\r\n]*/m', 'Date: <date>', $mime);

    return (string) preg_replace('/^Message-ID: <[0-9a-f]{32}@/m', 'Message-ID: <<id>@', $mime);
}

/** Verifies an AWS SigV4 Authorization header and masks what varies. */
function sigv4(string $authorization, string $method, string $path, string $host, array $request): string
{
    if (preg_match('/^AWS4-HMAC-SHA256 Credential=([^\/]*)\/(\d{8})\/([^\/]*)\/([^\/]*)\/aws4_request, SignedHeaders=([^,]*), Signature=([0-9a-f]{64})$/', $authorization, $m) !== 1) {
        return $authorization;
    }
    [, $key, $date, $region, $service, $signedList, $signature] = $m;
    $canonical = '';
    foreach (explode(';', $signedList) as $name) {
        $value = $name === 'host' ? $host : (string) ($request['header'])($name);
        $canonical .= $name . ':' . trim($value) . "\n";
    }
    $amzDate = (string) ($request['header'])('X-Amz-Date');
    $canonicalRequest = implode("\n", [$method, $path, '', $canonical, $signedList, hash('sha256', $request['body'])]);
    $scope = "{$date}/{$region}/{$service}/aws4_request";
    $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);
    $secret = SECRETS[$key] ?? '';
    $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    $valid = hash_equals(hash_hmac('sha256', $toSign, $k), $signature) && str_starts_with($amzDate, $date);

    return "AWS4-HMAC-SHA256 Credential={$key}/<date>/{$region}/{$service}/aws4_request, SignedHeaders={$signedList}, Signature=" . ($valid ? '<valid>' : '<invalid>');
}

/**
 * @return array<string, mixed>
 */
function record(array $request, string $scheme, string $host, string $path, string $query, string $keys): array
{
    $tokens = [];
    $headers = [];
    $boundary = null;
    $type = (string) ($request['header'])('Content-Type');
    if (preg_match('/boundary=([^;\s]+)/', $type, $m) === 1) {
        $boundary = $m[1];
    }
    foreach ($request['headers'] as [$name, $value]) {
        $lower = strtolower($name);
        if (in_array($lower, ['host', 'connection', 'upgrade', 'http2-settings'], true)) {
            continue;
        }
        if ($lower === 'authorization' && preg_match('/^Bearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*)$/', $value, $m) === 1) {
            $tokens[] = jwtView($m[1], $keys);
            $value = 'Bearer <jwt>';
        } elseif ($lower === 'authorization' && str_starts_with($value, 'AWS4-HMAC-SHA256 ')) {
            $value = sigv4($value, $request['method'], $path, $host, $request);
        } elseif ($lower === 'x-amz-date') {
            $value = '<amz-date>';
        }
        if ($boundary !== null) {
            $value = str_replace($boundary, '<boundary>', $value);
        }
        $headers[] = [$name, $value];
    }
    $body = $request['body'];
    $entry = [];
    if ($boundary !== null) {
        $body = str_replace($boundary, '<boundary>', $body);
    }
    if (preg_match('/(?:^|&)assertion=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*)/', $body, $m) === 1) {
        $tokens[] = jwtView($m[1], $keys);
        $body = str_replace($m[1], '<jwt>', $body);
    }
    if (preg_match('/"Data":"([A-Za-z0-9+\\\\\/=]+)"/', $body, $m) === 1) {
        $entry['mime'] = normalizeMime((string) base64_decode(str_replace('\/', '/', $m[1])));
        $body = str_replace($m[1], '<mime>', $body);
    }
    if (str_ends_with($path, '/bulk_sms')) {
        $body = (string) preg_replace('/%3A[0-9a-f]{13}/', '%3A<uniqid>', $body);
    }

    return [
        'method' => $request['method'],
        'url' => $scheme . '://' . $host . $path . ($query === '' ? '' : '?' . $query),
        'headers' => $headers,
        'body' => preg_match('//u', $body) === 1 ? $body : ['$bytes' => base64_encode($body)],
    ] + ($tokens === [] ? [] : ['jwt' => $tokens]) + $entry;
}

/**
 * Answers one request; returns false when the connection must close.
 */
function route(Connection $conn, array $request, array &$queues, array &$recorded, string $keys): bool
{
    [$path, $query] = array_pad(explode('?', $request['target'], 2), 2, '');
    $segments = explode('/', ltrim($path, '/'));

    if ($segments[0] === '_queue') {
        $ns = $segments[1] ?? '';
        foreach ((array) json_decode($request['body'], true) as $reply) {
            $queues[$ns][] = $reply;
        }
        respond($conn, 200, ['Content-Type' => 'application/json'], 'true');

        return true;
    }

    if ($segments[0] === '_take') {
        $ns = $segments[1] ?? '';
        $taken = $recorded[$ns] ?? [];
        parse_str($query, $options);
        if (($options['sort'] ?? '') === '1') {
            usort($taken, static fn (array $a, array $b): int => [$a['url'], json_encode($a['body'])] <=> [$b['url'], json_encode($b['body'])]);
        }
        unset($recorded[$ns], $queues[$ns]);
        respond($conn, 200, ['Content-Type' => 'application/json'], (string) json_encode($taken, JSON_UNESCAPED_SLASHES));

        return true;
    }

    if ($segments[0] === '_ready') {
        respond($conn, 200, [], 'ready');

        return true;
    }

    // /<ns>/<scheme>/<host>/<path...>
    $ns = (string) array_shift($segments);
    $scheme = (string) array_shift($segments);
    $host = (string) array_shift($segments);
    $original = '/' . implode('/', $segments);
    $recorded[$ns][] = record($request, $scheme, $host, $original, $query, $keys);

    $reply = null;
    $haystack = $original . '?' . $query . "\n" . $request['body'];
    foreach ($queues[$ns] ?? [] as $i => $candidate) {
        if (isset($candidate['match']) && str_contains($haystack, (string) $candidate['match'])) {
            $reply = $candidate;
            unset($queues[$ns][$i]);
            break;
        }
    }
    if ($reply === null) {
        foreach ($queues[$ns] ?? [] as $i => $candidate) {
            if (!isset($candidate['match'])) {
                $reply = $candidate;
                unset($queues[$ns][$i]);
                break;
            }
        }
    }
    $reply ??= ['status' => 200, 'json' => new stdClass()];
    if (!empty($reply['drop'])) {
        return false;
    }
    $body = array_key_exists('json', $reply) ? (string) json_encode($reply['json'], JSON_UNESCAPED_SLASHES) : (string) ($reply['body'] ?? '');
    $headers = (array) ($reply['headers'] ?? []);
    if (array_key_exists('json', $reply) && !isset($headers['Content-Type'])) {
        $headers['Content-Type'] = 'application/json';
    }
    respond($conn, (int) ($reply['status'] ?? 200), $headers, $body);

    return true;
}

Coroutine\run(function () use ($port, $keys, &$queues, &$recorded) {
    $server = new Server('0.0.0.0', $port, false, true);
    $server->handle(function (Connection $conn) use ($keys, &$queues, &$recorded) {
        $buffer = '';
        while (true) {
            $request = readRequest($conn, $buffer);
            if ($request === null) {
                break;
            }
            if (!route($conn, $request, $queues, $recorded, $keys)) {
                break;
            }
            if (strcasecmp((string) ($request['header'])('Connection'), 'close') === 0) {
                break;
            }
        }
        $conn->close();
    });
    $server->start();
});
