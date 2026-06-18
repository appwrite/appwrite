<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsUsage;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

class StatsUsageTest extends TestCase
{
    /**
     * Test that reduce() handles pool-not-found errors gracefully
     * instead of throwing unhandled exceptions.
     *
     * This test reproduces the Sentry error:
     * "Pool 'database_db_syd1_self_hosted_0_0' not found"
     *
     * The getProjectDB callable throws when a pool doesn't exist for the
     * project's database. The reduce() method must catch this error
     * to prevent flooding Sentry with unhandled exceptions.
     */
    public function testReduceHandlesPoolNotFound(): void
    {
        $statsUsage = new StatsUsage();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 1,
            'database' => 'mysql://database_db_syd1_self_hosted_0_0/appwrite',
        ]);

        $document = new Document([
            '$collection' => 'databases',
            '$id' => 'test-db',
            '$sequence' => 1,
        ]);

        $metrics = [];

        // Simulate getProjectDB throwing "Pool not found"
        $getProjectDB = function (Document $project) {
            throw new \Exception("Pool 'database_db_syd1_self_hosted_0_0' not found");
        };

        // Use reflection to call the protected reduce method
        $reflection = new \ReflectionMethod($statsUsage, 'reduce');
        $reflection->setAccessible(true);

        // This should NOT throw - the error should be caught internally
        $reflection->invoke($statsUsage, $project, $document, $metrics, $getProjectDB);

        // If we get here, the exception was handled gracefully
        $this->assertTrue(true, 'reduce() should handle pool-not-found errors without throwing');
    }

    /**
     * Test that reduce() still works correctly when getProjectDB succeeds
     * but the database operation fails.
     */
    public function testReduceHandlesDatabaseErrors(): void
    {
        $statsUsage = new StatsUsage();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 1,
            'database' => 'mysql://database_db_main/appwrite',
        ]);

        $document = new Document([
            '$collection' => 'unknown_collection',
            '$id' => 'test-doc',
            '$sequence' => 1,
        ]);

        $metrics = [];

        // Simulate getProjectDB succeeding but returning a mock
        // that will fail on getDocument calls (default case in switch)
        $mockDb = $this->createMock(\Utopia\Database\Database::class);
        $getProjectDB = function (Document $project) use ($mockDb) {
            return $mockDb;
        };

        $reflection = new \ReflectionMethod($statsUsage, 'reduce');
        $reflection->setAccessible(true);

        // Should not throw for unknown collection types (hits default case)
        $reflection->invoke($statsUsage, $project, $document, $metrics, $getProjectDB);

        $this->assertTrue(true, 'reduce() should handle unknown collection types gracefully');
    }
}
