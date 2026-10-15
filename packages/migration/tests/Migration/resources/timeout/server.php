<?php

require \dirname(__DIR__, 4).'/vendor/autoload.php';

use Utopia\Tests\Unit\General\TargetTimeoutServerBehaviour as Behaviour;

const LIFETIME_SECONDS = 120;

$behaviour = Behaviour::from($argv[1] ?? '');
$server = \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
    \fwrite(STDERR, $errorMessage);
    exit(1);
}

$address = (string) \stream_socket_get_name($server, false);
\fwrite(STDOUT, \substr($address, \strrpos($address, ':') + 1).PHP_EOL);
\fflush(STDOUT);

$connection = \stream_socket_accept($server, LIFETIME_SECONDS);

if ($connection === false) {
    exit(1);
}

if ($behaviour === Behaviour::Silent) {
    \sleep(LIFETIME_SECONDS);
    exit(0);
}

$request = '';
while (!\str_contains($request, "\r\n\r\n") && !\feof($connection)) {
    $request .= (string) \fread($connection, 8192);
}

\fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: ".Behaviour::STREAMED_BYTES."\r\nConnection: close\r\n\r\n");

if ($behaviour === Behaviour::Stalled) {
    \fwrite($connection, 'x');
    \sleep(LIFETIME_SECONDS);
    exit(0);
}

for ($i = 0; $i < Behaviour::STREAMED_BYTES; $i++) {
    \usleep(Behaviour::STREAM_INTERVAL_MICROSECONDS);
    \fwrite($connection, 'x');
}

\fclose($connection);
