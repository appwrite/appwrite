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
     * Test that the worker skips stats collection when the project database does not exist.
     * This prevents errors when a project has been deleted but stats messages are still queued.
     */
    public function testSkipsDeletedProjectDatabase(): void
    {
        $worker = new StatsResources();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => '1919',
            'database' => 'mysql://db_fra1c_00',
            'region' => 'fra',
        ]);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('exists')
            ->willReturn(false);
        // count() should never be called if the database doesn't exist
        $dbForProject->expects($this->never())
            ->method('count');

        $dbForLogs = $this->createMock(Database::class);

        $dbForPlatform = $this->createMock(Database::class);
        // Platform DB count should never be called for a deleted project
        $dbForPlatform->expects($this->never())
            ->method('count');

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };

        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        $logErrorCalled = false;
        $logError = function () use (&$logErrorCalled) {
            $logErrorCalled = true;
        };

        // Use reflection to call the protected countForProject method
        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);

        // Set logError on the worker
        $logErrorReflection = new \ReflectionProperty($worker, 'logError');
        $logErrorReflection->setAccessible(true);
        $logErrorReflection->setValue($worker, $logError);

        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);

        $this->assertFalse($logErrorCalled, 'logError should not be called for a deleted project database');
    }

    /**
     * Test that the worker proceeds with stats collection when the project database exists.
     */
    public function testProceedsWhenProjectDatabaseExists(): void
    {
        $worker = new StatsResources();

        $project = new Document([
            '$id' => 'test-project',
            '$sequence' => '1919',
            'database' => 'mysql://db_fra1c_00',
            'region' => 'fra',
        ]);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->expects($this->once())
            ->method('exists')
            ->willReturn(true);
        // count() should be called when database exists
        $dbForProject->expects($this->atLeastOnce())
            ->method('count')
            ->willReturn(0);
        $dbForProject->method('sum')
            ->willReturn(0);
        $dbForProject->method('find')
            ->willReturn([]);
        $dbForProject->method('getDatabase')
            ->willReturn('appwrite');
        $dbForProject->method('getSizeOfCollection')
            ->willReturn(0);

        $dbForLogs = $this->createMock(Database::class);
        $dbForLogs->method('upsertDocuments')
            ->willReturn(0);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('count')
            ->willReturn(0);

        $getProjectDB = function () use ($dbForProject) {
            return $dbForProject;
        };

        $getLogsDB = function () use ($dbForLogs) {
            return $dbForLogs;
        };

        $logErrorCalled = false;
        $logError = function () use (&$logErrorCalled) {
            $logErrorCalled = true;
        };

        $reflection = new \ReflectionMethod($worker, 'countForProject');
        $reflection->setAccessible(true);

        $logErrorReflection = new \ReflectionProperty($worker, 'logError');
        $logErrorReflection->setAccessible(true);
        $logErrorReflection->setValue($worker, $logError);

        $reflection->invoke($worker, $dbForPlatform, $getLogsDB, $getProjectDB, $project);

        $this->assertFalse($logErrorCalled, 'logError should not be called when the database exists');
    }
}
