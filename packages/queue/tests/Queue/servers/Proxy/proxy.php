<?php

/**
 * Plain TCP proxy for tests that need a server to vanish and come back.
 *
 *   php proxy.php <listen-port> <target-host> <target-port> [<control-file>]
 *
 * Every accepted client gets its own upstream socket; bytes are forwarded
 * both ways until either side closes. Killing this process closes every
 * client socket at once and leaves the port refusing connections, which is
 * what a broker failover looks like from a worker.
 *
 * While the control file exists, the next chunk a client sends is forwarded
 * upstream and then both sockets are closed before any reply comes back: the
 * server ran the command, the client never learns whether it did. The file
 * is removed once used.
 */

declare(strict_types=1);

[, $listenPort, $targetHost, $targetPort, $controlFile] = $_SERVER['argv'] + [null, null, null, null, null];

$server = stream_socket_server("tcp://127.0.0.1:{$listenPort}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "proxy: listen failed: {$errstr}\n");
    exit(1);
}

/** @var array<int, resource> $peers socket id => the socket on the other side */
$peers = [];

/** @var array<int, true> $clients socket ids of accepted clients */
$clients = [];

while (true) {
    $read = [$server, ...array_map(fn(int $id) => $peers[$id], array_keys($peers))];
    $write = null;
    $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server, 0);
            $upstream = $client ? @stream_socket_client("tcp://{$targetHost}:{$targetPort}", $errno, $errstr, 1) : false;

            if ($client === false || $upstream === false) {
                if ($client) {
                    fclose($client);
                }
                continue;
            }

            $peers[(int) $client] = $upstream;
            $peers[(int) $upstream] = $client;
            $clients[(int) $client] = true;
            continue;
        }

        $other = $peers[(int) $socket] ?? null;
        $data = fread($socket, 65536);

        if ($data === false || $data === '' || $other === null) {
            unset($peers[(int) $socket], $clients[(int) $socket]);
            if ($other !== null) {
                unset($peers[(int) $other]);
                fclose($other);
            }
            fclose($socket);
            continue;
        }

        fwrite($other, $data);
        // Client -> upstream chunk delivered; if asked to, now lose the reply.
        if ($controlFile === null) {
            continue;
        }
        if (!isset($clients[(int) $socket])) {
            continue;
        }

        clearstatcache(true, $controlFile);
        if (!is_file($controlFile)) {
            continue;
        }

        unlink($controlFile);
        fflush($other);
        usleep(50_000);
        unset($peers[(int) $socket], $peers[(int) $other], $clients[(int) $socket]);
        fclose($other);
        fclose($socket);
    }
}
