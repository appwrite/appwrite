<?php

namespace Utopia\Abuse\Tests\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;

final readonly class Fixed extends TokenBucket
{
    public function __construct(int $tokens, float $refillRate, private float $available, private float $time)
    {
        parent::__construct('fixed', $tokens, $refillRate);
    }

    #[\Override]
    protected function now(): float
    {
        return $this->time;
    }

    #[\Override]
    protected function hit(string $key, float $now): float
    {
        return $this->available;
    }

    #[\Override]
    protected function count(string $key, float $now): float
    {
        return $this->available;
    }

    #[\Override]
    protected function clear(string $key): void
    {
    }

    /**
     * @return array{}
     */
    #[\Override]
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return [];
    }

    #[\Override]
    public function cleanup(int $timestamp): bool
    {
        return true;
    }
}
