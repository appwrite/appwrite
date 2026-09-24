<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

/**
 * Thrown by Multiplexing when a caller's own read deadline expires before its
 * response arrived.
 *
 * Distinct from its parent on purpose. A {@see ConnectionException} is a verdict
 * about the *connection* — the socket is gone, a frame failed to parse, a send
 * was truncated — and the only recovery is to rebuild it. This is a verdict
 * about one *call*: the server was slower than this caller was willing to wait,
 * which says nothing about whether the connection is healthy. Conflating the two
 * is expensive on a multiplexed connection, because tearing it down fails every
 * other caller queued behind the slow one.
 *
 * Extends ConnectionException so existing `catch` sites keep working; callers
 * that need to tell "slow" from "broken" apart match this type first.
 */
final class TimeoutException extends ConnectionException {}
