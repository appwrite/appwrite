<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsResources;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound;

class StatsResourcesTest extends TestCase
{
    /**
     * Test that countForCollections gracefully handles a deleted collection
     * (Collection not found) without throwing an exception.
     *
     * This reproduces the Sentry error CLOUD-STAGING-6NG where a collection
     * is deleted between listing and counting.
     */
    public function testCountForCollectionsHandlesDeletedCollection(): void
    {
        $worker = $this->getMockBuilder(StatsResources::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Set up the logError callback
        $reflection = new \ReflectionClass($worker);
        $logErrorProp = $reflection->getProperty('logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($worker, function () {});

        $database = $this->createMock(Database::class);

        // Simulate foreachDocument finding one collection
        $collection = new Document([
            '$id' => 'col1',
            '$internalId' => '100',
            '$collection' => 'database_1',
        ]);

        // The database will return the collection when listing, but throw NotFound when counting
        $database->method('find')
            ->willReturn([$collection]);

        $database->method('count')
            ->willThrowException(new NotFound('Collection not found'));

        $database->method('getSizeOfCollection')
            ->willThrowException(new NotFound('Collection not found'));

        $databaseDoc = new Document([
            '$id' => 'db1',
            '$internalId' => '1',
        ]);

        // Call countForCollections via reflection
        $method = $reflection->getMethod('countForCollections');
        $method->setAccessible(true);

        // Should NOT throw - should handle the NotFound gracefully
        $result = $method->invoke($worker, $database, $databaseDoc, 'default');

        // Should return [0, 0] since the collection was not found
        $this->assertIsArray($result);
        $this->assertEquals(0, $result[0], 'Documents count should be 0 for deleted collection');
        $this->assertEquals(0, $result[1], 'Storage should be 0 for deleted collection');
    }

    /**
     * Test that countForBuckets gracefully handles a deleted bucket.
     */
    public function testCountForBucketsHandlesDeletedBucket(): void
    {
        $worker = $this->getMockBuilder(StatsResources::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $reflection = new \ReflectionClass($worker);
        $logErrorProp = $reflection->getProperty('logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($worker, function () {});

        $dbForProject = $this->createMock(Database::class);
        $dbForLogs = $this->createMock(Database::class);

        $bucket = new Document([
            '$id' => 'bucket1',
            '$internalId' => '200',
            '$collection' => 'buckets',
        ]);

        $dbForProject->method('find')
            ->willReturn([$bucket]);

        $dbForProject->method('count')
            ->willThrowException(new NotFound('Collection not found'));

        $dbForProject->method('sum')
            ->willThrowException(new NotFound('Collection not found'));

        $method = $reflection->getMethod('countForBuckets');
        $method->setAccessible(true);

        // Should NOT throw
        $method->invoke($worker, $dbForProject, $dbForLogs, 'default');

        // If we got here without exception, the test passes
        $this->assertTrue(true);
    }
}
