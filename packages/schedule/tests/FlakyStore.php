<?php

declare(strict_types=1);

namespace Utopia\Schedule\Tests;

use Utopia\Schedule\Claim;
use Utopia\Schedule\Store;
use Utopia\Schedule\Store\Memory as MemoryStore;

/**
 * A store whose next few calls fail, the way a dropped Redis connection
 * fails the command in flight before the client reconnects.
 */
final class FlakyStore implements Store
{
    public function __construct(
        public int $failures = 0,
        private readonly Store $store = new MemoryStore(),
    ) {
    }

    #[\Override]
    public function load(): ?Claim
    {
        $this->fail();

        return $this->store->load();
    }

    #[\Override]
    public function swap(?string $expected, Claim $next): bool
    {
        $this->fail();

        return $this->store->swap($expected, $next);
    }

    private function fail(): void
    {
        if ($this->failures > 0) {
            --$this->failures;

            throw new \RuntimeException('read error on connection');
        }
    }
}
