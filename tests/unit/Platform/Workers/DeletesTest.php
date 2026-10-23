<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Deployment\Retention;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Execution\Store as ExecutionStore;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Schedule\Source\Functions as FunctionSchedules;
use Executor\Executor;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider as Certificates;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Queue\Message;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    private Database $database;

    private string|false $key;

    protected function setUp(): void
    {
        // Projects default smtp and oAuthProviders through the encrypt filter on every write and read.
        $this->key = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

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

    protected function tearDown(): void
    {
        \putenv($this->key === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->key);
    }

    public function testQueuedFileCleanupOnlyDeletesTheObsoletePath(): void
    {
        $directory = sys_get_temp_dir() . '/s3-version-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $oldPath = $directory . '/old';
        $currentPath = $directory . '/replacement';
        file_put_contents($oldPath, 'original');
        file_put_contents($currentPath, 'replacement');
        $device = new Local($directory);
        $payload = (new DeleteMessage(type: DELETE_TYPE_FILES, resource: $oldPath))->toArray();
        $message = (new Message())->setPayload($payload);
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
                    telemetry: new NoTelemetry(),
                    certificates: $this->createStub(Certificates::class),
                    executor: $this->createStub(Executor::class),
                    executionRetention: '',
                    executionsRetentionCount: 0,
                    deploymentRetention: new Retention(),
                    publisherForDeletes: $this->createStub(DeletePublisher::class),
                    publisherForUsage: $this->createStub(UsagePublisher::class),
                    bus: new Bus(),
                    executionStore: $this->createStub(ExecutionStore::class),
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
     * The project row is gone by the time the worker runs, so a message whose
     * document lost its ID must not reach getProjectDB() or the project-ID
     * queries for an empty project, nor build a device rooted at 'app-'.
     */
    public function testProjectDeleteWithoutAnIdTouchesNothing(): void
    {
        $payload = (new DeleteMessage(
            type: DELETE_TYPE_DOCUMENT,
            document: new Document(['$collection' => DELETE_TYPE_PROJECTS, '$sequence' => '7', 'name' => 'nameless']),
        ))->toArray();
        $message = (new Message())->setPayload($payload);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->expects($this->never())->method($this->anything());
        $device = $this->createMock(Device::class);
        $device->expects($this->never())->method($this->anything());
        $telemetry = $this->createMock(Telemetry::class);
        $telemetry->expects($this->never())->method('createHistogram');
        $certificates = $this->createMock(Certificates::class);
        $certificates->expects($this->never())->method($this->anything());
        $executionStore = $this->createMock(ExecutionStore::class);
        $executionStore->expects($this->never())->method($this->anything());
        $connections = 0;
        $record = function () use (&$connections): Database {
            $connections++;

            return $this->database;
        };

        $refusal = null;
        try {
            (new Deletes())->action(
                message: $message,
                project: new Document(),
                dbForPlatform: $dbForPlatform,
                getProjectDB: $record,
                getDatabasesDB: $record,
                deviceForFiles: $device,
                deviceForFunctions: $device,
                deviceForSites: $device,
                deviceForBuilds: $device,
                telemetry: $telemetry,
                certificates: $certificates,
                executor: $this->createStub(Executor::class),
                executionRetention: '',
                executionsRetentionCount: 0,
                deploymentRetention: new Retention(),
                publisherForDeletes: $this->createStub(DeletePublisher::class),
                publisherForUsage: $this->createStub(UsagePublisher::class),
                bus: new Bus(),
                executionStore: $executionStore,
            );
        } catch (AssertionFailedError $failure) {
            // An unexpected call on a double is the failure under test, not the refusal.
            throw $failure;
        } catch (\Exception $error) {
            $refusal = $error;
        }

        $this->assertSame('Missing project ID', $refusal?->getMessage(), 'a project delete without a project ID must not run');
        $this->assertSame(0, $connections, 'no project or databases connection is opened');
    }

    /**
     * Maintenance jobs can outlive their project: the worker reloads the project by
     * the payload's ID as an empty Document, and getProjectDB() maps an empty
     * project to the platform database, so the project cleanups would run there.
     */
    public function testMaintenanceForDeletedProjectLeavesPlatformDatabaseUntouched(): void
    {
        $collections = Config::getParam('collections', []);
        foreach (['targets', 'sessions', 'tokens', 'challenges', 'transactions', 'transactionLogs', 'presenceLogs', 'sites', 'deployments', 'migrations', 'rules'] as $id) {
            $collection = $collections['console'][$id] ?? $collections['projects'][$id];
            $this->database->createCollection(Collection::create(
                id: $id,
                attributes: $collection['attributes'],
                indexes: $collection['indexes'],
            ));
        }

        $this->database->withPreserveDates(true, fn () => $this->database->skipFilters(fn () => $this->database->createDocument('sessions', new Document([
            '$id' => 'platform-session',
            '$createdAt' => DateTime::format(new \DateTime('-2 years')),
            'userInternalId' => '1',
            'expire' => DateTime::format(new \DateTime('-1 year')),
        ]))));

        $payload = [
            'type' => DELETE_TYPE_MAINTENANCE,
            'project' => ['$id' => 'deleted', '$sequence' => '42'],
        ];
        $message = (new Message())->setPayload($payload);
        $getProjectDB = fn (Document $project): Database => $project->isEmpty()
            ? $this->database
            : throw new \LogicException('No project database for ' . $project->getId());

        (new Deletes())->action(
            message: $message,
            project: new Document(),
            dbForPlatform: $this->database,
            getProjectDB: $getProjectDB,
            getDatabasesDB: $getProjectDB,
            deviceForFiles: $this->createStub(Device::class),
            deviceForFunctions: $this->createStub(Device::class),
            deviceForSites: $this->createStub(Device::class),
            deviceForBuilds: $this->createStub(Device::class),
            telemetry: new NoTelemetry(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: DateTime::format(new \DateTime('-14 days')),
            executionsRetentionCount: 0,
            deploymentRetention: new Retention(),
            publisherForDeletes: $this->createStub(DeletePublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
        );

        $session = $this->database->skipFilters(fn () => $this->database->getDocument('sessions', 'platform-session'));
        $this->assertSame('platform-session', $session->getId(), 'the platform database keeps its expired session');
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
