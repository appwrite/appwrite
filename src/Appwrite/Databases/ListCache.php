<?php

namespace Appwrite\Databases;

use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Console;

/**
 * A collection's cached list responses, held in one cache hash, and a version every purge changes.
 *
 * A list read takes the version before it queries and saves its result only while the version is unchanged, so a
 * read that ran while a purge happened is not cached.
 */
readonly class ListCache
{
    /**
     * Seconds a version is read back for. It only has to stay the same across one list request: a version that
     * expires between its two reads reads as changed, and a purge after it expired writes a new one.
     */
    private const int VERSION_TTL = 86400;

    public function __construct(
        private Cache $cache,
        private string $key,
    ) {
    }

    /**
     * @throws Unavailable when the version cannot be changed
     */
    public function purge(): bool
    {
        if ($this->cache->save($this->getVersionKey(), \bin2hex(\random_bytes(16)), ttl: self::VERSION_TTL) === false) {
            throw new Unavailable('Failed to change the version of the cached list responses of ' . $this->key);
        }

        return $this->cache->purge($this->key);
    }

    /**
     * The version to save a list read under, taken before its query, or null when the cache cannot tell.
     */
    public function getVersion(): ?string
    {
        try {
            $version = $this->cache->load($this->getVersionKey(), self::VERSION_TTL);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($version) ? $version : '';
    }

    /**
     * Save fields of a list read unless a purge changed the version since it was taken.
     *
     * @param array<string, mixed> $fields
     */
    public function save(array $fields, ?string $version): void
    {
        if ($version === null || $this->getVersion() !== $version) {
            return;
        }

        try {
            $this->cache->saveMany($this->key, $fields);
        } catch (\Throwable) {
        }
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

    private function getVersionKey(): string
    {
        return $this->key . ':version';
    }
}
