<?php

namespace Appwrite\Databases;

use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Console;

/**
 * A collection's cached list responses, held in one cache hash, and a version every purge changes.
 *
 * A list read takes the version before it queries, and both reads and writes a field named after it, so a read
 * that ran while a purge happened is never served: its rows land in a field of a version no later read looks up.
 * Naming the field after the version is what makes this safe without an atomic compare-and-set, as the version
 * comparison and the write of the rows are separate cache operations a purge can interleave with.
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
     * The value of a field of a list read cached under the given version, or false when it is not cached.
     *
     * A version a read could not take reads nothing, as nothing is ever saved under one.
     */
    public function load(string $field, int $ttl, ?string $version): mixed
    {
        if ($version === null) {
            return false;
        }

        try {
            return $this->cache->load($this->key, $ttl, $this->getField($field, $version));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Save fields of a list read under the version it was taken under, unless a purge already changed it.
     *
     * @param array<string, mixed> $fields
     */
    public function save(array $fields, ?string $version): void
    {
        if ($version === null || $this->getVersion() !== $version) {
            return;
        }

        $versioned = [];
        foreach ($fields as $field => $value) {
            $versioned[$this->getField($field, $version)] = $value;
        }

        try {
            $this->cache->saveMany($this->key, $versioned);
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

    /**
     * The hash field one variation of a list read is cached under while $version is the collection's version.
     *
     * A purge changes the version and removes the hash, so a field of a superseded version is both empty and
     * unreachable: a save that lands after the purge that should have stopped it ages out instead of serving
     * rows the purge revoked.
     */
    private function getField(string $field, string $version): string
    {
        return $version . ':' . $field;
    }
}
