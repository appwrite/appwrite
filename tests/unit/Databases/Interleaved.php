<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Cache\Cache;

/**
 * A cache that runs one write right after it hands out a version, as a permission write interleaves with a list
 * read that already compared the version it was taken under.
 */
final class Interleaved extends Cache
{
    /**
     * @var ?\Closure(): void
     */
    private ?\Closure $between = null;

    /**
     * Run $write once, after the next version read answers and before its caller acts on it.
     *
     * @param \Closure(): void $write
     */
    public function between(\Closure $write): void
    {
        $this->between = $write;
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $value = parent::load($key, $ttl, $hash);

        if ($this->between !== null && \str_ends_with($key, ':version')) {
            $write = $this->between;
            $this->between = null;
            $write();
        }

        return $value;
    }
}
