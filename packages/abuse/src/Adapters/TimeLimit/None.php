<?php

namespace Utopia\Abuse\Adapters\TimeLimit;

use Utopia\Abuse\Adapters\TimeLimit;

class None extends TimeLimit
{
    /**
     * @var int
     */
    protected int $ttl;

    public function __construct(string $key, int $limit, int $seconds)
    {
        $this->key = $key;
        $this->ttl = $seconds;
        $now = \time();
        $this->timestamp = (int) ($now - ($now % $seconds));
        $this->limit = $limit;
    }

    protected function count(string $key, int $timestamp): int
    {
        return 0;
    }

    protected function hit(string $key, int $timestamp): void
    {
    }

    protected function set(string $key, int $timestamp, int $value): void
    {
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param int|null $offset
     * @param int|null $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return [];
    }

    /**
     * Delete all logs older than $timestamp
     *
     * @param int $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
