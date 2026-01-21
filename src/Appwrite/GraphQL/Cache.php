<?php

namespace Appwrite\GraphQL;

use GraphQL\Type\Schema as GQLSchema;
use Swoole\Lock;
use Swoole\Table;

/**
 * LRU Cache for GraphQL Schemas keyed by project ID.
 *
 * Uses a combination of array storage and access tracking to implement
 * least-recently-used eviction when the cache reaches memory capacity.
 *
 * This class is designed to be instantiated once per Swoole worker and
 * registered for reuse across requests. Thread-safe via Swoole mutex locks.
 *
 * Dirty flags are stored in a shared Swoole Table to propagate cache
 * invalidation across all workers.
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
     * @var Table|null Shared Swoole Table for dirty flags (shared across workers)
     */
    private ?Table $dirty;

    /**
     * @var array<string, int> Local dirty flags (used when no shared table available)
     */
    private array $local = [];

    /**
     * @var Lock Swoole mutex lock for thread safety within this worker
     */
    private Lock $lock;

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
     * @param Table|null $dirty Shared Swoole Table for cross-worker dirty flag propagation
     */
    public function __construct(int $maxMB = 50, ?Table $dirty = null)
    {
        $this->maxBytes = \max(1, $maxMB) * 1024 * 1024;
        $this->dirty = $dirty;
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
            if ($this->isDirty($projectId)) {
                $this->clearDirty($projectId);
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
            $this->clearDirty($projectId);

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
     * Uses shared Swoole Table to propagate across all workers when available,
     * otherwise falls back to local array (for single-worker/test scenarios).
     */
    public function setDirty(string $projectId): void
    {
        if ($this->dirty !== null) {
            $this->dirty->set($projectId, ['timestamp' => \time()]);
        } else {
            $this->local[$projectId] = \time();
        }
    }

    /**
     * Check if a project's schema is dirty.
     */
    public function isDirty(string $projectId): bool
    {
        if ($this->dirty !== null) {
            return $this->dirty->exists($projectId);
        }
        return isset($this->local[$projectId]);
    }

    /**
     * Clear a project's dirty flag (from shared table or local).
     */
    private function clearDirty(string $projectId): void
    {
        if ($this->dirty !== null) {
            $this->dirty->del($projectId);
        } else {
            unset($this->local[$projectId]);
        }
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
            $this->local = [];
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
            'dirty' => $this->dirty !== null
                ? $this->dirty->count()
                : \count($this->local),
        ];
    }
}
