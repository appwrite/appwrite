<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Redis\TimeoutException;

/** Real in-process cache whose reads can be made to miss their deadline. */
final class SlowLoadMemory extends Memory
{
    public bool $slow = false;

    #[\Override]
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if ($this->slow) {
            throw new TimeoutException('read deadline expired');
        }

        return parent::load($key, $ttl, $hash);
    }
}
