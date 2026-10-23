<?php

namespace Appwrite\Databases;

use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Console;

/**
 * A collection's cached list responses, held in one cache hash.
 */
readonly class ListCache
{
    public function __construct(
        private Cache $cache,
        private string $key,
    ) {
    }

    public function purge(): bool
    {
        return $this->cache->purge($this->key);
    }

    /**
     * Run a write that changes who may read rows, purging the given caches before and after it.
     *
     * A purge that fails before the write refuses it, so nothing is stored while a response cached before it
     * survives. The purge after the write clears a response cached while it ran; it is best-effort, as the write is
     * already stored.
     *
     * @template T
     * @param array<ListCache> $caches
     * @param callable(): T $write
     * @return T
     * @throws Unavailable when a cache cannot be purged before the write
     */
    public static function purged(array $caches, callable $write): mixed
    {
        foreach ($caches as $cache) {
            try {
                $cache->purge();
            } catch (\Throwable $error) {
                throw new Unavailable('Failed to purge the cached list responses of ' . $cache->key, previous: $error);
            }
        }

        $result = $write();

        foreach ($caches as $cache) {
            try {
                $cache->purge();
            } catch (\Throwable $error) {
                Console::warning('Failed to purge the cached list responses of ' . $cache->key . ' after a permission change: ' . $error->getMessage());
            }
        }

        return $result;
    }
}
