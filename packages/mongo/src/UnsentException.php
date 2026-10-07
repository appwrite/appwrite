<?php

namespace Utopia\Mongo;

/**
 * A failure raised before the command reached the server.
 *
 * Connection validation and the dial that precedes it run ahead of every
 * send, so a failure raised there leaves the command unsent and nothing
 * applied — the one class of failure a caller may safely replay.
 *
 * The distinction is carried by the TYPE and nothing else. A message quotes
 * caller-chosen values, so callers cannot classify on text; and an error code
 * cannot prove it either, because a post-send error response carries the
 * server's own code into an ordinary {@see Exception} — including codes like
 * HostUnreachable that look pre-send. Only the client raises this type, and
 * only before it has sent anything.
 */
class UnsentException extends Exception
{
    public function isUnsentError(): bool
    {
        return true;
    }
}
