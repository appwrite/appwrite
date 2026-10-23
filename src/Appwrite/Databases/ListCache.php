<?php

namespace Appwrite\Databases;

use Utopia\Cache\Cache;
use Utopia\Cache\Exception\Unavailable;
use Utopia\Console;

/**
 * A collection's cached list responses, held in one cache hash.
 *
 * A write that changes who may read its rows is fenced: before it runs it registers a token and retires every cached
 * response, and while any token is live, lists are read without the cache. A response is only saved under the
 * hash's generation from before its read, so a read that raced the retirement is dropped. A fence that cannot be
 * registered refuses the write before it is stored. A release that fails lets its token lapse instead, so a stored
 * write never fails on the cache and no response read before it is served again.
 */
readonly class ListCache
{
    /**
     * Seconds a fence holds without its release. It must outlast the longest write, as a fence that lapses while its
     * write still runs lets a read cache rows the write is about to change.
     */
    private const int FENCE_TTL = 300;

    public function __construct(
        private Cache $cache,
        private string $key,
    ) {
    }

    /**
     * The generation to save a response under, or null while a fenced write runs or the cache cannot tell, when
     * nothing may be saved.
     */
    public function lease(): ?string
    {
        try {
            // Read before the fences: a fence registered after this read retires the generation it returns.
            $generation = $this->cache->getGeneration($this->key);

            if ($this->cache->loadMany($this->getFenceKey(), self::FENCE_TTL) !== []) {
                return null;
            }

            return $generation;
        } catch (\Throwable) {
            return null;
        }
    }

    public function load(string $field, int $ttl): mixed
    {
        try {
            return $this->cache->load($this->key, $ttl, $field);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int|string, mixed>|string $data
     */
    public function save(string $field, array|string $data, ?string $lease): void
    {
        if ($lease === null) {
            return;
        }

        try {
            $this->cache->saveWithLease($this->key, $data, $field, $lease);
        } catch (\Throwable) {
        }
    }

    public function purge(): bool
    {
        return $this->cache->purge($this->key);
    }

    /**
     * @throws Unavailable when the fence cannot be registered or the cached responses cannot be retired
     */
    public function fence(): string
    {
        $token = \bin2hex(\random_bytes(16));

        try {
            $registered = $this->cache->save($this->getFenceKey(), (string) \time(), $token, self::FENCE_TTL);
        } catch (\Throwable $error) {
            throw new Unavailable('Failed to fence the cached list responses of ' . $this->key, previous: $error);
        }

        if ($registered === false) {
            throw new Unavailable('Failed to fence the cached list responses of ' . $this->key);
        }

        // Registered before the purge, so a read that leases the purged generation also sees the fence.
        try {
            $this->purge();
        } catch (\Throwable $error) {
            $this->release($token);

            throw new Unavailable('Failed to retire the cached list responses of ' . $this->key, previous: $error);
        }

        return $token;
    }

    public function release(string $token): void
    {
        try {
            $this->cache->purge($this->getFenceKey(), $token);
        } catch (\Throwable $error) {
            Console::warning('Failed to release the fence on ' . $this->key . ', it lapses in ' . self::FENCE_TTL . ' seconds: ' . $error->getMessage());
        }
    }

    /**
     * Run a write behind a fence on each of the given caches.
     *
     * @template T
     * @param array<ListCache> $caches
     * @param callable(): T $write
     * @return T
     */
    public static function fenced(array $caches, callable $write): mixed
    {
        $fences = [];

        try {
            foreach ($caches as $cache) {
                $fences[] = [$cache, $cache->fence()];
            }

            return $write();
        } finally {
            foreach ($fences as [$cache, $token]) {
                $cache->release($token);
            }
        }
    }

    private function getFenceKey(): string
    {
        return $this->key . ':fences';
    }
}
