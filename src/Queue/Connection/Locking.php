<?php

namespace Utopia\Queue\Connection;

use Utopia\Lock\Lock;
use Utopia\Lock\Mutex;
use Utopia\Queue\Connection;

/**
 * Wraps any {@see Connection} and serializes every command behind a single
 * lock, so that concurrent coroutines sharing one connection cannot interleave
 * their requests and responses on the same socket.
 *
 * Outside of a coroutine there is no preemption, so the lock degrades to a
 * plain in-process flag (see {@see Mutex}).
 */
class Locking implements Connection
{
    /**
     * Wait forever when acquiring the lock; a command should never be dropped
     * just because the connection is momentarily busy.
     */
    private const float ACQUIRE_TIMEOUT = -1;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly Lock $lock = new Mutex(),
    ) {}

    /**
     * Run a command while holding the lock, ensuring only one runs at a time.
     *
     * @template T
     * @param callable(): T $command
     * @return T
     */
    protected function synchronize(callable $command): mixed
    {
        return $this->lock->withLock($command, self::ACQUIRE_TIMEOUT);
    }

    public function execute(string $script, array $keys, array $args): mixed
    {
        return $this->synchronize(fn(): mixed => $this->connection->execute($script, $keys, $args));
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->rightPushArray($queue, $payload));
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        return $this->synchronize(fn(): array|false => $this->connection->rightPopArray($queue, $timeout));
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        return $this->synchronize(fn(): array|false => $this->connection->rightPopLeftPushArray($queue, $destination, $timeout));
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->leftPushArray($queue, $payload));
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->leftPushMany($queue, $payloads));
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->rightPushMany($queue, $payloads));
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        return $this->synchronize(fn(): array|false => $this->connection->leftPopArray($queue, $timeout));
    }

    public function rightPush(string $queue, string $payload): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->rightPush($queue, $payload));
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        return $this->synchronize(fn(): string|false => $this->connection->rightPop($queue, $timeout));
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        return $this->synchronize(fn(): array => $this->connection->rightPopMany($queue, $count, $timeout));
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        return $this->synchronize(fn(): string|false => $this->connection->rightPopLeftPush($queue, $destination, $timeout));
    }

    public function leftPush(string $queue, string $payload): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->leftPush($queue, $payload));
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        return $this->synchronize(fn(): string|false => $this->connection->leftPop($queue, $timeout));
    }

    public function listRemove(string $queue, string $key): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->listRemove($queue, $key));
    }

    public function listSize(string $key): int
    {
        return $this->synchronize(fn(): int => $this->connection->listSize($key));
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return $this->synchronize(fn(): array => $this->connection->listRange($key, $total, $offset));
    }

    public function remove(string $key): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->remove($key));
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->set($key, $value, $ttl));
    }

    public function setNotExists(string $key, string $value, int $ttl = 0): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->setNotExists($key, $value, $ttl));
    }

    public function get(string $key): array|string|null
    {
        return $this->synchronize(fn(): string|array|null => $this->connection->get($key));
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->setArray($key, $value, $ttl));
    }

    public function increment(string $key): int
    {
        return $this->synchronize(fn(): int => $this->connection->increment($key));
    }

    public function incrementBy(string $key, int $by): int
    {
        return $this->synchronize(fn(): int => $this->connection->incrementBy($key, $by));
    }

    public function decrement(string $key): int
    {
        return $this->synchronize(fn(): int => $this->connection->decrement($key));
    }

    public function ping(): bool
    {
        return $this->synchronize(fn(): bool => $this->connection->ping());
    }

    public function close(): void
    {
        $this->synchronize(fn() => $this->connection->close());
    }
}
