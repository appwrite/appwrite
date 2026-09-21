<?php

namespace Tests\E2E\Adapter;

use Swoole\Coroutine;
use Utopia\Queue\Connection;

/**
 * Minimal in-memory {@see Connection} for tests, backing the broker without a
 * real Redis server. An empty pop yields so a busy receive loop doesn't starve
 * the handler coroutines.
 */
class InMemoryConnection implements Connection
{
    /** @var array<string, list<mixed>> */
    private array $lists = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, float> */
    private array $expires = [];
    private float $now = 0.0;

    public function advanceToNextExpiry(bool $justBefore = false): void
    {
        if ($this->expires === []) {
            throw new \LogicException('No expiring keys');
        }

        $this->now = min($this->expires) - ($justBefore ? 0.5 : 0.0);
        foreach ($this->expires as $key => $expiry) {
            if ($expiry <= $this->now) {
                $this->remove($key);
            }
        }
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue][] = $payload;

        return true;
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        $value = $this->pop($queue, fromTail: true);

        return \is_array($value) ? $value : false;
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        $value = $this->rightPopArray($queue, $timeout);
        if (\is_array($value)) {
            $this->lists[$destination] ??= [];
            array_unshift($this->lists[$destination], $value);
        }

        return $value;
    }

    public function leftPushArray(string $queue, array $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], $payload);

        return true;
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->leftPush($queue, $payload);
        }

        return true;
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->rightPush($queue, $payload);
        }

        return true;
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        $value = $this->pop($queue, fromTail: false);

        return \is_array($value) ? $value : false;
    }

    public function rightPush(string $queue, string $payload): bool
    {
        $this->lists[$queue][] = $payload;

        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        $value = $this->pop($queue, fromTail: true);

        return \is_string($value) ? $value : false;
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        $popped = [];

        while (\count($popped) < $count && !empty($this->lists[$queue])) {
            $value = array_pop($this->lists[$queue]);
            if (!\is_string($value)) {
                break;
            }
            $popped[] = $value;
        }

        return $popped;
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        $value = $this->rightPop($queue, $timeout);
        if (\is_string($value)) {
            $this->lists[$destination] ??= [];
            array_unshift($this->lists[$destination], $value);
        }

        return $value;
    }

    public function leftPush(string $queue, string $payload): bool
    {
        $this->lists[$queue] ??= [];
        array_unshift($this->lists[$queue], $payload);

        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        $value = $this->pop($queue, fromTail: false);

        return \is_string($value) ? $value : false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        $list = $this->lists[$queue] ?? [];
        $index = array_search($key, $list, true);
        if ($index === false) {
            return false;
        }

        unset($list[$index]);
        $this->lists[$queue] = array_values($list);

        return true;
    }

    public function listSize(string $key): int
    {
        return \count($this->lists[$key] ?? []);
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return \array_slice($this->lists[$key] ?? [], $offset, $total);
    }

    public function remove(string $key): bool
    {
        unset($this->values[$key], $this->expires[$key]);

        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        unset($this->expires[$key]);
        if ($ttl > 0) {
            $this->expires[$key] = $this->now + $ttl;
        }

        return true;
    }

    public function setNotExists(string $key, string $value, int $ttl = 0): bool
    {
        if (\array_key_exists($key, $this->values)) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function get(string $key): array|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        unset($this->expires[$key]);
        if ($ttl > 0) {
            $this->expires[$key] = $this->now + $ttl;
        }

        return true;
    }

    public function increment(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function incrementBy(string $key, int $by): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function decrement(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) - 1;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void {}

    /** Pop from either end, yielding when empty so the receive loop doesn't spin. */
    private function pop(string $queue, bool $fromTail): mixed
    {
        if (empty($this->lists[$queue])) {
            // Guarded on the class as well as the coroutine id: the unit tier
            // runs this fake on a bare host where Swoole is not loaded at all.
            if (class_exists(Coroutine::class) && Coroutine::getCid() !== -1) {
                Coroutine::sleep(0.005);
            }

            return null;
        }

        return $fromTail ? array_pop($this->lists[$queue]) : array_shift($this->lists[$queue]);
    }
}
