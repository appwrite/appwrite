<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Platform\Workers\Deletes;
use Appwrite\Schedule\Source\Functions as FunctionSchedules;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\System\System;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        // Workers run with authorization disabled, as app/worker.php sets it up.
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_console')
            ->setAuthorization($authorization);

        $this->database->create();

        $collections = Config::getParam('collections', []);
        foreach (['projects' => $collections['console']['projects'], 'schedules' => $collections['console']['schedules'], 'functions' => $collections['projects']['functions']] as $id => $collection) {
            $this->database->createCollection(Collection::create(
                id: $id,
                attributes: $collection['attributes'],
                indexes: $collection['indexes'],
            ));
        }
    }

    public function testQueuedFileCleanupOnlyDeletesTheObsoletePath(): void
    {
        $directory = sys_get_temp_dir() . '/s3-version-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $oldPath = $directory . '/old';
        $currentPath = $directory . '/replacement';
        file_put_contents($oldPath, 'original');
        file_put_contents($currentPath, 'replacement');
        $device = new \Utopia\Storage\Device\Local($directory);
        $payload = (new \Appwrite\Event\Message\Delete(type: DELETE_TYPE_FILES, resource: $oldPath))->toArray();
        $message = (new \Utopia\Queue\Message())->setPayload($payload);
        try {
            // Queue delivery is at least once; reclaiming an already-removed path is safe.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                (new Deletes())->action(
                    message: $message,
                    project: new Document(['$id' => 'project']),
                    dbForPlatform: $this->database,
                    getProjectDB: fn () => $this->database,
                    getDatabasesDB: fn () => $this->database,
                    deviceForFiles: $device,
                    deviceForFunctions: $device,
                    deviceForSites: $device,
                    deviceForBuilds: $device,
                    deviceForCache: $device,
                    certificates: $this->createStub(\Utopia\Cdn\Certificates\Provider::class),
                    executor: $this->createStub(\Executor\Executor::class),
                    executionRetention: '',
                    executionsRetentionCount: 0,
                    publisherForDeletes: $this->createStub(\Appwrite\Event\Publisher\Delete::class),
                    publisherForUsage: $this->createStub(\Appwrite\Event\Publisher\Usage::class),
                    bus: new \Utopia\Bus\Bus(),
                    executionStore: $this->createStub(\Appwrite\Execution\Store::class),
                );
                $this->assertFileDoesNotExist($oldPath);
                $this->assertSame('replacement', file_get_contents($currentPath));
            }
        } finally {
            if (file_exists($oldPath)) {
                unlink($oldPath);
            }
            unlink($currentPath);
            rmdir($directory);
        }
    }

    /**
     * The scheduler keeps every schedule in memory and syncs from the rows whose
     * resourceUpdatedAt moved since its last read. A row removed outright never
     * moves, so a deleted project's functions kept firing every minute until
     * the next full snapshot, into a worker that could no longer find the
     * project. Deleting the project has to reach the scheduler on its next
     * incremental sync.
     */
    public function testDeletedProjectSchedulesStopFiringOnTheNextSync(): void
    {
        $this->addSchedule('deleted', 'cleanup');
        $this->addSchedule('deleted', 'report');
        $this->addSchedule('kept', 'digest');

        $clock = new TestClock(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $scheduler = new Scheduler(
            source: new FunctionSchedules(
                $this->database,
                fn (Document $project): Database => $this->database,
                fn (): bool => false,
                fn (array $schedule): int => 0,
            ),
            clock: $clock,
        );

        $scheduler->reconcile(full: true);
        $this->assertSame(3, $scheduler->count());

        // The API removes the project, then the deletes worker cleans up after it.
        $this->database->skipFilters(fn () => $this->database->deleteDocument('projects', 'deleted'));
        (new TestDeletes())->stopSchedules($this->database, 'deleted');

        $clock->advance(10);
        $scheduler->reconcile();

        $this->assertSame(1, $scheduler->count(), 'only the schedule of the project that still exists may fire');
    }

    private function addSchedule(string $projectId, string $functionId): void
    {
        // Projects and functions carry subquery filters into collections this
        // test has no use for; the schedule source reads them unfiltered too.
        $this->database->skipFilters(function () use ($projectId, $functionId): void {
            if ($this->database->getDocument('projects', $projectId)->isEmpty()) {
                $this->database->createDocument('projects', new Document([
                    '$id' => $projectId,
                    'name' => $projectId,
                    'region' => System::getEnv('_APP_REGION', 'default'),
                    'teamInternalId' => '1',
                    'database' => 'memory',
                ]));
            }

            $this->database->createDocument('functions', new Document([
                '$id' => $functionId,
                'name' => $functionId,
                'runtime' => 'node-22',
                'schedule' => '* * * * *',
                'enabled' => true,
                'live' => true,
                'logging' => true,
            ]));
        });

        $this->database->createDocument('schedules', new Document([
            'region' => System::getEnv('_APP_REGION', 'default'),
            'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION,
            'resourceId' => $functionId,
            'resourceInternalId' => '1',
            'resourceUpdatedAt' => DateTime::addSeconds(new \DateTime(), -3600),
            'projectId' => $projectId,
            'projectInternalId' => '1',
            'schedule' => '* * * * *',
            'active' => true,
        ]));
    }
}

final class TestDeletes extends Deletes
{
    public function stopSchedules(Database $dbForPlatform, string $projectId): void
    {
        $this->deactivateSchedules($dbForPlatform, $projectId);
    }
}
