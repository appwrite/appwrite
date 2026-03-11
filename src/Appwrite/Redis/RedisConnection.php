<?php

namespace Appwrite\Redis;

class RedisConnection
{
    /**
     * Create a persistent Redis connection with automatic retry on stale connections.
     *
     * Persistent connections (pconnect) can become stale when the Redis server
     * restarts or the connection is interrupted. When reused, they throw a
     * RedisException with "read error on connection". This method catches that
     * error, closes the stale connection, and retries once.
     */
    public static function create(\Redis $redis, string $host, int $port, string $pass = ''): \Redis
    {
        try {
            @$redis->pconnect($host, $port);
        } catch (\RedisException $e) {
            $redis->close();
            @$redis->pconnect($host, $port);
        }

        if ($pass) {
            $redis->auth($pass);
        }

        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return $redis;
    }
}
