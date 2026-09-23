<?php

declare(strict_types=1);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : '/';
$path = is_string($path) ? $path : '/';

if ($path === '/not-found') {
    http_response_code(404);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'missing';

    return;
}

if ($path === '/server-error') {
    http_response_code(500);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'failed';

    return;
}

if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/final') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'final';

    return;
}

if ($path === '/redirect-large') {
    http_response_code(302);
    header('Location: /stream-large');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/redirect-stream-preserve') {
    http_response_code(307);
    header('Location: /stream');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/nested/parent') {
    http_response_code(302);
    header('Location: ../final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/nested/dot') {
    http_response_code(302);
    header('Location: ./final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/nested/plain') {
    http_response_code(302);
    header('Location: final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/nested/final') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'nested-final';

    return;
}

if ($path === '/redirect-absolute') {
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $host = is_string($host) ? $host : '127.0.0.1';
    http_response_code(302);
    header('Location: http://' . $host . '/final');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/redirect-auth') {
    http_response_code(302);
    header('Location: /echo-auth');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/redirect-cross') {
    $port = $_SERVER['SERVER_PORT'] ?? '';
    $port = is_numeric($port) ? (int) $port : 0;
    http_response_code(302);
    header('Location: http://localhost:' . $port . '/echo-auth');
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/echo-auth') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = '';
    $cookie = '';

    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        if (strcasecmp($name, 'Authorization') === 0) {
            $authorization = $value;
        }

        if (strcasecmp($name, 'Cookie') === 0) {
            $cookie = $value;
        }
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $authorization;
    $authorization = is_string($authorization) ? $authorization : '';
    $cookie = is_string($_SERVER['HTTP_COOKIE'] ?? null) ? $_SERVER['HTTP_COOKIE'] : $cookie;

    echo $authorization . '|' . $cookie;

    return;
}

if (preg_match('#^/hops/(\d+)$#', $path, $matches) === 1) {
    $remaining = (int) $matches[1];

    if ($remaining === 0) {
        http_response_code(200);
        header('Content-Type: text/plain;charset=UTF-8');
        echo 'hopped';

        return;
    }

    http_response_code(302);
    header('Location: /hops/' . ($remaining - 1));
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'redirect';

    return;
}

if ($path === '/headers') {
    http_response_code(204);
    header('X-Trace: one', false);
    header('X-Trace: two', false);
    header('x-Mixed-Case: Value');

    return;
}

if ($path === '/binary') {
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    echo "\x00\x01hello\xff";

    return;
}

if ($path === '/request-headers') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $host = is_string($host) ? $host : '';
    $trace = $_SERVER['HTTP_X_TRACE'] ?? '';
    $trace = is_string($trace) ? $trace : '';

    echo $host . ':' . $trace;

    return;
}

if ($path === '/request-target') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo is_string($requestUri) ? $requestUri : '';

    return;
}

if ($path === '/space%20name') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo is_string($requestUri) ? $requestUri : '';

    return;
}

if ($path === '/' && is_string($requestUri) && str_contains($requestUri, 'ping=1')) {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo $requestUri;

    return;
}

if ($path === '/method') {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
    $method = is_string($requestMethod) ? $requestMethod : '';

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    header('X-Request-Method: ' . $method);

    echo $method;

    return;
}

if ($path === '/body-info') {
    $body = file_get_contents('php://input');
    $body = $body === false ? '' : $body;

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo strlen($body) . ':' . hash('sha256', $body);

    return;
}

if ($path === '/multipart') {
    $name = $_POST['name'] ?? '';
    $name = is_string($name) ? $name : '';

    $file = $_FILES['file'] ?? null;
    $size = is_array($file) && isset($file['size']) && is_int($file['size']) ? $file['size'] : 0;
    $tmp = is_array($file) && isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
    $hash = $tmp !== '' && is_file($tmp) ? hash_file('sha256', $tmp) : '';

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo $name . ':' . $size . ':' . $hash;

    return;
}

if ($path === '/selected-headers') {
    $comma = $_SERVER['HTTP_X_COMMA'] ?? '';
    $comma = is_string($comma) ? $comma : '';
    $zero = $_SERVER['HTTP_X_ZERO'] ?? '';
    $zero = is_string($zero) ? $zero : '';
    $mixed = $_SERVER['HTTP_X_MIXED_REQUEST'] ?? '';
    $mixed = is_string($mixed) ? $mixed : '';

    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo $comma . ':' . $zero . ':' . $mixed;

    return;
}

if ($path === '/large-response') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    echo str_repeat('abcd', 65_536);

    return;
}

if ($path === '/stream') {
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    for ($i = 0; $i < 5; $i++) {
        echo 'chunk' . $i . "\n";
        flush();
        usleep(20_000);
    }

    return;
}

if ($path === '/stream-large') {
    $chunkSize = 65_536;
    $chunkCount = 128;

    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . ($chunkSize * $chunkCount));

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $method = is_string($requestMethod) ? $requestMethod : 'GET';

    if (strtoupper($method) === 'HEAD') {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $chunk = str_repeat('a', $chunkSize);

    for ($i = 0; $i < $chunkCount; $i++) {
        echo $chunk;
        flush();
    }

    return;
}

if ($path === '/slow') {
    sleep(1);
    http_response_code(200);
    header('Content-Type: text/plain;charset=UTF-8');
    echo 'slow';

    return;
}

if ($path === '/gzip') {
    $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $accept = is_string($accept) ? $accept : '';

    // ?repeat sizes the payload, ?type=binary returns all 256 byte values,
    // ?compress=0 forces plaintext even when gzip was offered.
    $repeat = isset($_GET['repeat']) && is_numeric($_GET['repeat']) ? max(1, (int) $_GET['repeat']) : 64;
    $binary = ($_GET['type'] ?? '') === 'binary';
    $compressible = ($_GET['compress'] ?? '1') !== '0';

    if ($binary) {
        $unit = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $unit .= chr($byte);
        }

        $payload = str_repeat($unit, $repeat);
    } else {
        $payload = str_repeat('utopia ', $repeat);
    }

    http_response_code(200);
    header('Content-Type: ' . ($binary ? 'application/octet-stream' : 'text/plain;charset=UTF-8'));
    // Echo what the client advertised so a test can assert negotiation.
    header('X-Accept-Encoding: ' . $accept);

    if ($compressible && str_contains($accept, 'gzip')) {
        header('Content-Encoding: gzip');
        echo gzencode($payload);

        return;
    }

    header('Content-Length: ' . strlen($payload));
    echo $payload;

    return;
}

http_response_code(202);
header('Content-Type: text/plain;charset=UTF-8');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$method = is_string($requestMethod) ? $requestMethod : '';
$customHeader = $_SERVER['HTTP_X_CUSTOM'] ?? '';
$customHeader = is_string($customHeader) ? $customHeader : '';
$body = file_get_contents('php://input');

echo $method . ':' . $path . ':' . $customHeader . ':' . ($body === false ? '' : $body);
