<?php

namespace Appwrite\GraphQL;

use GraphQL\Type\Schema as GQLSchema;
use Swoole\Lock;

/**
 * LRU Cache for GraphQL Schemas keyed by project ID.
 *
 * Uses a combination of array storage and access tracking to implement
 * least-recently-used eviction when the cache reaches memory capacity.
 *
 * This class is designed to be instantiated once per Swoole worker and
 * registered for reuse across requests. Thread-safe via Swoole mutex locks.
 */
class Cache
{
    /**
     * @var array<string, GQLSchema> Cache storage: projectId => schema
     */
    private array $cache = [];

    /**
     * @var array<string, int> Access timestamps: projectId => nanoseconds (hrtime)
     */
    private array $accessTimes = [];

    /**
     * @var array<string, int> Memory usage per schema: projectId => bytes
     */
    private array $memorySizes = [];

    /**
     * @var int Maximum cache size in bytes
     */
    private int $maxBytes;

    /**
     * @var int Current total memory usage in bytes
     */
    private int $currentBytes = 0;

    /**
     * @var array<string, int> Dirty flags with timestamp: projectId => timestamp
     */
    private array $dirty = [];

    /**
     * @var Lock Swoole mutex lock for thread safety
     */
    private Lock $lock;

    /**
     * @var int Maximum age for dirty flags in seconds (1 hour)
     */
    private const int DIRTY_FLAG_TTL = 3600;

    /**
     * @var int Maximum number of dirty flags to prevent unbounded growth
     */
    private const int MAX_DIRTY_FLAGS = 10000;

    /**
     * Heuristic constants for memory estimation.
     * These are approximations - actual memory usage varies based on resolver closures,
     * description lengths, and type complexity. The values are tuned for relative
     * comparison between schemas rather than absolute accuracy.
     */
    private const int BYTES_PER_TYPE = 2048;  // ~2KB base per type
    private const int BYTES_PER_FIELD = 768;  // ~768 bytes per field (includes resolver overhead estimate)

    /**
     * Create a new cache instance.
     *
     * @param int $maxMB Maximum cache size in megabytes (default: 50)
     */
    public function __construct(int $maxMB = 50)
    {
        $this->maxBytes = \max(1, $maxMB) * 1024 * 1024;
        $this->lock = new Lock(SWOOLE_MUTEX);
    }

    /**
     * Configure the maximum cache size in megabytes.
     *
     * @param int $megabytes Maximum cache size in MB (minimum 1 MB)
     */
    public function setMaxSizeMB(int $megabytes): void
    {
        $bytes = \max(1, $megabytes) * 1024 * 1024;
        if ($this->maxBytes === $bytes) {
            return;
        }
        $this->maxBytes = $bytes;
        $this->evictIfNeeded();
    }

    /**
     * Get the current max size in megabytes.
     */
    public function getMaxSizeMB(): int
    {
        return (int) ($this->maxBytes / 1024 / 1024);
    }

    /**
     * Get the current memory usage in bytes.
     */
    public function getCurrentBytes(): int
    {
        return $this->currentBytes;
    }

