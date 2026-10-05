<?php

namespace Utopia\Abuse\Adapter\TimeLimit;

use Utopia\Abuse\Adapter\TimeLimit;

final readonly class None extends TimeLimit
{
    #[\Override]
    protected function hit(string $key, int $window): int
    {
        return 0;
    }

    #[\Override]
    protected function count(string $key, int $window): int
    {
        return 0;
    }

    #[\Override]
    protected function set(string $key, int $window, int $value): void
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
