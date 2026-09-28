<?php

namespace Utopia\Abuse\Adapters\SlidingWindow;

use Utopia\Abuse\Adapters\SlidingWindow;

class None extends SlidingWindow
{
    /**
     * @param  int  $ttl  Accepted for parity with the storage adapters; unused here
     */
    public function __construct(string $key, int $limit, int $windowSize, int $ttl) // @phpstan-ignore constructor.unusedParameter
    {
        if ($windowSize <= 0) {
            throw new \InvalidArgumentException('windowSize must be greater than 0');
        }

        $this->key = $key;
        $this->limit = $limit;
        $now = \time();
        $this->timestamp = (int) ($now - ($now % $windowSize));
    }

    protected function count(string $key, int $timestamp): int
    {
        return 0;
    }

    public function check(): bool
    {
        return false;
    }

    public function reset(): void
    {
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return [];
    }

    /**
     * Delete all logs older than $timestamp
     *
     * @param  int  $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
