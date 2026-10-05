<?php

namespace Utopia\Abuse\Adapters\TokenBucket;

use Utopia\Abuse\Adapters\TokenBucket;

class None extends TokenBucket
{
    /**
     * @param  float  $refillRate  Accepted for parity with the storage adapters; unused here
     */
    public function __construct(string $key, int $tokens, float $refillRate) // @phpstan-ignore constructor.unusedParameter
    {
        $this->key = $key;
        $this->tokens = $tokens;
        $this->timestamp = \time();
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
