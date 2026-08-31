<?php

declare(strict_types=1);

namespace Utopia\Cdn;

use Utopia\Cdn\Cache\Adapter;

class Cache
{
    public function __construct(private readonly Adapter $adapter) {}

    /**
     * @param array<int, string> $paths
     */
    public function purgePaths(string $domain, array $paths): void
    {
        $this->adapter->purgePaths(Domain::validate($domain), Domain::validatePaths($paths));
    }

    public function purgeDomain(string $domain): void
    {
        $this->adapter->purgeDomain(Domain::validate($domain));
    }

    /**
     * @param array<int, string> $keys
     */
    public function purgeKeys(array $keys): void
    {
        $this->adapter->purgeKeys($keys);
    }

    /**
     * Purges the adapter's entire cache. Prefer purgeDomain() or purgeKeys().
     */
    public function purgeZone(): void
    {
        $this->adapter->purgeZone();
    }
}
