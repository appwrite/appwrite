<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Deployment\Retention;
use Appwrite\Deployment\Retention\Reason;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Execution\Store as ExecutionStore;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Schedule\Source\Functions as FunctionSchedules;
use Executor\Executor;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
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
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Method;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
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

    private Database $projectDatabase;

    private string|false $openSslKey;

    protected function setUp(): void
    {
        // Schedules carry an encrypted cursor, so the scheduler needs the key app/init reads.
        $this->openSslKey = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-openssl-key');

        // Workers run with authorization disabled, as app/worker.php sets it up.
        $authorization = new Authorization();
        $authorization->disable();

        $this->database = (new Database(new Memory(), new Cache(new MemoryCache())))
            ->setDatabase('appwrite')
            ->setNamespace('_console')
            ->setAuthorization($authorization);

        $this->database->create();

        $collections = Config::getParam('collections', []);
        foreach (['projects' => $collections['console']['projects'], 'schedules' => $collections['console']['schedules'], 'rules' => $collections['console']['rules'], 'functions' => $collections['projects']['functions']] as $id => $collection) {
            $this->database->createCollection(Collection::create(
                id: $id,
                attributes: $collection['attributes'],
                indexes: $collection['indexes'],
            ));
        }

        $this->projectDatabase = $this->createProjectDatabase(new Database(new Memory(), new Cache(new MemoryCache())));
    }

    protected function tearDown(): void
    {
        \putenv($this->openSslKey === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->openSslKey);
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

    public function testDeploymentsAreKeptForeverWithoutRequestOrPolicy(): void
    {
        $function = $this->createFunction('forever', deploymentRetention: 0, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(400));
        $this->createDeployment($function, 'ancient', '2020-01-01T00:00:00.000+00:00');
        $this->createDeployment($function, 'recent', $this->daysAgo(2));

        $deleted = $this->maintain(new Retention());

        $this->assertSame([], $deleted);
        $this->assertSame(['active', 'ancient', 'recent'], $this->survivors());
    }

    public function testRequestedRetentionSparesActiveAndPinnedDeployments(): void
    {
        $function = $this->createFunction('requested', deploymentRetention: 1, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(10));
        $this->createDeployment($function, 'pinned', $this->daysAgo(9));
        $this->createDeployment($function, 'preview', $this->daysAgo(8));
        $this->createDeployment($function, 'old', $this->daysAgo(5));
        $this->createDeployment($function, 'fresh', $this->daysAgo(0.05));
        $this->createRule($function, 'pinned', 'manual');
        $this->createRule($function, 'preview', 'deployment');

        $deleted = $this->maintain(new Retention());

        $this->assertSame(['old', 'preview'], $deleted);
        $this->assertSame(['active', 'fresh', 'pinned'], $this->survivors());
    }

    public function testPolicyDefaultDeletesDeploymentsPastIt(): void
    {
        $site = $this->createSite('policy', deploymentRetention: 0, deploymentId: 'active');
        $this->createDeployment($site, 'active', $this->daysAgo(60));
        $this->createDeployment($site, 'expired', $this->daysAgo(40));
        $this->createDeployment($site, 'current', $this->daysAgo(20));

        $deleted = $this->maintain(new Retention(days: 30, maxDays: 30));

        $this->assertSame(['expired'], $deleted);
        $this->assertSame(['active', 'current'], $this->survivors());
    }

    public function testPolicyMaximumOverridesALongerRequest(): void
    {
        $function = $this->createFunction('clamped', deploymentRetention: 180, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(100));
        $this->createDeployment($function, 'beyondRequest', $this->daysAgo(200));
        $this->createDeployment($function, 'beyondMaximum', $this->daysAgo(40));
        $this->createDeployment($function, 'withinMaximum', $this->daysAgo(20));

        $deleted = $this->maintain(new Retention(days: 30, maxDays: 30));

        $this->assertSame(['beyondMaximum', 'beyondRequest'], $deleted);
        $this->assertSame(['active', 'withinMaximum'], $this->survivors());
    }

    public function testCountPolicyKeepsTheNewestAlongsideActiveAndPinned(): void
    {
        $function = $this->createFunction('counted', deploymentRetention: 0, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(100));
        $this->createDeployment($function, 'pinned', $this->daysAgo(90));
        $this->createRule($function, 'pinned', 'manual');
        foreach ([1, 2, 3, 4, 5] as $age) {
            $this->createDeployment($function, 'day' . $age, $this->daysAgo($age));
        }

        $deleted = $this->maintain(new Retention(maxCount: 3));

        $this->assertSame(['day4', 'day5'], $deleted);
        $this->assertSame(['active', 'day1', 'day2', 'day3', 'pinned'], $this->survivors());
    }

    public function testCountPolicyBreaksACreatedAtTieByInsertionOrder(): void
    {
        $function = $this->createFunction('tied', deploymentRetention: 0, deploymentId: 'active');
        $tie = $this->daysAgo(3);
        $this->createDeployment($function, 'active', $this->daysAgo(50));
        $this->createDeployment($function, 'newest', $this->daysAgo(1));
        $this->createDeployment($function, 'newer', $this->daysAgo(2));
        $this->createDeployment($function, 'tiedFirst', $tie);
        $this->createDeployment($function, 'tiedSecond', $tie);
        $this->createDeployment($function, 'oldest', $this->daysAgo(4));

        $first = $this->projectDatabase->getDocument('deployments', 'tiedFirst');
        $second = $this->projectDatabase->getDocument('deployments', 'tiedSecond');
        $this->assertSame($first->getCreatedAt(), $second->getCreatedAt(), 'both deployments sit on the count boundary');
        $this->assertLessThan((int) $second->getSequence(), (int) $first->getSequence());

        $deleted = $this->maintain(new Retention(maxCount: 3));

        $this->assertSame(['oldest', 'tiedFirst'], $deleted);
        $this->assertSame(['active', 'newer', 'newest', 'tiedSecond'], $this->survivors());
    }

    /**
     * An override may only log some reasons, so the count pass must not pick up what an age pass already chose:
     * each deployment is handed to the seam for one reason only.
     */
    public function testEachDeploymentIsRetiredForOneReason(): void
    {
        $function = $this->createFunction('reasons', deploymentRetention: 0, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(100));
        foreach ([1, 2, 3, 10] as $age) {
            $this->createDeployment($function, 'day' . $age, $this->daysAgo($age));
        }
        $this->createDeployment($function, 'expired40', $this->daysAgo(40));
        $this->createDeployment($function, 'expired50', $this->daysAgo(50));

        $deletes = new RecordingDeletes();
        $deleted = $this->maintain(new Retention(days: 30, maxDays: 0, maxCount: 2), deletes: $deletes);

        $policy = $deletes->ids(Reason::Policy);
        $count = $deletes->ids(Reason::Count);
        $this->assertSame(['expired40', 'expired50'], $policy);
        $this->assertSame(['day10', 'day3'], $count);
        $this->assertSame([], \array_values(\array_intersect($policy, $count)), 'no deployment is retired for two reasons');
        $this->assertSame([], $deletes->ids(Reason::Requested));
        $this->assertSame(['reasons'], \array_values(\array_unique(\array_column($deletes->calls, 'resource'))));

        // The override logged the policy reason only, so those rows remain and nothing was queued for them.
        $this->assertSame(['day10', 'day3'], $deleted);
        $this->assertSame(\array_values(\array_unique($deleted)), $deleted, 'each deployment is queued once');
        $this->assertSame(['active', 'day1', 'day2', 'expired40', 'expired50'], $this->survivors());
    }

    /**
     * Functions and sites keep their own sequences, so a function and a site can share one; retention on one must
     * not reach the other's deployments.
     */
    public function testFunctionAndSiteSharingASequenceAreRetainedApart(): void
    {
        $function = $this->createFunction('shared', deploymentRetention: 1, deploymentId: 'functionActive');
        $site = $this->createSite('shared', deploymentRetention: 0, deploymentId: 'siteActive');
        $this->assertSame($function->getSequence(), $site->getSequence());

        $this->createDeployment($function, 'functionActive', $this->daysAgo(10));
        $this->createDeployment($function, 'functionOld', $this->daysAgo(5));
        $this->createDeployment($site, 'siteActive', $this->daysAgo(10));
        $this->createDeployment($site, 'siteOld', $this->daysAgo(5));
        $this->createDeployment($site, 'siteOlder', $this->daysAgo(6));

        $deleted = $this->maintain(new Retention());

        $this->assertSame(['functionOld'], $deleted);
        $this->assertSame(['functionActive', 'siteActive', 'siteOld', 'siteOlder'], $this->survivors());
    }

    /**
     * Builds in progress must not crowd out rollback targets: with as many builds queued as the cap allows, the
     * newest finished deployments still survive, and the builds are neither counted nor retired.
     */
    public function testCountPolicyKeepsTheNewestFinishedDeploymentsBesideBuildsInProgress(): void
    {
        $function = $this->createFunction('building', deploymentRetention: 0, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(100));
        $this->createDeployment($function, 'waiting', $this->daysAgo(0.5), status: 'waiting');
        $this->createDeployment($function, 'building', $this->daysAgo(0.6), status: 'building');
        $this->createDeployment($function, 'newest', $this->daysAgo(1));
        $this->createDeployment($function, 'processing', $this->daysAgo(2), status: 'processing');
        $this->createDeployment($function, 'newer', $this->daysAgo(3));
        $this->createDeployment($function, 'beyondCap', $this->daysAgo(4));
        $this->createDeployment($function, 'stuck', $this->daysAgo(5), status: 'building');
        $this->createDeployment($function, 'oldest', $this->daysAgo(6), status: 'failed');

        $deleted = $this->maintain(new Retention(maxCount: 2));

        $this->assertSame(['beyondCap', 'oldest'], $deleted);
        $this->assertSame(['active', 'building', 'newer', 'newest', 'processing', 'stuck', 'waiting'], $this->survivors());
    }

    /**
     * A failed count lookup for one resource is logged; maintenance still caps the resources after it.
     */
    public function testCountLookupFailureLeavesOtherResourcesCapped(): void
    {
        $this->projectDatabase = $this->createProjectDatabase(new CountLookupFailingDatabase(new Memory(), new Cache(new MemoryCache())));
        $function = $this->createFunction('unreadable', deploymentRetention: 0, deploymentId: 'functionActive');
        $site = $this->createSite('readable', deploymentRetention: 0, deploymentId: 'siteActive');
        $this->createDeployment($function, 'functionActive', $this->daysAgo(10));
        $this->createDeployment($function, 'functionOld', $this->daysAgo(5));
        $this->createDeployment($function, 'functionNew', $this->daysAgo(1));
        $this->createDeployment($site, 'siteActive', $this->daysAgo(10));
        $this->createDeployment($site, 'siteOld', $this->daysAgo(5));
        $this->createDeployment($site, 'siteNew', $this->daysAgo(1));

        $deleted = $this->maintain(new Retention(maxCount: 1));

        $this->assertSame(['siteOld'], $deleted);
        $this->assertSame(['functionActive', 'functionNew', 'functionOld', 'siteActive', 'siteNew'], $this->survivors());
    }

    public function testConsoleProjectDeploymentsAreNeverRetired(): void
    {
        $function = $this->createFunction('console', deploymentRetention: 1, deploymentId: 'active');
        $this->createDeployment($function, 'active', $this->daysAgo(10));
        $this->createDeployment($function, 'ancient', '2020-01-01T00:00:00.000+00:00');
        foreach ([1, 2, 3] as $age) {
            $this->createDeployment($function, 'day' . $age, $this->daysAgo($age + 0.5));
        }

        $deleted = $this->maintain(new Retention(days: 1, maxDays: 1, maxCount: 1), new Document(['$id' => 'console', '$sequence' => '1']));

        $this->assertSame([], $deleted);
        $this->assertSame(['active', 'ancient', 'day1', 'day2', 'day3'], $this->survivors());
    }

    /**
     * Runs project maintenance and returns the IDs of the deployments it queued for cleanup.
     *
     * @return list<string>
     */
    private function maintain(Retention $retention, ?Document $project = null, Deletes $deletes = new Deletes()): array
    {
        $publisher = new MockPublisher();
        $device = $this->createStub(Device::class);

        $deletes->action(
            message: (new Message())->setPayload((new DeleteMessage(type: DELETE_TYPE_MAINTENANCE))->toArray()),
            project: $project ?? new Document(['$id' => 'project', '$sequence' => '1']),
            dbForPlatform: $this->database,
            getProjectDB: fn (): Database => $this->projectDatabase,
            getDatabasesDB: fn (): Database => $this->projectDatabase,
            deviceForFiles: $device,
            deviceForFunctions: $device,
            deviceForSites: $device,
            deviceForBuilds: $device,
            telemetry: new NoTelemetry(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: '',
            executionsRetentionCount: 0,
            deploymentRetention: $retention,
            publisherForDeletes: new DeletePublisher($publisher, new Queue('v1-deletes')),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
        );

        $deleted = [];
        foreach ($publisher->getEvents('v1-deletes') ?? [] as $payload) {
            $message = DeleteMessage::fromArray($payload);
            $this->assertSame(DELETE_TYPE_DOCUMENT, $message->type);
            $this->assertSame('deployments', $message->document?->getCollection());
            $deleted[] = $message->document->getId();
        }
        \sort($deleted);

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function survivors(): array
    {
        $ids = \array_map(
            fn (Document $deployment): string => $deployment->getId(),
            $this->projectDatabase->find('deployments', [Query::limit(100)]),
        );
        \sort($ids);

        return $ids;
    }

    private function createProjectDatabase(Database $database): Database
    {
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_1')
            ->setAuthorization($this->database->getAuthorization());

        $database->create();

        // Maintenance runs every cleanup step before it reaches deployments, so each collection they read must exist.
        $collections = Config::getParam('collections', []);
        foreach (['targets', 'subscribers', 'topics', 'sessions', 'tokens', 'challenges', 'transactions', 'transactionLogs', 'presenceLogs', 'pushLedger', 'migrations', 'variables', 'functions', 'sites', 'deployments'] as $id) {
            $database->createCollection(Collection::create(
                id: $id,
                attributes: $collections['projects'][$id]['attributes'],
                indexes: $collections['projects'][$id]['indexes'],
            ));
        }

        return $database;
    }

    private function createFunction(string $id, int $deploymentRetention, string $deploymentId): Document
    {
        return $this->projectDatabase->createDocument('functions', new Document([
            '$id' => $id,
            'name' => $id,
            'runtime' => 'node-22',
            'enabled' => true,
            'live' => true,
            'logging' => true,
            'deploymentRetention' => $deploymentRetention,
            'deploymentId' => $deploymentId,
        ]));
    }

    private function createSite(string $id, int $deploymentRetention, string $deploymentId): Document
    {
        return $this->projectDatabase->createDocument('sites', new Document([
            '$id' => $id,
            'name' => $id,
            'framework' => 'other',
            'buildRuntime' => 'node-22',
            'enabled' => true,
            'live' => true,
            'logging' => true,
            'deploymentRetention' => $deploymentRetention,
            'deploymentId' => $deploymentId,
        ]));
    }

    private function createDeployment(Document $resource, string $id, string $createdAt, string $status = 'ready'): void
    {
        $this->projectDatabase->withPreserveDates(true, fn (): Document => $this->projectDatabase->createDocument('deployments', new Document([
            '$id' => $id,
            '$createdAt' => $createdAt,
            '$updatedAt' => $createdAt,
            'resourceId' => $resource->getId(),
            'resourceInternalId' => $resource->getSequence(),
            'resourceType' => $resource->getCollection(),
            'type' => 'manual',
            'status' => $status,
        ])));
    }

    private function createRule(Document $resource, string $deploymentId, string $trigger): void
    {
        $this->database->createDocument('rules', new Document([
            'projectId' => 'project',
            'projectInternalId' => '1',
            'domain' => $deploymentId . '.' . $resource->getId() . '.example.com',
            'type' => 'deployment',
            'trigger' => $trigger,
            'deploymentResourceType' => $resource->getCollection() === 'sites' ? 'site' : 'function',
            'deploymentResourceId' => $resource->getId(),
            'deploymentResourceInternalId' => $resource->getSequence(),
            'deploymentId' => $deploymentId,
            'status' => 'verified',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]));
    }

    private function daysAgo(float $days): string
    {
        return DateTime::addSeconds(new \DateTime(), (int) (-$days * 86400));
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

/**
 * Records every reason the seam is handed and, like an override that only logs some reasons, retires only the
 * requested and count reasons.
 */
final class RecordingDeletes extends Deletes
{
    /**
     * @var list<array{reason: Reason, ids: list<string>, resource: string}>
     */
    public array $calls = [];

    /**
     * @return list<string>
     */
    public function ids(Reason $reason): array
    {
        $ids = [];
        foreach ($this->calls as $call) {
            if ($call['reason'] === $reason) {
                \array_push($ids, ...$call['ids']);
            }
        }
        \sort($ids);

        return $ids;
    }

    #[\Override]
    protected function retireDeployments(Reason $reason, array $queries, Retention $retention, Document $resource, Database $dbForProject, DeletePublisher $publisherForDeletes, Document $project): void
    {
        $this->calls[] = [
            'reason' => $reason,
            'ids' => \array_map(
                fn (Document $deployment): string => $deployment->getId(),
                $dbForProject->find('deployments', [...$queries, Query::limit(100)]),
            ),
            'resource' => $resource->getId(),
        ];

        if ($reason === Reason::Requested || $reason === Reason::Count) {
            parent::retireDeployments($reason, $queries, $retention, $resource, $dbForProject, $publisherForDeletes, $project);
        }
    }
}

/**
 * Fails the count cap's cutoff lookup for functions, as an engine timeout would, and serves every other read.
 */
final class CountLookupFailingDatabase extends Database
{
    #[\Override]
    public function find(string $collection, array $queries = [], PermissionType $forPermission = PermissionType::Read): array
    {
        $offset = \array_any($queries, fn (Query $query): bool => $query->getMethod() === Method::Offset);
        $functions = \array_any($queries, fn (Query $query): bool => $query->getAttribute() === 'resourceType' && $query->getValues() === ['functions']);
        if ($collection === 'deployments' && $offset && $functions) {
            throw new \RuntimeException('Deployment count cutoff lookup timed out');
        }

        return parent::find($collection, $queries, $forPermission);
    }
}