    /**
     * Get a schema from cache if it exists and is not dirty.
     * Updates access time on hit.
     */
    public function get(string $projectId): ?GQLSchema
    {
        $this->lock->lock();
        try {
            // Cleanup dirty flags on get as well to prevent unbounded growth
            $this->cleanupDirtyFlags();

            if (isset($this->dirty[$projectId])) {
                unset($this->dirty[$projectId]);
                if (isset($this->cache[$projectId])) {
                    $this->removeInternal($projectId);
                }
                return null;
            }

            if (!isset($this->cache[$projectId])) {
                return null;
            }

            $this->accessTimes[$projectId] = \hrtime(true);

            return $this->cache[$projectId];
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Store a schema in the cache.
     * Evicts least recently used entries if memory limit would be exceeded.
     */
    public function set(string $projectId, GQLSchema $schema): void
    {
        $this->lock->lock();
        try {
            unset($this->dirty[$projectId]);

            $schemaSize = $this->calculateSchemaSize($schema);

            // Reject schemas larger than max cache size
            if ($schemaSize > $this->maxBytes) {
                return;
            }

            // Update existing entry
            if (isset($this->cache[$projectId])) {
                $oldSize = $this->memorySizes[$projectId] ?? 0;
                $this->currentBytes = \max(0, $this->currentBytes - $oldSize) + $schemaSize;

                $this->cache[$projectId] = $schema;
                $this->memorySizes[$projectId] = $schemaSize;
                $this->accessTimes[$projectId] = \hrtime(true);
                return;
            }

            // Evict until we have room for the new schema
            while ($this->currentBytes + $schemaSize > $this->maxBytes && !empty($this->cache)) {
                $this->evictLRU();
            }

            $this->cache[$projectId] = $schema;
            $this->memorySizes[$projectId] = $schemaSize;
            $this->accessTimes[$projectId] = \hrtime(true);
            $this->currentBytes += $schemaSize;

            // Periodically clean up stale dirty flags
            $this->cleanupDirtyFlags();
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Calculate the memory size of a schema in bytes.
     *
     * Uses heuristic estimation for relative sizing in LRU eviction.
     * Actual memory usage varies based on resolver closures, descriptions, and type complexity.
     */
    private function calculateSchemaSize(GQLSchema $schema): int
    {
        $typeMap = $schema->getTypeMap();
        $typeCount = \count($typeMap);
        $fieldCount = 0;

        foreach ($typeMap as $type) {
            if (\method_exists($type, 'getFields')) {
                $fieldCount += \count($type->getFields());
            }
        }

        return ($typeCount * self::BYTES_PER_TYPE) + ($fieldCount * self::BYTES_PER_FIELD);
    }

    /**
     * Mark a project's schema as dirty (needs rebuild).
     */
    public function setDirty(string $projectId): void
    {
        $this->lock->lock();
        try {
            // Enforce maximum dirty flag count to prevent unbounded growth
            if (\count($this->dirty) >= self::MAX_DIRTY_FLAGS && !isset($this->dirty[$projectId])) {
                // Remove oldest dirty flag
                $oldest = \array_key_first($this->dirty);
                unset($this->dirty[$oldest]);
            }
            $this->dirty[$projectId] = \time();
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Check if a project's schema is dirty.
     */
    public function isDirty(string $projectId): bool
    {
        return isset($this->dirty[$projectId]);
    }

    /**
     * Remove a specific project's schema from cache.
     */
    public function remove(string $projectId): void
    {
        $this->lock->lock();
        try {
            $this->removeInternal($projectId);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Internal remove method (without locking - must be called within lock).
     */
    private function removeInternal(string $projectId): void
    {
        if (isset($this->memorySizes[$projectId])) {
            $this->currentBytes = \max(0, $this->currentBytes - $this->memorySizes[$projectId]);
        }

        unset($this->cache[$projectId]);
        unset($this->accessTimes[$projectId]);
        unset($this->memorySizes[$projectId]);
        unset($this->dirty[$projectId]);
    }

    /**
     * Clear all cached schemas.
     */
    public function clear(): void
    {
        $this->lock->lock();
        try {
            $this->cache = [];
            $this->accessTimes = [];
            $this->memorySizes = [];
            $this->dirty = [];
            $this->currentBytes = 0;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * Get current cache size (number of schemas).
     */
    public function size(): int
    {
        return \count($this->cache);
    }

    /**
     * Evict least recently used entries if over memory capacity.
     */
    private function evictIfNeeded(): void
    {
        while ($this->currentBytes > $this->maxBytes && !empty($this->cache)) {
            $this->evictLRU();
        }
    }

    /**
     * Evict the least recently used entry.
     * Must be called within a locked context.
     */
    private function evictLRU(): void
    {
        if (empty($this->accessTimes)) {
            return;
        }

        $lruProject = \array_key_first($this->accessTimes);
        $lruTime = $this->accessTimes[$lruProject];

        foreach ($this->accessTimes as $projectId => $time) {
            if ($time < $lruTime) {
                $lruTime = $time;
                $lruProject = $projectId;
            }
        }

        $this->removeInternal($lruProject);
    }

    /**
     * Remove dirty flags older than TTL to prevent unbounded growth.
     * Only cleans up flags for projects without cached schemas.
     */
    private function cleanupDirtyFlags(): void
    {
        $cutoff = \time() - self::DIRTY_FLAG_TTL;

        foreach ($this->dirty as $projectId => $timestamp) {
            // Only clean up if there's no cached schema - if there is one,
            // the dirty flag must persist until the schema is accessed
            if ($timestamp < $cutoff && !isset($this->cache[$projectId])) {
                unset($this->dirty[$projectId]);
            }
        }
    }

    /**
     * Get cache statistics for monitoring.
     *
     * @return array{schemas: int, memoryMB: float, maxMemoryMB: int, dirty: int}
     */
    public function getStats(): array
    {
        return [
            'schemas' => \count($this->cache),
            'memoryMB' => \round($this->currentBytes / 1024 / 1024, 2),
            'maxMemoryMB' => $this->getMaxSizeMB(),
            'dirty' => \count($this->dirty),
        ];
    }
}
