<?php

declare(strict_types=1);

namespace Appwrite\Utopia\Database;

use Override;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;

class Database extends UtopiaDatabase
{
    /**
     * In-process cache storing resolved collection definitions.
     *
     * @var array<string, array{
     *     collection: Collection,
     *     version: string,
     *     validatedAt: float
     * }>
     */
    protected static array $collectionMemo = [];

    /**
     * Window in seconds during which a memoized collection is served directly
     * without pinging the shared cache for version validation.
     */
    protected static float $revalidateTtl = 2.0;

    /**
     * Local monotonic sequence counter to record process-level mutations.
     */
    protected static int $localSequence = 0;

    /**
     * Override to serve collection metadata from in-process memory when valid,
     * saving repeated decoding and object tree reconstruction on hot paths.
     */
    #[Override]
    public function findCollection(string $collection): ?Collection
    {
        if ($collection === self::METADATA) {
            return parent::findCollection($collection);
        }

        // Bypassing memo during active transactions ensures uncommitted schema
        // mutations are isolated to the transactional adapter.
        if ($this->adapter->inTransaction()) {
            return parent::findCollection($collection);
        }

        // If caching is globally disabled (None adapter), bypass memo.
        if ($this->getCacheName() === 'none') {
            return parent::findCollection($collection);
        }

        $cacheKey = $this->getCollectionMetadataCacheKey($collection);
        $now = \microtime(true);

        if (isset(self::$collectionMemo[$cacheKey])) {
            $entry = self::$collectionMemo[$cacheKey];

            // Serve directly if still within the local revalidation window.
            if (($now - $entry['validatedAt']) < self::$revalidateTtl) {
                return clone $entry['collection'];
            }

            // Outside TTL window: check generation with shared cache to detect cross-node updates.
            [$collectionKey, $documentKey] = $this->getCacheBaseKeys(self::METADATA, $collection);
            $currentVersion = $this->resolveCacheGeneration($documentKey);

            if ($currentVersion === $entry['version']) {
                self::$collectionMemo[$cacheKey]['validatedAt'] = $now;
                return clone $entry['collection'];
            }
        }

        $definition = parent::findCollection($collection);

        if ($definition !== null) {
            [$collectionKey, $documentKey] = $this->getCacheBaseKeys(self::METADATA, $collection);
            $version = $this->resolveCacheGeneration($documentKey);

            self::$collectionMemo[$cacheKey] = [
                'collection' => clone $definition,
                'version' => $version,
                'validatedAt' => $now,
            ];

            return clone $definition;
        }

        return null;
    }

    /**
     * Resolves the cache generation token for a given key.
     */
    protected function resolveCacheGeneration(string $key): string
    {
        return $this->cache->getGeneration($key) . ':' . self::$localSequence;
    }

    /**
     * Invalidate local collection memo entry when its metadata document is purged.
     */
    #[Override]
    protected function purgeCachedDocumentInternal(string $collectionId, ?string $id): bool
    {
        $result = parent::purgeCachedDocumentInternal($collectionId, $id);

        if ($collectionId === self::METADATA && $id !== null) {
            self::$localSequence++;
            self::invalidateCollectionMemo($this->getCollectionMetadataCacheKey($id));
        }

        return $result;
    }

    /**
     * Invalidate local collection memo entry when a collection is purged.
     */
    #[Override]
    public function purgeCachedCollection(string $collection): void
    {
        parent::purgeCachedCollection($collection);

        self::$localSequence++;
        if ($collection === self::METADATA) {
            self::resetCollectionMemo();
        } else {
            self::invalidateCollectionMemo($this->getCollectionMetadataCacheKey($collection));
        }
    }

    /**
     * Invalidate a single memoized collection or all entries.
     */
    public static function invalidateCollectionMemo(?string $key = null): void
    {
        if ($key !== null) {
            unset(self::$collectionMemo[$key]);
        } else {
            self::$collectionMemo = [];
        }
    }

    /**
     * Clear all memoized collection definitions.
     */
    public static function resetCollectionMemo(): void
    {
        self::$collectionMemo = [];
    }

    /**
     * Set the revalidation TTL window in seconds (useful for tests and tuning).
     */
    public static function setRevalidateTtl(float $ttl): void
    {
        self::$revalidateTtl = $ttl;
    }

    /**
     * Get count of currently memoized collection definitions.
     */
    public static function getCollectionMemoCount(): int
    {
        return \count(self::$collectionMemo);
    }
}
