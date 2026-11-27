<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Cache\Adapter\Memory;

/**
 * A memory cache whose entries can be made older, to cross a TTL without waiting.
 */
final class AgingMemory extends Memory
{
    public function age(int $seconds): void
    {
        foreach ($this->store as $key => $entry) {
            $this->store[$key]['time'] = $entry['time'] - $seconds;
        }
    }
}
