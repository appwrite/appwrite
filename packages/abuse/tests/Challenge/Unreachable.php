<?php

namespace Utopia\Abuse\Tests\Challenge;

use Utopia\Abuse\Adapter\TimeLimit;

/**
 * A rate limiter whose store is down: every call throws.
 */
final readonly class Unreachable extends TimeLimit
{
    #[\Override]
    protected function hit(string $key, int $window): int
    {
        throw new \RuntimeException('store down');
    }

    #[\Override]
    protected function count(string $key, int $window): int
    {
        throw new \RuntimeException('store down');
    }

    #[\Override]
    protected function set(string $key, int $window, int $value): void
    {
        throw new \RuntimeException('store down');
    }

    #[\Override]
    protected function unhit(string $key, int $window): void
    {
        throw new \RuntimeException('store down');
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
