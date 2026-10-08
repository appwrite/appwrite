<?php

namespace Utopia\Abuse\Adapter\SlidingWindow;

use Utopia\Abuse\Adapter\SlidingWindow;

final readonly class None extends SlidingWindow
{
    #[\Override]
    protected function hit(string $key, int $window, float $elapsed): int
    {
        return 0;
    }

    #[\Override]
    protected function count(string $key, int $window, float $elapsed): int
    {
        return 0;
    }

    #[\Override]
    protected function clear(string $key, int $window): void
    {
    }

    /**
     * @return array<string, mixed>
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
