<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Deployment\Retention;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Execution\Store as ExecutionStore;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Schedule\Source\Functions as FunctionSchedules;
use Executor\Executor;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Appwrite\Messaging\Adapter\Fakes\RecordingPubSub;
use Tests\Unit\Platform\Workers\Fakes\RecordingBuilds;
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
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Distributed;
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

    private string $idle;

    /**
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $canceled;

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

        $this->canceled = new \ArrayObject();

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

    private function mqtt(): Mqtt
    {
        return new Mqtt(new NoTelemetry(), new RecordingPubSub());
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
                    deviceForVideos: $device,
                    deviceForDistribution: $device,
                    telemetry: new NoTelemetry(),
                    mqtt: $this->mqtt(),
                    certificates: $this->createStub(Certificates::class),
                    executor: $this->createStub(Executor::class),
                    executionRetention: '',
                    executionsRetentionCount: 0,
                    deploymentRetention: new Retention(),
                    publisherForDeletes: $this->createStub(DeletePublisher::class),
                    publisherForUsage: $this->createStub(UsagePublisher::class),
                    bus: new Bus(),
                    executionStore: $this->createStub(ExecutionStore::class),
                    queueForRealtime: $this->createStub(Realtime::class),
                    distributionBuilds: new RecordingBuilds($this->canceled),
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
                deviceForVideos: $device,
                deviceForDistribution: $device,
                telemetry: $telemetry,
                mqtt: $this->mqtt(),
                certificates: $certificates,
                executor: $this->createStub(Executor::class),
                executionRetention: '',
                executionsRetentionCount: 0,
                deploymentRetention: new Retention(),
                publisherForDeletes: $this->createStub(DeletePublisher::class),
                publisherForUsage: $this->createStub(UsagePublisher::class),
                bus: new Bus(),
                executionStore: $executionStore,
                queueForRealtime: $this->createStub(Realtime::class),
                distributionBuilds: new RecordingBuilds($this->canceled),
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
            deviceForVideos: $this->createStub(Device::class),
            deviceForDistribution: $this->createStub(Device::class),
            telemetry: new NoTelemetry(),
            mqtt: $this->mqtt(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: DateTime::format(new \DateTime('-14 days')),
            executionsRetentionCount: 0,
            deploymentRetention: new Retention(),
            publisherForDeletes: $this->createStub(DeletePublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
            queueForRealtime: $this->createStub(Realtime::class),
            distributionBuilds: new RecordingBuilds($this->canceled),
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

    public function testIdleUploadKeepsItsRowUntilTheAbortRetriesRunOut(): void
    {
        $collection = $this->createIdleUploads(['failing' => [], 'missing' => []]);

        $device = $this->createMock(Device::class);
        $device->method('abort')->willReturnCallback(fn (string $path, string $uploadId): bool => $uploadId === 'missing'
            ? throw new \Utopia\Storage\Exception\NotFoundException()
            : throw new \Exception('Abort failed'));
        $device->expects($this->once())->method('delete')->with('/uploads/failing', true);

        $this->runMaintenance($device);
        $this->assertTrue($this->database->getDocument($collection, 'missing')->isEmpty(), 'an upload the device no longer has is removed at once');

        $this->runMaintenance($device);
        $failing = $this->database->getDocument($collection, 'failing');
        $this->assertSame(2, $failing->getAttribute('metadata')['abortAttempts']);
        $this->assertSame('failing', $failing->getAttribute('metadata')['uploadId'], 'the upload ID is kept for the next attempt');
        $this->assertSame(\strtotime($this->idle), \strtotime($failing->getUpdatedAt()), 'a failed attempt leaves the row idle');

        $this->runMaintenance($device);
        $this->assertTrue($this->database->getDocument($collection, 'failing')->isEmpty(), 'the last attempt removes the row');
    }

    public function testUploadCleanupStopsOnceItsLeaseIsLost(): void
    {
        if (!\class_exists(\Redis::class)) {
            $this->markTestSkipped('The redis extension is required');
        }

        $collection = $this->createIdleUploads(['failing' => ['abortAttempts' => 2]]);

        $device = $this->createMock(Device::class);
        $device->expects($this->once())->method('abort')->willThrowException(new \Exception('Abort failed'));
        $device->expects($this->never())->method('delete');

        // The lease is held for the abort and lapses before the fallback delete.
        $redis = $this->createStub(\Redis::class);
        $redis->method('eval')->willReturnOnConsecutiveCalls(1, 0);
        $lock = (new Distributed($redis, 'upload'))->adopt('token');

        $this->runMaintenance($device, fn (string $key, int $ttl, callable $callback) => $callback($lock));

        $this->assertFalse($this->database->getDocument($collection, 'failing')->isEmpty(), 'a resumed upload keeps its row and bytes');
    }

    public function testArtifactDeleteRemovesOnlyItsFile(): void
    {
        $directory = $this->createDirectory('artifacts');
        \file_put_contents($directory . '/artifact.aab', 'bundle');
        \file_put_contents($directory . '/other.aab', 'other');
        $artifact = new Document([
            '$id' => 'artifact',
            '$collection' => DELETE_TYPE_DISTRIBUTION_ARTIFACTS,
            'status' => 'ready',
            'path' => $directory . '/artifact.aab',
        ]);

        try {
            // Queue delivery is at least once; a second run finds nothing left to remove.
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->deleteDocument($artifact, new Local($directory));
                $this->assertFileDoesNotExist($directory . '/artifact.aab');
                $this->assertSame('other', \file_get_contents($directory . '/other.aab'), 'another artifact keeps its file');
            }
        } finally {
            (new Local($directory))->delete($directory, true);
        }
    }

    public function testUnfinishedArtifactUploadLeavesNoChunks(): void
    {
        $directory = $this->createDirectory('artifacts');
        \mkdir($directory . '/tmp_artifact.aab');
        \file_put_contents($directory . '/tmp_artifact.aab/artifact.part.1', 'chunk');
        $artifact = new Document([
            '$id' => 'artifact',
            '$collection' => DELETE_TYPE_DISTRIBUTION_ARTIFACTS,
            'status' => 'uploading',
            'path' => $directory . '/artifact.aab',
            'metadata' => [],
        ]);

        try {
            $this->deleteDocument($artifact, new Local($directory));

            $this->assertDirectoryDoesNotExist($directory . '/tmp_artifact.aab', 'the received chunks go with the artifact');
        } finally {
            (new Local($directory))->delete($directory, true);
        }
    }

    public function testArtifactDeleteCancelsItsJobAndStopsItsSchedule(): void
    {
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact');
        $directory = $this->createDirectory('artifacts');
        \file_put_contents($directory . '/artifact.aab', 'unsigned');
        \mkdir($directory . '/signed/artifact', recursive: true);
        \file_put_contents($directory . '/signed/artifact/app.aab', 'signed');
        \mkdir($directory . '/signing/artifact', recursive: true);
        \file_put_contents($directory . '/signing/artifact/1-bundle.json', 'bundle');

        try {
            $this->deleteDocument(new Document([
                '$id' => 'artifact',
                '$collection' => DELETE_TYPE_DISTRIBUTION_ARTIFACTS,
                'status' => 'processing',
                'path' => $directory . '/artifact.aab',
            ]), new Local($directory));

            $this->assertSame(['artifact'], $this->canceled->getArrayCopy(), 'the inspect or sign job is canceled');
            $this->assertSame([false], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact'), 'reconcile stops for the artifact');
            $this->assertFileDoesNotExist($directory . '/artifact.aab');
            $this->assertDirectoryDoesNotExist($directory . '/signed/artifact', 'a sign job\'s output goes too');
            $this->assertDirectoryDoesNotExist($directory . '/signing/artifact', 'a sign job\'s one-time signing bundle goes too');
        } finally {
            (new Local($directory))->delete($directory, true);
        }
    }

    public function testAppDeleteRemovesWhatTheAppOwns(): void
    {
        $this->createCollections(['distributionBuilds', 'distributionLogs', 'distributionArtifacts', 'distributionTargets', 'distributionReleases', 'distributionSubmissions', 'distributionListings', 'variables', 'repositories', 'distributionLeases']);
        $directory = $this->createDirectory('distribution');
        foreach ([['5', ''], ['6', 'other-']] as [$appInternalId, $prefix]) {
            $this->seedDistributionApp($appInternalId, $prefix, $directory);
        }
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'build');
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact');
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'submission');
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'other-submission');

        foreach (['artifact.aab', 'other-artifact.aab', 'builds/build/app.aab', 'signed/artifact/app.aab', 'listings/listing/phone.png', 'listings/other-listing/phone.png'] as $file) {
            @\mkdir(\dirname($directory . '/' . $file), recursive: true);
            \file_put_contents($directory . '/' . $file, $file);
        }

        try {
            $this->deleteDocument(new Document([
                '$id' => 'app',
                '$sequence' => '5',
                '$collection' => DELETE_TYPE_DISTRIBUTION_APPS,
            ]), new Local($directory));

            $rows = [
                'distributionBuilds' => 'other-build',
                'distributionLogs' => 'other-log',
                'distributionLeases' => 'other-lease',
                'distributionArtifacts' => 'other-artifact',
                'distributionTargets' => 'other-target',
                'distributionReleases' => 'other-release',
                'distributionSubmissions' => 'other-submission',
                'distributionListings' => 'other-listing',
                'variables' => 'other-variable',
                'repositories' => 'other-repository',
            ];
            foreach ($rows as $collection => $kept) {
                $remaining = \array_map(fn (Document $document): string => $document->getId(), $this->database->find($collection));
                $this->assertSame([$kept], $remaining, $collection . ' keeps only the other app\'s row');
            }

            $canceled = $this->canceled->getArrayCopy();
            \sort($canceled);
            $this->assertSame(['artifact', 'build'], $canceled, 'every job the app started is canceled');
            $this->assertSame([false], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'build'));
            $this->assertSame([false], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact'));
            $this->assertSame([false], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'submission'));
            $this->assertSame([true], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'other-submission'), 'another app keeps reconciling');

            foreach (['artifact.aab', 'builds/build', 'signed/artifact', 'listings/listing'] as $path) {
                $this->assertFileDoesNotExist($directory . '/' . $path);
            }
            $this->assertFileExists($directory . '/other-artifact.aab');
            $this->assertFileExists($directory . '/listings/other-listing/phone.png');
        } finally {
            (new Local($directory))->delete($directory, true);
        }
    }

    public function testReleaseDeleteKeepsSubmissionsStillInFlight(): void
    {
        $this->createCollections(['distributionSubmissions']);
        foreach (['failed' => ['failed', 1.0], 'reviewing' => ['reviewing', 1.0], 'rolling' => ['published', 0.5], 'published' => ['published', 1.0]] as $submissionId => [$status, $rollout]) {
            $this->createSubmission($submissionId, '5', 'artifact', $status, $rollout);
            $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $submissionId);
        }

        $this->deleteDocument(new Document([
            '$id' => 'release',
            '$sequence' => '7',
            '$collection' => DELETE_TYPE_DISTRIBUTION_RELEASES,
        ]), $this->createStub(Device::class));

        $remaining = \array_map(fn (Document $submission): string => $submission->getId(), $this->database->find('distributionSubmissions'));
        \sort($remaining);
        $this->assertSame(['reviewing', 'rolling'], $remaining, 'only finished submissions go with their release');
        $this->assertSame([false], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'failed'));
        $this->assertSame([true], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'rolling'), 'a staged rollout keeps reconciling');
    }

    /**
     * Stopped Distribution rows used to reach the resource lookup, which knows
     * only functions and messages, so the sweep logged an error and kept them.
     */
    public function testScheduleSweepRemovesStoppedDistributionRows(): void
    {
        $this->addSchedule('project', 'cleanup');
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact', active: false);
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'submission', active: false);
        $this->addDistributionSchedule(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'reviewing');

        (new Deletes())->action(
            message: (new Message())->setPayload((new DeleteMessage(type: DELETE_TYPE_SCHEDULES, datetime: DateTime::now()))->toArray()),
            project: new Document(),
            dbForPlatform: $this->database,
            getProjectDB: fn () => $this->database,
            getDatabasesDB: fn () => $this->database,
            deviceForFiles: $this->createStub(Device::class),
            deviceForFunctions: $this->createStub(Device::class),
            deviceForSites: $this->createStub(Device::class),
            deviceForBuilds: $this->createStub(Device::class),
            deviceForVideos: $this->createStub(Device::class),
            deviceForDistribution: $this->createStub(Device::class),
            telemetry: new NoTelemetry(),
            mqtt: $this->mqtt(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: '',
            executionsRetentionCount: 0,
            deploymentRetention: new Retention(),
            publisherForDeletes: $this->createStub(DeletePublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
            queueForRealtime: $this->createStub(Realtime::class),
            distributionBuilds: new RecordingBuilds($this->canceled),
        );

        $this->assertSame([], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_BUILD, 'artifact'));
        $this->assertSame([], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'submission'));
        $this->assertSame([true], $this->scheduleStates(SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, 'reviewing'), 'a running submission keeps its row');
    }

    public function testMaintenanceRemovesExpiredAndAbandonedArtifacts(): void
    {
        $this->createIdleUploads([]);
        $this->createCollections(['distributionArtifacts', 'distributionSubmissions']);
        $directory = $this->createDirectory('distribution');
        $yesterday = DateTime::format(new \DateTime('-1 day'));
        $artifacts = [
            'expired' => ['status' => 'ready', 'expiresAt' => $yesterday],
            'submitted' => ['status' => 'ready', 'expiresAt' => $yesterday],
            'retained' => ['status' => 'ready', 'expiresAt' => DateTime::format(new \DateTime('+1 day'))],
            'abandoned' => ['status' => 'uploading', '$updatedAt' => DateTime::format(new \DateTime('-8 days'))],
            'resuming' => ['status' => 'uploading'],
        ];
        foreach ($artifacts as $artifactId => $attributes) {
            \file_put_contents($directory . '/' . $artifactId . '.aab', $artifactId);
            $artifact = $this->database->withPreserveDates(true, fn () => $this->database->createDocument('distributionArtifacts', new Document([
                '$id' => $artifactId,
                'appId' => 'app',
                'appInternalId' => '5',
                'type' => 'aab',
                'source' => 'upload',
                'path' => $directory . '/' . $artifactId . '.aab',
                'metadata' => [],
                ...$attributes,
            ])));
            if ($artifactId === 'submitted') {
                $this->createSubmission('reviewing', '5', $artifact->getSequence(), 'reviewing', 1.0);
            }
        }
        $keys = [];
        $locks = function (string $key, int $ttl, callable $callback) use (&$keys): mixed {
            $keys[] = $key;

            return $callback(null);
        };

        try {
            $this->runMaintenance($this->createStub(Device::class), $locks, new Local($directory));

            $remaining = \array_map(fn (Document $artifact): string => $artifact->getId(), $this->database->find('distributionArtifacts'));
            \sort($remaining);
            $this->assertSame(['resuming', 'retained', 'submitted'], $remaining);
            $this->assertFileDoesNotExist($directory . '/expired.aab');
            $this->assertFileDoesNotExist($directory . '/abandoned.aab');
            $this->assertFileExists($directory . '/submitted.aab', 'an artifact a store submission still reads is kept');
            $this->assertSame(['distribution:artifact:project:app:abandoned'], $keys, 'the cleanup takes the upload endpoint\'s lock');
        } finally {
            (new Local($directory))->delete($directory, true);
        }
    }

    public function testInstallationDeleteUnlinksDistributionApps(): void
    {
        $this->createCollections(['sites', 'distributionApps', 'repositories']);
        $this->database->createDocument('repositories', new Document([
            '$id' => 'repository',
            'installationId' => 'installation',
            'projectId' => 'project',
            'projectInternalId' => '1',
            'providerRepositoryId' => 'provider-repository',
            'resourceId' => 'app',
            'resourceType' => RESOURCE_TYPE_DISTRIBUTION_APPS,
        ]));
        $this->database->createDocument('distributionApps', new Document([
            '$id' => 'app',
            'name' => 'App',
            'framework' => 'flutter',
            'installationId' => 'installation',
            'installationInternalId' => '9',
            'providerRepositoryId' => 'provider-repository',
            'repositoryId' => 'repository',
            'repositoryInternalId' => '3',
            'providerBranch' => 'main',
            'providerBranches' => ['release/*'],
            'providerPaths' => ['apps/mobile/**'],
            'providerTags' => ['v*'],
        ]));

        $this->deleteDocument(new Document([
            '$id' => 'installation',
            '$sequence' => '9',
            '$collection' => DELETE_TYPE_INSTALLATIONS,
        ]), $this->createStub(Device::class));

        $app = $this->database->getDocument('distributionApps', 'app');
        $this->assertSame('', $app->getAttribute('installationId'));
        $this->assertSame('', $app->getAttribute('repositoryId'));
        $this->assertSame([], $app->getAttribute('providerBranches'), 'push triggers go with the repository');
        $this->assertSame([], $app->getAttribute('providerPaths'));
        $this->assertSame([], $app->getAttribute('providerTags'));
        $this->assertSame('App', $app->getAttribute('name'), 'the app itself stays');
        $this->assertTrue($this->database->getDocument('repositories', 'repository')->isEmpty());
    }

    /**
     * @param list<string> $ids
     */
    private function createCollections(array $ids): void
    {
        $collections = Config::getParam('collections', []);
        foreach ($ids as $id) {
            $schema = $collections['console'][$id] ?? $collections['projects'][$id];
            $this->database->createCollection(Collection::create(id: $id, attributes: $schema['attributes'], indexes: $schema['indexes']));
        }
    }

    /**
     * One of each row an app owns, every ID starting with the prefix, its artifact file in the directory.
     */
    private function seedDistributionApp(string $appInternalId, string $prefix, string $directory): void
    {
        $owner = ['appId' => $prefix . 'app', 'appInternalId' => $appInternalId];
        $build = $this->database->createDocument('distributionBuilds', new Document([
            '$id' => $prefix . 'build',
            ...$owner,
            'targetId' => 'target',
            'targetInternalId' => '1',
            'platform' => 'android',
            'type' => 'release',
            'providerCommitHash' => 'commit',
        ]));
        $this->database->createDocument('distributionLogs', new Document([
            '$id' => $prefix . 'log',
            'buildId' => $build->getId(),
            'buildInternalId' => $build->getSequence(),
            'sequence' => 1,
            'chunk' => 0,
        ]));
        $this->database->createDocument('distributionLeases', new Document([
            '$id' => $prefix . 'lease',
            'projectId' => 'project',
            'projectInternalId' => '1',
            'buildId' => $build->getId(),
            'platform' => 'android',
            'runnerPlatform' => 'linux',
            'region' => System::getEnv('_APP_REGION', 'default'),
            'endpoint' => 'https://runner.test',
        ]));
        $artifact = $this->database->createDocument('distributionArtifacts', new Document([
            '$id' => $prefix . 'artifact',
            ...$owner,
            'type' => 'aab',
            'source' => 'upload',
            'status' => 'ready',
            'path' => $directory . '/' . $prefix . 'artifact.aab',
        ]));
        $this->database->createDocument('distributionTargets', new Document([
            '$id' => $prefix . 'target',
            ...$owner,
            'platform' => 'android',
            'identifier' => 'io.appwrite.' . $appInternalId,
        ]));
        $this->database->createDocument('distributionReleases', new Document([
            '$id' => $prefix . 'release',
            ...$owner,
            'version' => '1.0.0',
        ]));
        $this->createSubmission($prefix . 'submission', $appInternalId, $artifact->getSequence(), 'published', 1.0);
        $this->database->createDocument('distributionListings', new Document([
            '$id' => $prefix . 'listing',
            ...$owner,
            'platform' => 'android',
            'locale' => 'en-US',
        ]));
        $this->database->createDocument('variables', new Document([
            '$id' => $prefix . 'variable',
            'resourceType' => RESOURCE_TYPE_DISTRIBUTION_APPS,
            'resourceInternalId' => $appInternalId,
            'resourceId' => $prefix . 'app',
            'key' => 'API_URL',
            'value' => 'https://api.test',
        ]));
        $this->database->createDocument('repositories', new Document([
            '$id' => $prefix . 'repository',
            'installationId' => 'installation',
            'projectId' => 'project',
            'projectInternalId' => '1',
            'providerRepositoryId' => 'provider-repository',
            'resourceId' => $prefix . 'app',
            'resourceInternalId' => $appInternalId,
            'resourceType' => RESOURCE_TYPE_DISTRIBUTION_APPS,
        ]));
    }

    private function createSubmission(string $submissionId, string $appInternalId, string $artifactInternalId, string $status, float $rollout): void
    {
        $this->database->createDocument('distributionSubmissions', new Document([
            '$id' => $submissionId,
            'appId' => 'app',
            'appInternalId' => $appInternalId,
            'releaseId' => 'release',
            'releaseInternalId' => '7',
            'targetId' => 'target',
            'targetInternalId' => '1',
            'artifactId' => 'artifact',
            'artifactInternalId' => $artifactInternalId,
            'platform' => 'android',
            'provider' => 'google',
            'track' => 'internal',
            'rollout' => $rollout,
            'status' => $status,
            'key' => $submissionId,
        ]));
    }

    private function addDistributionSchedule(string $type, string $resourceId, bool $active = true): void
    {
        $this->database->createDocument('schedules', new Document([
            'region' => System::getEnv('_APP_REGION', 'default'),
            'resourceType' => $type,
            'resourceId' => $resourceId,
            'resourceInternalId' => '1',
            'resourceUpdatedAt' => DateTime::addSeconds(new \DateTime(), -3600),
            'projectId' => 'project',
            'projectInternalId' => '1',
            'schedule' => '',
            'active' => $active,
        ]));
    }

    /**
     * @return list<bool>
     */
    private function scheduleStates(string $type, string $resourceId): array
    {
        return \array_map(fn (Document $schedule): bool => $schedule->getAttribute('active'), $this->database->find('schedules', [
            Query::equal('resourceType', [$type]),
            Query::equal('resourceId', [$resourceId]),
        ]));
    }

    private function createDirectory(string $prefix): string
    {
        $directory = \sys_get_temp_dir() . '/' . $prefix . '-' . \bin2hex(\random_bytes(8));
        \mkdir($directory);

        return $directory;
    }

    private function deleteDocument(Document $document, Device $deviceForDistribution): void
    {
        (new Deletes())->action(
            message: (new Message())->setPayload((new DeleteMessage(type: DELETE_TYPE_DOCUMENT, document: $document))->toArray()),
            project: new Document(['$id' => 'project', '$sequence' => '1', 'region' => System::getEnv('_APP_REGION', 'default')]),
            dbForPlatform: $this->database,
            getProjectDB: fn () => $this->database,
            getDatabasesDB: fn () => $this->database,
            deviceForFiles: $this->createStub(Device::class),
            deviceForFunctions: $this->createStub(Device::class),
            deviceForSites: $this->createStub(Device::class),
            deviceForBuilds: $this->createStub(Device::class),
            deviceForVideos: $this->createStub(Device::class),
            deviceForDistribution: $deviceForDistribution,
            telemetry: new NoTelemetry(),
            mqtt: $this->mqtt(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: '',
            executionsRetentionCount: 0,
            deploymentRetention: new Retention(),
            publisherForDeletes: $this->createStub(DeletePublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
            queueForRealtime: $this->createStub(Realtime::class),
            distributionBuilds: new RecordingBuilds($this->canceled),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $uploads file ID => extra metadata
     */
    private function createIdleUploads(array $uploads): string
    {
        $collections = Config::getParam('collections', []);
        foreach (['targets', 'sessions', 'tokens', 'challenges', 'transactions', 'transactionLogs', 'presenceLogs', 'pushLedger', 'sites', 'deployments', 'migrations', 'rules', 'videosRenditions', 'videosCaptions'] as $id) {
            $schema = $collections['console'][$id] ?? $collections['projects'][$id];
            $this->database->createCollection(Collection::create(id: $id, attributes: $schema['attributes'], indexes: $schema['indexes']));
        }
        $buckets = $collections['projects']['buckets'];
        $this->database->createCollection(Collection::create(id: 'buckets', attributes: $buckets['attributes'], indexes: $buckets['indexes']));
        $bucket = $this->database->createDocument('buckets', new Document([
            '$id' => 'uploads',
            'name' => 'Uploads',
            'enabled' => true,
            'maximumFileSize' => 0,
            'allowedFileExtensions' => [],
            'compression' => 'none',
            'encryption' => false,
            'antivirus' => false,
        ]));
        $files = $collections['buckets']['files'];
        $collection = 'bucket_' . $bucket->getSequence();
        $this->database->createCollection(Collection::create(id: $collection, attributes: $files['attributes'], indexes: $files['indexes']));

        $this->idle = DateTime::format(new \DateTime('-8 days'));
        foreach ($uploads as $fileId => $metadata) {
            $this->database->withPreserveDates(true, fn () => $this->database->createDocument($collection, new Document([
                '$id' => $fileId,
                '$updatedAt' => $this->idle,
                'bucketId' => 'uploads',
                'bucketInternalId' => $bucket->getSequence(),
                'name' => $fileId,
                'path' => '/uploads/' . $fileId,
                'signature' => '',
                'chunksTotal' => 2,
                'chunksUploaded' => 1,
                'metadata' => ['uploadId' => $fileId, ...$metadata],
            ])));
        }

        return $collection;
    }

    private function runMaintenance(Device $deviceForFiles, ?callable $locks = null, ?Device $deviceForDistribution = null): void
    {
        $project = new Document(['$id' => 'project', '$sequence' => '1', 'region' => System::getEnv('_APP_REGION', 'default')]);
        (new Deletes())->action(
            message: (new Message())->setPayload([
                'type' => DELETE_TYPE_MAINTENANCE,
                'project' => ['$id' => $project->getId(), '$sequence' => $project->getSequence()],
            ]),
            project: $project,
            dbForPlatform: $this->database,
            getProjectDB: fn () => $this->database,
            getDatabasesDB: fn () => $this->database,
            deviceForFiles: $deviceForFiles,
            deviceForFunctions: $this->createStub(Device::class),
            deviceForSites: $this->createStub(Device::class),
            deviceForBuilds: $this->createStub(Device::class),
            deviceForVideos: $this->createStub(Device::class),
            deviceForDistribution: $deviceForDistribution ?? $this->createStub(Device::class),
            telemetry: new NoTelemetry(),
            mqtt: $this->mqtt(),
            certificates: $this->createStub(Certificates::class),
            executor: $this->createStub(Executor::class),
            executionRetention: DateTime::format(new \DateTime('-14 days')),
            executionsRetentionCount: 0,
            deploymentRetention: new Retention(),
            publisherForDeletes: $this->createStub(DeletePublisher::class),
            publisherForUsage: $this->createStub(UsagePublisher::class),
            bus: new Bus(),
            executionStore: $this->createStub(ExecutionStore::class),
            queueForRealtime: $this->createStub(Realtime::class),
            distributionBuilds: new RecordingBuilds($this->canceled),
            locks: $locks,
        );
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
