<?php

/*
 * One SMTP session, answered from a script: the greeting, then one reply per
 * command, in order. A reply is sent exactly as given, so a multi-line reply is
 * written with its own CRLFs. After a 354 the message data is read up to the
 * terminating dot, and the next reply is the answer to the data.
 *
 * Prints the port it listens on, then every command it reads, one JSON string a
 * line. When the script runs out it hangs up, which is how a server that has
 * nothing more to say looks to the client.
 */

$replies = json_decode(base64_decode($argv[1] ?? ''), true);

if (!is_array($replies) || $replies === []) {
    fwrite(STDERR, "usage: smtp-server.php <base64 JSON list of replies>\n");
    exit(1);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $code, $error);

if ($server === false) {
    fwrite(STDERR, "listen: {$error}\n");
    exit(1);
}

$address = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($address, (int) strrpos($address, ':') + 1) . "\n");
fflush(STDOUT);

$peer = @stream_socket_accept($server, 10);

if ($peer === false) {
    exit(0);
}

stream_set_timeout($peer, 10);
fwrite($peer, array_shift($replies) . "\r\n");

while ($replies !== [] && ($line = fgets($peer)) !== false) {
    fwrite(STDOUT, json_encode(rtrim($line, "\r\n")) . "\n");
    fflush(STDOUT);

    $reply = (string) array_shift($replies);
    fwrite($peer, $reply . "\r\n");

    // The data is not a command, and the reply to it goes out the moment the
    // terminating dot is read.
    if (str_starts_with($reply, '354') && $replies !== []) {
        while (($data = fgets($peer)) !== false && $data !== ".\r\n") {
        }

        fwrite($peer, array_shift($replies) . "\r\n");
    }
}

fclose($peer);
fclose($server);
