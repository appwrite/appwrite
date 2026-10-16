<?php

// The backend cannot finish until the real downstream sink acknowledges bytes.
if (!is_string($_SERVER['REQUEST_URI'] ?? null)) {
    throw new RuntimeException('Missing request URI.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/octet-stream');
if ($path === '/missing') {
    http_response_code(404);
    echo '<Error><Code>NoSuchKey</Code></Error>';
    return;
}
if ($path === '/ignores-range') {
    echo 'abcdefgh';
    return;
}
header('Content-Length: 8388608');
echo str_repeat('x', 65536);
flush();
$marker = getenv('S3_STREAM_MARKER');
if ($marker === false) {
    throw new RuntimeException('Missing acknowledgement path.');
}
$deadline = microtime(true) + 2;
while (!file_exists($marker) && microtime(true) < $deadline) {
    clearstatcache(true, $marker);
    usleep(1000);
}
if (!file_exists($marker)) {
    exit; // A buffered read never lets the downstream sink run in time.
}
for ($i = 1; $i < 128; ++$i) {
    echo str_repeat('x', 65536);
    flush();
}
