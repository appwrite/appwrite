<?php

declare(strict_types=1);

namespace Appwrite\Tests\Queue;

use Swoole\Coroutine;
use Utopia\Queue\Connection;

/**
 * Minimal in-memory {@see Connection} for concurrency tests (no Redis).
 * Empty pops yield so a busy receive loop does not starve handlers.
 */
final class InMemoryConnection implements Connection
{
    /** @var array<string, list<mixed>> */
    private array $lists = [];

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $counters = [];

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

    /**
     * @param list<string> $payloads
     */
    public function rightPushMany(string $queue, array $payloads): bool
    {
        foreach ($payloads as $payload) {
            $this->lists[$queue][] = $payload;
        }

        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        $value = $this->pop($queue, fromTail: true);

        return \is_string($value) ? $value : false;
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

    /**
     * @param list<string> $payloads
     */
    public function leftPushMany(string $queue, array $payloads): bool
    {
        $this->lists[$queue] ??= [];

        // Reversed, so the batch keeps its order once each payload has been pushed
        // onto the head — the same result as one LPUSH with every payload.
        foreach (\array_reverse($payloads) as $payload) {
            array_unshift($this->lists[$queue], $payload);
        }

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
        unset($this->values[$key]);

        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function get(string $key): array|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function increment(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function decrement(string $key): int
    {
        return $this->counters[$key] = ($this->counters[$key] ?? 0) - 1;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void
    {
    }

    private function pop(string $queue, bool $fromTail): mixed
    {
        if (empty($this->lists[$queue])) {
            if (Coroutine::getCid() !== -1) {
                Coroutine::sleep(0.005);
            }

            return null;
        }

        return $fromTail ? array_pop($this->lists[$queue]) : array_shift($this->lists[$queue]);
    }
}
