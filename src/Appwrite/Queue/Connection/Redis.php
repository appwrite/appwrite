<?php

namespace Appwrite\Queue\Connection;

use Appwrite\Redis\Auth as RedisAuth;

/**
 * Temporary Appwrite-side wrapper until the upstream Utopia Queue Redis
 * connection authenticates with its stored username/password credentials.
 */
class Redis extends \Utopia\Queue\Connection\Redis
{
    protected function getRedis(): \Redis
    {
        if ($this->redis) {
            return $this->redis;
        }

        $connectTimeout = $this->connectTimeout < 0 ? 0 : $this->connectTimeout;

        for ($attempt = 1; $attempt <= self::CONNECT_MAX_ATTEMPTS; $attempt++) {
            $redis = new \Redis();

            try {
                $redis->connect($this->host, $this->port, $connectTimeout);

                RedisAuth::authenticate($redis, $this->user, $this->password);

                if ($this->readTimeout >= 0) {
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);
                }

                $this->redis = $redis;

                return $this->redis;
            } catch (\RedisException $e) {
                try {
                    $redis->close();
                } catch (\Throwable) {
                }

                if ($attempt === self::CONNECT_MAX_ATTEMPTS) {
                    throw new \RedisException(
                        \sprintf(
                            'Failed to connect to Redis at %s:%d after %d attempts: %s',
                            $this->host,
                            $this->port,
                            self::CONNECT_MAX_ATTEMPTS,
                            $e->getMessage(),
                        ),
                        (int) $e->getCode(),
                        $e,
                    );
                }

                $backoffMs = min(
                    self::CONNECT_MAX_BACKOFF_MS,
                    self::CONNECT_BACKOFF_MS * (2 ** ($attempt - 1)),
                );

                usleep(mt_rand(0, $backoffMs) * 1000);
            }
        }

        throw new \RedisException(\sprintf(
            'Unreachable: Redis connect loop for %s:%d exited after %d attempts without success or exception.',
            $this->host,
            $this->port,
            self::CONNECT_MAX_ATTEMPTS,
        ));
    }
}
