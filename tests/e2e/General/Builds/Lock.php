<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

final class Lock
{
    public function __invoke(string $key, int $ttl, callable $callback, float $timeout = 0): mixed
    {
        return $callback();
    }
}
