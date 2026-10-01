<?php

namespace Utopia\Abuse\Adapter\TokenBucket;

use Utopia\Abuse\Adapter\TokenBucket;

final readonly class None extends TokenBucket
{
    #[\Override]
    protected function hit(string $key, float $now): int
    {
        return 0;
    }

    #[\Override]
    protected function count(string $key, float $now): int
    {
        return 0;
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
