<?php

declare(strict_types=1);

namespace Appwrite\Queue\Connection;

use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Queue\Connection;

/**
 * Borrows a Redis connection per command so concurrent workers do not
 * serialize on a single {@see \Utopia\Queue\Connection\Locking} mutex.
 *
 * Each checkout is exclusive, so php-redis stays single-owner without a lock.
 */
final class Pool implements Connection
{
    /**
     * @param UtopiaPool<Connection> $pool
     */
    public function __construct(private UtopiaPool $pool)
    {
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function rightPush(string $queue, string $payload): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function leftPush(string $queue, string $payload): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function listRemove(string $queue, string $key): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function listSize(string $key): int
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function remove(string $key): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function get(string $key): array|string|null
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function increment(string $key): int
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function decrement(string $key): int
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function ping(): bool
    {
        return $this->delegate(__FUNCTION__, []);
    }

    public function close(): void
    {
        $this->delegate(__FUNCTION__, []);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function delegate(string $method, array $args): mixed
    {
        return $this->pool->use(function (Connection $connection) use ($method, $args) {
            return $connection->{$method}(...$args);
        });
    }
}
