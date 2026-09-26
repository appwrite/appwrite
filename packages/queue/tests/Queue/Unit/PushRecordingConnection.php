<?php

declare(strict_types=1);

namespace Tests\Unit;

use Utopia\Queue\Connection;

/**
 * Records the push the broker chooses, so a test can tell one command from N
 * without a Redis server. Everything the batching path does not touch answers
 * the interface and nothing more.
 */
final class PushRecordingConnection implements Connection
{
    public function execute(string $script, array $keys, array $args): mixed
    {
        throw new \LogicException('Publisher tests must not execute consumer scripts');
    }

    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $pushed = [];

    /** @var list<array<string, mixed>> */
    public array $arrays = [];

    /**
     * List lengths this fake reports, keyed by the full Redis key.
     *
     * @var array<string, int>
     */
    public array $sizes = [];

    public function leftPushArray(string $queue, array $payload): bool
    {
        $this->calls[] = ['leftPushArray', $queue];
        $this->arrays[] = $payload;

        return true;
    }

    public function rightPushArray(string $queue, array $payload): bool
    {
        $this->calls[] = ['rightPushArray', $queue];
        $this->arrays[] = $payload;

        return true;
    }

    public function leftPushMany(string $queue, array $payloads): bool
    {
        $this->calls[] = ['leftPushMany', $queue];
        $this->pushed = [...$this->pushed, ...$payloads];

        return true;
    }

    public function rightPushMany(string $queue, array $payloads): bool
    {
        $this->calls[] = ['rightPushMany', $queue];
        $this->pushed = [...$this->pushed, ...$payloads];

        return true;
    }

    public function rightPopArray(string $queue, int $timeout): array|false
    {
        return false;
    }

    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false
    {
        return false;
    }

    public function leftPopArray(string $queue, int $timeout): array|false
    {
        return false;
    }

    public function rightPush(string $queue, string $payload): bool
    {
        $this->calls[] = ['rightPush', $queue];
        $this->pushed[] = $payload;

        return true;
    }

    public function rightPop(string $queue, int $timeout): string|false
    {
        return false;
    }

    public function rightPopMany(string $queue, int $count, int $timeout): array
    {
        $this->calls[] = ['rightPopMany', $queue];

        return [];
    }

    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
    {
        return false;
    }

    public function leftPush(string $queue, string $payload): bool
    {
        $this->calls[] = ['leftPush', $queue];
        $this->pushed[] = $payload;

        return true;
    }

    public function leftPop(string $queue, int $timeout): string|false
    {
        return false;
    }

    public function listRemove(string $queue, string $key): bool
    {
        return true;
    }

    public function listSize(string $key): int
    {
        return $this->sizes[$key] ?? 0;
    }

    public function listRange(string $key, int $total, int $offset): array
    {
        return [];
    }

    public function remove(string $key): bool
    {
        return true;
    }

    public function set(string $key, string $value, int $ttl = 0): bool
    {
        return true;
    }

    public function setNotExists(string $key, string $value, int $ttl = 0): bool
    {
        return true;
    }

    public function get(string $key): array|string|null
    {
        return null;
    }

    public function setArray(string $key, array $value, int $ttl = 0): bool
    {
        return true;
    }

    public function increment(string $key): int
    {
        return 0;
    }

    public function incrementBy(string $key, int $by): int
    {
        $this->calls[] = ['incrementBy', $key];

        return $by;
    }

    public function decrement(string $key): int
    {
        return 0;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void {}
}
