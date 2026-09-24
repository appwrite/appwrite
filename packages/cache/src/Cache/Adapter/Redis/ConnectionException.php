<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter\Redis;

/**
 * Thrown by Multiplexing when the underlying Redis connection is
 * unhealthy and the operation should be retried (or surfaced as a connection
 * error rather than a Redis-level error).
 */
class ConnectionException extends \RedisException {}
