<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

/**
 * Thrown by Multiplexing when the reader made no progress for longer than
 * livenessTimeout while callers were still waiting, so the connection was
 * declared dead and torn down.
 *
 * This is a connection verdict, unlike {@see TimeoutException} — but resending
 * is still not the recovery. The connection has already been replaced, and a
 * fresh socket to a server that has answered nothing for seconds will not answer
 * this attempt either. Surfacing the failure lets the caller's own breaker see
 * it; the next call gets the rebuilt connection.
 */
final class IdleConnectionException extends ConnectionException {}
