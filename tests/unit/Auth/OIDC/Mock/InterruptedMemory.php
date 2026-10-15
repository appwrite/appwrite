<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OIDC\Mock;

use Utopia\Cache\Adapter\Memory;

/**
 * Memory cache that runs another request right after its first cache miss,
 * the window in which two workers can both find the cache empty.
 */
final class InterruptedMemory extends Memory
{
    /**
     * @param \Closure(): void $interruption
     */
    public function __construct(private ?\Closure $interruption)
    {
    }

    #[\Override]
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $result = parent::load($key, $ttl, $hash);
        if ($result === false && $this->interruption !== null) {
            $interruption = $this->interruption;
            $this->interruption = null;
            $interruption();
        }

        return $result;
    }
}
