<?php

declare(strict_types=1);

namespace Utopia\Schedule\Store;

use Utopia\Schedule\Claim;
use Utopia\Schedule\Store;

/**
 * In-process claim storage. Coverage and leadership survive ticks but
 * not restarts, and only instances sharing the object contend; use
 * shared storage in production.
 */
final class Memory implements Store
{
    private ?Claim $claim = null;

    #[\Override]
    public function load(): ?Claim
    {
        return $this->claim;
    }

    #[\Override]
    public function swap(?string $expected, Claim $next): bool
    {
        if (($this->claim?->token) !== $expected) {
            return false;
        }

        $this->claim = $next;

        return true;
    }
}
