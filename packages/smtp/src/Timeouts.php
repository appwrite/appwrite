<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * How long each kind of wait may last.
 *
 * Reaching a server and hearing back from it fail differently and deserve
 * different patience. A host that is down should be given up on in seconds,
 * while the reply to a message can take as long as the server needs to scan
 * it — RFC 5321 section 4.5.3.2 asks for ten minutes there. One value for both
 * means choosing between noticing an outage quickly and letting a large
 * message through.
 *
 * These are per operation, not per session: a slow transfer of many chunks is
 * bounded by the size of the message rather than by any single number here.
 */
class Timeouts
{
    /**
     * @param  float  $connect  Opening the socket, and any TLS handshake on it.
     * @param  float  $read  Waiting for one reply, or one block of one.
     * @param  float  $write  Handing over one command, or one block of message data.
     */
    public function __construct(
        public readonly float $connect = 10.0,
        public readonly float $read = 30.0,
        public readonly float $write = 30.0,
    ) {
        foreach (['connect' => $connect, 'read' => $read, 'write' => $write] as $name => $seconds) {
            // NAN fails every comparison and INF passes them all, so neither is
            // caught by asking whether the number is positive.
            if (! is_finite($seconds) || $seconds <= 0) {
                // var_export rather than interpolation: coercing NAN to a
                // string is itself a warning.
                throw new \InvalidArgumentException(\sprintf(
                    'The %s timeout must be a finite number greater than zero, got %s',
                    $name,
                    var_export($seconds, true),
                ));
            }
        }
    }
}
