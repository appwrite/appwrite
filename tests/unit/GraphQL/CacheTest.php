<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Cache;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema as GQLSchema;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        $this->cache = new Cache(1);
    }

    /**
     * Create a mock schema with configurable number of types.
     * Size calculation is based on type count (~4KB per type).
     */
    private function createMockSchema(int $typeCount = 1, string $suffix = ''): GQLSchema
    {
        $types = [];
        $queryFields = [];

        for ($i = 0; $i < $typeCount; $i++) {
            $typeName = "Type{$i}{$suffix}";
            $types[$typeName] = new ObjectType([
                'name' => $typeName,
                'fields' => [
                    'id' => ['type' => Type::string()],
                    'name' => ['type' => Type::string()],
                ]
            ]);
            $queryFields["get{$typeName}"] = ['type' => $types[$typeName]];
        }

        if (empty($queryFields)) {
            $queryFields['dummy'] = ['type' => Type::string()];
        }

        return new GQLSchema([
            'query' => new ObjectType([
                'name' => 'Query' . $suffix,
                'fields' => $queryFields
            ]),
            'types' => \array_values($types)
        ]);
    }

    /**
     * Create a large schema with many types (~400KB+).
     */
    private function createLargeSchema(string $suffix = ''): GQLSchema
    {
        return $this->createMockSchema(100, $suffix);
    }

    // ============================================
    // Basic Operations
    // ============================================

    public function testSetAndGet(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->assertSame($schema, $this->cache->get('project1'));
        $this->assertEquals(1, $this->cache->size());
    }

    public function testGetNonExistent(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
    }

    public function testGetAfterRemove(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);
        $this->cache->remove('project1');

        $this->assertNull($this->cache->get('project1'));
    }

    public function testRemoveNonExistent(): void
    {
        // Should not throw
        $this->cache->remove('nonexistent');
        $this->assertEquals(0, $this->cache->size());
    }

    public function testRemoveMultipleTimes(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->cache->remove('project1');
        $this->cache->remove('project1'); // Second remove should be safe
        $this->cache->remove('project1'); // Third remove should be safe

        $this->assertEquals(0, $this->cache->size());
    }

    // ============================================
    // Memory Tracking
    // ============================================

    public function testMemoryTracking(): void
    {
        $this->assertEquals(0, $this->cache->getCurrentBytes());

        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->assertGreaterThan(0, $this->cache->getCurrentBytes());
    }

    public function testMemoryTrackingAccuracy(): void
    {
        $schema1 = $this->createMockSchema(10);
        $schema2 = $this->createMockSchema(50);

        $this->cache->set('project1', $schema1);
        $bytes1 = $this->cache->getCurrentBytes();

        $this->cache->set('project2', $schema2);
        $bytes2 = $this->cache->getCurrentBytes();

        // Larger schema should use more memory
        $this->assertGreaterThan($bytes1, $bytes2);
        $this->assertEquals(2, $this->cache->size());
    }

    public function testMemoryDecreasesOnRemove(): void
    {
        $schema = $this->createMockSchema(100);
        $this->cache->set('project1', $schema);

        $bytesWithSchema = $this->cache->getCurrentBytes();
        $this->assertGreaterThan(0, $bytesWithSchema);

        $this->cache->remove('project1');
        $this->assertEquals(0, $this->cache->getCurrentBytes());
    }

    public function testMemoryDecreasesOnEviction(): void
    {
        $this->cache->setMaxSizeMB(10);

        // Fill cache with enough schemas to exceed 1 MB
        // Each large schema is ~83 KB, so 15+ schemas > 1 MB
        for ($i = 1; $i <= 15; $i++) {
            $this->cache->set("project{$i}", $this->createLargeSchema((string)$i));
        }

        $bytesBefore = $this->cache->getCurrentBytes();
        $sizeBefore = $this->cache->size();

        $this->assertGreaterThan(1024 * 1024, $bytesBefore, 'Cache should exceed 1 MB before eviction');

        // Reduce limit to force eviction
        $this->cache->setMaxSizeMB(1);

        $bytesAfter = $this->cache->getCurrentBytes();
        $sizeAfter = $this->cache->size();

        $this->assertLessThan($bytesBefore, $bytesAfter);
        $this->assertLessThan($sizeBefore, $sizeAfter);
        $this->assertLessThanOrEqual(1024 * 1024, $bytesAfter, 'Cache should be at or under 1 MB after eviction');
    }

    // ============================================
    // LRU Eviction
    // ============================================

    public function testLRUEvictionByMemory(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->cache->set("project{$i}", $this->createLargeSchema());
        }

        $stats = $this->cache->getStats();
        $this->assertLessThanOrEqual(1, $stats['memoryMB']);
    }

    public function testLRUEvictsOldestFirst(): void
    {
        $this->cache->setMaxSizeMB(1);

        // Add schemas
        $this->cache->set('oldest', $this->createLargeSchema('1'));
        $this->cache->set('middle', $this->createLargeSchema('2'));
        $this->cache->set('newest', $this->createLargeSchema('3'));

        // Access middle to make it more recent than oldest
        $this->cache->get('middle');

        // Add more to trigger eviction
        for ($i = 0; $i < 10; $i++) {
            $this->cache->set("extra{$i}", $this->createLargeSchema((string)$i));
        }

        // Oldest should have been evicted first (assuming it wasn't accessed)
        // Middle was accessed so it should survive longer
        $this->assertNull($this->cache->get('oldest'));
    }

    public function testLRUAccessUpdatesTimestamp(): void
    {
        $this->cache->setMaxSizeMB(1);

        $this->cache->set('project1', $this->createLargeSchema('1'));
        $this->cache->set('project2', $this->createLargeSchema('2'));

        // Access project1 repeatedly to keep it fresh
        for ($i = 0; $i < 5; $i++) {
            $this->cache->get('project1');
            $this->cache->set("filler{$i}", $this->createLargeSchema((string)$i));
        }

        // project1 should still exist due to recent access
        // project2 should have been evicted
        $this->assertNotNull($this->cache->get('project1'));
    }

    public function testEvictionWithSingleEntry(): void
    {
        // Set very small cache
        $cache = new Cache(1);

        // Add a schema that's close to the limit
        $cache->set('project1', $this->createLargeSchema('1'));

        // Adding another large schema should evict the first
        $cache->set('project2', $this->createLargeSchema('2'));

        $this->assertEquals(1, $cache->getMaxSizeMB());
        $stats = $cache->getStats();
        $this->assertLessThanOrEqual(1, $stats['memoryMB']);
    }

    // ============================================
    // Dirty Flag
    // ============================================

    public function testDirtyFlag(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->cache->setDirty('project1');
        $this->assertTrue($this->cache->isDirty('project1'));

        $this->assertNull($this->cache->get('project1'));
        $this->assertEquals(0, $this->cache->size());
    }

    public function testSetClearsDirtyFlag(): void
    {
        $this->cache->setDirty('project1');
        $this->assertTrue($this->cache->isDirty('project1'));

        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->assertFalse($this->cache->isDirty('project1'));
        $this->assertNotNull($this->cache->get('project1'));
    }

    public function testDirtyFlagWithoutCacheEntry(): void
    {
        // Mark dirty without ever caching
        $this->cache->setDirty('project1');
        $this->assertTrue($this->cache->isDirty('project1'));

        // Get should return null and clear dirty flag
        $this->assertNull($this->cache->get('project1'));
        $this->assertFalse($this->cache->isDirty('project1'));
    }

    public function testDirtyFlagClearedOnGet(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);
        $this->cache->setDirty('project1');

        // First get clears dirty and removes entry
        $this->assertNull($this->cache->get('project1'));
        $this->assertFalse($this->cache->isDirty('project1'));

        // Second get still returns null but no dirty flag
        $this->assertNull($this->cache->get('project1'));
    }

    public function testMultipleDirtyFlags(): void
    {
        $this->cache->set('project1', $this->createMockSchema());
        $this->cache->set('project2', $this->createMockSchema());
        $this->cache->set('project3', $this->createMockSchema());

        $this->cache->setDirty('project1');
        $this->cache->setDirty('project3');

        $this->assertTrue($this->cache->isDirty('project1'));
        $this->assertFalse($this->cache->isDirty('project2'));
        $this->assertTrue($this->cache->isDirty('project3'));

        // Access dirty entries
        $this->assertNull($this->cache->get('project1'));
        $this->assertNotNull($this->cache->get('project2'));
        $this->assertNull($this->cache->get('project3'));

        $this->assertEquals(1, $this->cache->size());
    }

    public function testDirtyFlagIdempotent(): void
    {
        $this->cache->set('project1', $this->createMockSchema());

        $this->cache->setDirty('project1');
        $this->cache->setDirty('project1');
        $this->cache->setDirty('project1');

        $this->assertTrue($this->cache->isDirty('project1'));
        $this->assertNull($this->cache->get('project1'));
    }

    // ============================================
    // Update/Replace Operations
    // ============================================

    public function testUpdateExistingEntry(): void
    {
        $schema1 = $this->createMockSchema(10);
        $schema2 = $this->createMockSchema(20);

        $this->cache->set('project1', $schema1);
        $bytesAfterFirst = $this->cache->getCurrentBytes();

        $this->cache->set('project1', $schema2);
        $bytesAfterSecond = $this->cache->getCurrentBytes();

        $this->assertEquals(1, $this->cache->size());
        $this->assertSame($schema2, $this->cache->get('project1'));
        $this->assertGreaterThan($bytesAfterFirst, $bytesAfterSecond);
    }

    public function testUpdateWithSmallerSchema(): void
    {
        $schema1 = $this->createMockSchema(100);
        $schema2 = $this->createMockSchema(10);

        $this->cache->set('project1', $schema1);
        $bytesAfterFirst = $this->cache->getCurrentBytes();

        $this->cache->set('project1', $schema2);
        $bytesAfterSecond = $this->cache->getCurrentBytes();

        $this->assertEquals(1, $this->cache->size());
        $this->assertLessThan($bytesAfterFirst, $bytesAfterSecond);
    }

    public function testRapidUpdates(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->cache->set('project1', $this->createMockSchema($i + 1));
        }

        $this->assertEquals(1, $this->cache->size());
        $this->assertNotNull($this->cache->get('project1'));
    }

    // ============================================
    // Clear Operations
    // ============================================

    public function testClear(): void
    {
        $this->cache->set('project1', $this->createMockSchema());
        $this->cache->set('project2', $this->createMockSchema());
        $this->cache->setDirty('project3');

        $this->cache->clear();

        $this->assertEquals(0, $this->cache->size());
        $this->assertEquals(0, $this->cache->getCurrentBytes());
        $this->assertNull($this->cache->get('project1'));
        $this->assertNull($this->cache->get('project2'));
        $this->assertFalse($this->cache->isDirty('project3'));
    }

    public function testClearEmptyCache(): void
    {
        $this->cache->clear();

        $this->assertEquals(0, $this->cache->size());
        $this->assertEquals(0, $this->cache->getCurrentBytes());
    }

    public function testClearAndReuse(): void
    {
        $this->cache->set('project1', $this->createMockSchema());
        $this->cache->clear();

        $schema = $this->createMockSchema();
        $this->cache->set('project1', $schema);

        $this->assertSame($schema, $this->cache->get('project1'));
        $this->assertEquals(1, $this->cache->size());
    }

    // ============================================
    // Stats
    // ============================================

    public function testGetStats(): void
    {
        $this->cache->set('project1', $this->createMockSchema());
        $this->cache->setDirty('project2');

        $stats = $this->cache->getStats();

        $this->assertEquals(1, $stats['schemas']);
        $this->assertArrayHasKey('memoryMB', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['memoryMB']);
        $this->assertEquals(1, $stats['maxMemoryMB']);
        $this->assertEquals(1, $stats['dirty']);
    }

    public function testStatsEmpty(): void
    {
        $stats = $this->cache->getStats();

        $this->assertEquals(0, $stats['schemas']);
        $this->assertEquals(0.0, $stats['memoryMB']);
        $this->assertEquals(0, $stats['dirty']);
    }

    public function testStatsAfterEviction(): void
    {
        $this->cache->setMaxSizeMB(1);

        for ($i = 1; $i <= 20; $i++) {
            $this->cache->set("project{$i}", $this->createLargeSchema());
        }

        $stats = $this->cache->getStats();
        $this->assertLessThanOrEqual(1, $stats['memoryMB']);
        $this->assertLessThan(20, $stats['schemas']);
    }

    // ============================================
    // Size Configuration
    // ============================================

    public function testMaxSizeMBChange(): void
    {
        $this->cache->setMaxSizeMB(10);

        for ($i = 1; $i <= 5; $i++) {
            $this->cache->set("project{$i}", $this->createLargeSchema());
        }

        $this->cache->setMaxSizeMB(1);

        $stats = $this->cache->getStats();
        $this->assertLessThanOrEqual(1, $stats['memoryMB']);
    }

    public function testGetMaxSizeMB(): void
    {
        $this->cache->setMaxSizeMB(50);
        $this->assertEquals(50, $this->cache->getMaxSizeMB());
    }

    public function testMinimumMaxSize(): void
    {
        $this->cache->setMaxSizeMB(0);
        $this->assertEquals(1, $this->cache->getMaxSizeMB());

        $this->cache->setMaxSizeMB(-5);
        $this->assertEquals(1, $this->cache->getMaxSizeMB());
    }

    public function testSetMaxSizeNoChangeSkipsEviction(): void
    {
        $this->cache->setMaxSizeMB(10);
        $this->cache->set('project1', $this->createMockSchema());

        $sizeBefore = $this->cache->size();

        // Same value should not trigger eviction
        $this->cache->setMaxSizeMB(10);

        $this->assertEquals($sizeBefore, $this->cache->size());
    }

    public function testConstructorWithCustomSize(): void
    {
        $cache = new Cache(100);
        $this->assertEquals(100, $cache->getMaxSizeMB());
    }

    public function testConstructorWithZeroSize(): void
    {
        $cache = new Cache(0);
        $this->assertEquals(1, $cache->getMaxSizeMB()); // Minimum is 1
    }

    public function testConstructorWithNegativeSize(): void
    {
        $cache = new Cache(-10);
        $this->assertEquals(1, $cache->getMaxSizeMB()); // Minimum is 1
    }

    // ============================================
    // Multiple Instances
    // ============================================

    public function testMultipleCacheInstances(): void
    {
        $cache1 = new Cache(10);
        $cache2 = new Cache(20);

        $schema = $this->createMockSchema();

        $cache1->set('project1', $schema);
        $cache2->set('project1', $schema);

        $this->assertEquals(1, $cache1->size());
        $this->assertEquals(1, $cache2->size());

        $cache1->remove('project1');
        $this->assertEquals(0, $cache1->size());
        $this->assertEquals(1, $cache2->size());
    }

    public function testInstancesHaveIndependentDirtyFlags(): void
    {
        $cache1 = new Cache(10);
        $cache2 = new Cache(10);

        $cache1->set('project1', $this->createMockSchema());
        $cache2->set('project1', $this->createMockSchema());

        $cache1->setDirty('project1');

        $this->assertTrue($cache1->isDirty('project1'));
        $this->assertFalse($cache2->isDirty('project1'));
    }

    public function testInstancesHaveIndependentMemoryTracking(): void
    {
        $cache1 = new Cache(10);
        $cache2 = new Cache(10);

        $cache1->set('project1', $this->createLargeSchema());

        $this->assertGreaterThan(0, $cache1->getCurrentBytes());
        $this->assertEquals(0, $cache2->getCurrentBytes());
    }

    // ============================================
    // Edge Cases - Project IDs
    // ============================================

    public function testEmptyProjectId(): void
    {
        $schema = $this->createMockSchema();
        $this->cache->set('', $schema);

        $this->assertSame($schema, $this->cache->get(''));
        $this->assertEquals(1, $this->cache->size());
    }

    public function testProjectIdWithSpecialCharacters(): void
    {
        $schema = $this->createMockSchema();

        $specialIds = [
            'project-with-dashes',
            'project_with_underscores',
            'project.with.dots',
            'project:with:colons',
            'project/with/slashes',
            'project@with@at',
            'project#with#hash',
            'project with spaces',
            "project\twith\ttabs",
        ];

        foreach ($specialIds as $id) {
            $this->cache->set($id, $schema);
            $this->assertSame($schema, $this->cache->get($id), "Failed for ID: {$id}");
        }

        $this->assertEquals(count($specialIds), $this->cache->size());
    }

    public function testProjectIdWithUnicode(): void
    {
        $schema = $this->createMockSchema();

        $unicodeIds = [
            'プロジェクト', // Japanese
            '项目',        // Chinese
            'مشروع',       // Arabic
            'проект',      // Russian
            '🚀project',   // Emoji
        ];

        foreach ($unicodeIds as $id) {
            $this->cache->set($id, $schema);
            $this->assertSame($schema, $this->cache->get($id), "Failed for ID: {$id}");
        }
    }

    public function testVeryLongProjectId(): void
    {
        $schema = $this->createMockSchema();
        $longId = str_repeat('a', 10000);

        $this->cache->set($longId, $schema);
        $this->assertSame($schema, $this->cache->get($longId));
    }

    public function testNumericProjectId(): void
    {
        $schema = $this->createMockSchema();

        $this->cache->set('123456', $schema);
        $this->assertSame($schema, $this->cache->get('123456'));
    }

    // ============================================
    // Edge Cases - Stress Tests
    // ============================================

    public function testManySmallSchemas(): void
    {
        $this->cache->setMaxSizeMB(10);

        for ($i = 0; $i < 1000; $i++) {
            $this->cache->set("project{$i}", $this->createMockSchema(1));
        }

        // Should have cached many small schemas
        $this->assertGreaterThan(100, $this->cache->size());
    }

    public function testAlternatingSetAndGet(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $projectId = "project" . ($i % 10);
            $this->cache->set($projectId, $this->createMockSchema($i + 1));
            $this->assertNotNull($this->cache->get($projectId));
        }

        $this->assertGreaterThan(0, $this->cache->size());
        $this->assertLessThanOrEqual(10, $this->cache->size());
    }

    public function testAlternatingDirtyAndSet(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->cache->set('project1', $this->createMockSchema($i + 1));
            $this->cache->setDirty('project1');
            $this->assertNull($this->cache->get('project1'));
        }

        $this->assertEquals(0, $this->cache->size());
    }

    // ============================================
    // Edge Cases - Boundary Conditions
    // ============================================

    public function testSchemaExactlyAtMemoryLimit(): void
    {
        // This is tricky to test exactly, but we can verify behavior
        $cache = new Cache(1); // 1 MB limit

        // Add schemas until we hit the limit
        $added = 0;
        while ($cache->getCurrentBytes() < 1024 * 1024 && $added < 100) {
            $cache->set("project{$added}", $this->createMockSchema(50));
            $added++;
        }

        // Should have evicted some if over limit
        $stats = $cache->getStats();
        $this->assertLessThanOrEqual(1, $stats['memoryMB']);
    }

    public function testEvictionWhenNewSchemaLargerThanLimit(): void
    {
        $cache = new Cache(1); // 1 MB limit

        // Add a small schema first
        $cache->set('small', $this->createMockSchema(10));

        // Try to add a very large schema (should evict small one)
        $largeSchema = $this->createLargeSchema();
        $cache->set('large', $largeSchema);

        // Large schema should be cached (even if close to limit)
        $this->assertNotNull($cache->get('large'));
    }

    // ============================================
    // Regression Tests
    // ============================================

    public function testDirtyFlagDoesNotLeakMemory(): void
    {
        // Set dirty for many non-existent projects
        for ($i = 0; $i < 1000; $i++) {
            $this->cache->setDirty("nonexistent{$i}");
        }

        $stats = $this->cache->getStats();
        $this->assertEquals(1000, $stats['dirty']);

        // Clear should remove all dirty flags
        $this->cache->clear();
        $stats = $this->cache->getStats();
        $this->assertEquals(0, $stats['dirty']);
    }

    public function testRemoveDoesNotAffectOtherEntries(): void
    {
        $schema1 = $this->createMockSchema(10);
        $schema2 = $this->createMockSchema(20);
        $schema3 = $this->createMockSchema(30);

        $this->cache->set('project1', $schema1);
        $this->cache->set('project2', $schema2);
        $this->cache->set('project3', $schema3);

        $this->cache->remove('project2');

        $this->assertSame($schema1, $this->cache->get('project1'));
        $this->assertNull($this->cache->get('project2'));
        $this->assertSame($schema3, $this->cache->get('project3'));
    }

    public function testSetDirtyDoesNotAffectOtherEntries(): void
    {
        $schema1 = $this->createMockSchema();
        $schema2 = $this->createMockSchema();

        $this->cache->set('project1', $schema1);
        $this->cache->set('project2', $schema2);

        $this->cache->setDirty('project1');

        $this->assertNull($this->cache->get('project1'));
        $this->assertSame($schema2, $this->cache->get('project2'));
    }
}
