<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsResources;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Queue\Message;

class StatsResourcesTest extends TestCase
{
    /**
     * Test that countForProject skips projects with uninitialized databases
     * (missing _metadata table) without throwing an error.
     *
     * This reproduces the Sentry error:
     * "Table 'appwrite._2040__metadata' doesn't exist"
     */
    public function testCountForProjectSkipsUninitializedDatabase(): void
    {
        $worker = new StatsResources();

        $dbForPlatform = $this->createMock(Database::class);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDatabase')->willReturn('appwrite');
        $dbForProject->method('exists')
            ->with('appwrite', '_metadata')
            ->willReturn(false);

        // If exists() returns false, count() should never be called
        $dbForProject->expects($this->never())->method('count');

        $dbForLogs = $this->createMock(Database::class);

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };
        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 2040,
            'database' => 'appwrite',
            'region' => 'fra',
        ]);

        $logErrorCalled = false;
        $logError = function () use (&$logErrorCalled) {
            $logErrorCalled = true;
        };

        // Use reflection to call protected method
        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);

        // Set logError property
        $logErrorProp = new \ReflectionProperty($worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($worker, $logError);

        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);

        // logError should NOT have been called since we skip gracefully
        $this->assertFalse($logErrorCalled, 'logError should not be called for uninitialized project databases');
    }

    /**
     * Test that countForProject proceeds normally when _metadata table exists.
     */
    public function testCountForProjectProceedsWhenDatabaseInitialized(): void
    {
        $worker = new StatsResources();

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('count')->willReturn(0);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDatabase')->willReturn('appwrite');
        $dbForProject->method('exists')
            ->with('appwrite', '_metadata')
            ->willReturn(true);
        $dbForProject->method('count')->willReturn(0);
        $dbForProject->method('sum')->willReturn(0);
        $dbForProject->method('getNamespace')->willReturn('_2040');
        $dbForProject->method('find')->willReturn([]);

        $dbForLogs = $this->createMock(Database::class);
        $dbForLogs->method('upsertDocuments')->willReturn(0);

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };
        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 2040,
            'database' => 'appwrite',
            'region' => 'fra',
        ]);

        $logError = function () {
            // Accept errors from sub-operations, we just want to verify count() is called
        };

        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);

        $logErrorProp = new \ReflectionProperty($worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($worker, $logError);

        // count() should be called on dbForProject when _metadata exists
        $dbForProject->expects($this->atLeastOnce())->method('count');

        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);
    }
}
