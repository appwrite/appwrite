<?php

declare(strict_types=1);

/*
 * The server end of one connection, run as its own process so a client that
 * blocks on a TLS or WebSocket handshake has someone to complete it. Prints the
 * port it listens on, then "ready" once the handshake is done, then "ok" after
 * each command read from stdin: "send <base64 bytes>", "close", "linger" (set
 * SO_LINGER 0, so the process ending resets the connection).
 */

$options = getopt('', ['certificates:', 'websocket']);
$certificates = isset($options['certificates']) && \is_string($options['certificates']) ? $options['certificates'] : null;

$context = $certificates === null ? [] : ['ssl' => [
    'local_cert' => "{$certificates}/server-cert.pem",
    'local_pk' => "{$certificates}/server-key.pem",
]];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($context));
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
$address = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($address, (int) strrpos($address, ':') + 1) . "\n");

$peer = stream_socket_accept($server, 10.0);
if ($peer === false) {
    exit(1);
}

$socket = \function_exists('socket_import_stream') ? socket_import_stream($peer) : false;
stream_set_read_buffer($peer, 8192);

if ($certificates !== null && stream_socket_enable_crypto($peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
    exit(1);
}

if (isset($options['websocket'])) {
    $key = '';
    while (($line = fgets($peer)) !== false && trim($line) !== '') {
        [$name, $value] = explode(':', $line, 2) + [1 => ''];
        if (strtolower(trim($name)) === 'sec-websocket-key') {
            $key = trim($value);
        }
    }
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite($peer, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
}

fwrite(STDOUT, "ready\n");

while (($line = fgets(STDIN)) !== false) {
    [$command, $argument] = explode(' ', trim($line), 2) + [1 => ''];
    switch ($command) {
        case 'send':
            fwrite($peer, (string) base64_decode($argument, true));
            break;
        case 'close':
            fclose($peer);
            break;
        case 'linger':
            if ($socket === false) {
                exit(1);
            }
            socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            break;
    }
    fwrite(STDOUT, "ok\n");
}
