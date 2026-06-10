<?php

namespace Appwrite\PubSub\Adapter;

use Appwrite\PubSub\Adapter;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Pools\Pool as UtopiaPool;

class Pool implements Adapter
{
    public function __construct(private UtopiaPool $pool)
    {
    }

    public function ping($message = null): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function subscribe($channels, $callback): void
    {
        $maxRetries = 4;
        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->delegate('subscribe', [$channels, $callback]);
                return;
            } catch (\RedisException $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    \usleep(50000 * ($attempt + 1));
                    continue;
                }
            }
        }

        throw $lastException;
    }

    public function publish($channel, $message): void
    {
        $this->delegate(__FUNCTION__, \func_get_args());
    }

    /**
     * Forward method calls to the internal adapter instance via the pool.
     *
     * Required because __call() can't be used to implement abstract methods.
     *
     * @param string $method
     * @param array<mixed> $args
     * @return mixed
     * @throws DatabaseException
     */
    public function delegate(string $method, array $args): mixed
    {
        return $this->pool->use(function (Adapter $adapter) use ($method, $args) {
            return $adapter->{$method}(...$args);
        });
    }
}
