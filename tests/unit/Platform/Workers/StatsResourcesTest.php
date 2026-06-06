<?php

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\StatsResources;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
class StatsResourcesTest extends TestCase
{
    /**
     * Test that countForProject skips projects with uninitialized databases
     * (where _metadata table doesn't exist).
     *
     * This reproduces Sentry CLOUD-STAGING-6AS: "Table 'appwrite._2040__metadata' doesn't exist"
     */
    public function testSkipsUninitializedProjectDatabase(): void
    {
        $worker = new StatsResources();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 2040,
            'database' => 'appwrite',
            'region' => 'fra',
        ]);

        $dbForPlatform = $this->createMock(Database::class);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDatabase')->willReturn('appwrite');
        // Simulate _metadata table not existing
        $dbForProject->method('exists')->with('appwrite', '_metadata')->willReturn(false);
        // count() should NEVER be called if the database is not initialized
        $dbForProject->expects($this->never())->method('count');

        $dbForLogs = $this->createMock(Database::class);
        // upsertDocuments should NEVER be called for uninitialized databases
        $dbForLogs->expects($this->never())->method('upsertDocuments');

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };
        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        // This should NOT throw a PDOException about missing _metadata table
        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);
        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that countForProject proceeds normally when the database is initialized.
     */
    public function testProceedsWithInitializedProjectDatabase(): void
    {
        $worker = new StatsResources();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => 2040,
            'database' => 'appwrite',
            'region' => 'fra',
        ]);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('count')->willReturn(0);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDatabase')->willReturn('appwrite');
        // Database is initialized - _metadata table exists
        $dbForProject->method('exists')->with('appwrite', '_metadata')->willReturn(true);
        // count() SHOULD be called when database is initialized
        $dbForProject->expects($this->atLeastOnce())->method('count')->willReturn(0);
        $dbForProject->method('sum')->willReturn(0);
        $dbForProject->method('find')->willReturn([]);
        $dbForProject->method('getSizeOfCollection')->willReturn(0);

        $dbForLogs = $this->createMock(Database::class);
        $dbForLogs->expects($this->once())->method('upsertDocuments');

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };
        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        $logError = function () {};

        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);

        // Need to set logError
        $logErrorProp = new \ReflectionProperty($worker, 'logError');
        $logErrorProp->setAccessible(true);
        $logErrorProp->setValue($worker, $logError);

        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);

        // Assertions are in the mock expectations above
        $this->assertTrue(true);
    }
}
