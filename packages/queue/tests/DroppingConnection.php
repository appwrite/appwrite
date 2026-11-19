<?php

declare(strict_types=1);

namespace Utopia\Queue\Tests;

use Utopia\Queue\Connection;

/**
 * Holds lists and values in memory and loses the connection on every write
 * that would move a job onward, as a sweep that dies part way through would.
 * Reads and pops still succeed, so a step that took the job before writing it
 * anywhere else is visible as a lost job.
 */
final class DroppingConnection implements Connection
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, string> */
    public array $values = [];

    public function execute(string $script, array $keys, array $args): mixed
    {
        throw new \RedisException('Connection lost');
    }

    /** @param array<mixed> $payload */
    public function rightPushArray(string $queue, array $payload): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        throw new \RedisException('Connection lost');
    }

    /** @return array<mixed>|false */
    public function rightPopArray(string $queue, int $timeout): array|false
    {
        throw new \LogicException('Not used by retry()');
    }

    /** @return array<mixed>|false */
    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        throw new \LogicException('Not used by retry()');
    }

    /** @param array<mixed> $payload */
    public function leftPushArray(string $queue, array $payload): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        throw new \RedisException('Connection lost');
    }

    /** @return array<mixed>|false */
    public function leftPopArray(string $queue, int $timeout): array|false
    {
        throw new \LogicException('Not used by retry()');
    }

    public function rightPush(string $queue, string $payload): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        return array_pop($this->lists[$queue]) ?? false;
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        throw new \LogicException('Not used by retry()');
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        throw new \RedisException('Connection lost');
    }

    public function leftPush(string $queue, string $payload): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        return array_shift($this->lists[$queue]) ?? false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function listSize(string $key): int
    {
        return \count($this->lists[$key] ?? []);
    }

    /** @return list<string> */
    public function listRange(string $key, int $total, int $offset): array
    {
        $list = $this->lists[$key] ?? [];
        $start = $offset < 0 ? \count($list) + $offset : $offset;

        return \array_slice($list, max(0, $start), $total);
    }

    public function remove(string $key): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function setNotExists(string $key, string $value, int $ttl = 0): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /** @param array<mixed> $value */
    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        throw new \RedisException('Connection lost');
    }

    public function increment(string $key): int
    {
        throw new \RedisException('Connection lost');
    }

    public function incrementBy(string $key, int $by): int
    {
        throw new \RedisException('Connection lost');
    }

    public function decrement(string $key): int
    {
        throw new \RedisException('Connection lost');
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}
