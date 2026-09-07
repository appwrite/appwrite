<?php

namespace Tests\E2E\Services\FunctionsSchedule;

use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Execution\Store;
use Appwrite\Platform\Workers\Deletes;
use Appwrite\Schedule\Source\Functions;
use Executor\Executor;
use PHPUnit\Framework\TestCase;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\DSN\DSN;
use Utopia\Queue\Message;
use Utopia\Schedule\Scheduler;
use Utopia\Storage\Device;
use Utopia\System\System;

/** Runs the public scheduler and delete-worker paths against an isolated MySQL database. */
class ScheduleCleanupTest extends TestCase
{
    protected Database $database;
    private \PDO $pdo;
    private string $databaseName;
    private string $region;

    protected function setUp(): void
    {
        $connection = System::getEnv('_APP_CONNECTIONS_DB_CONSOLE', '');
        if ($connection === '') {
            $this->markTestSkipped('Schedule cleanup integration requires _APP_CONNECTIONS_DB_CONSOLE.');
        }
        $dsn = new DSN(str_contains($connection, '=') ? explode('=', $connection, 2)[1] : $connection);
        if (!in_array($dsn->getScheme(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Schedule cleanup integration requires the MySQL console fixture.');
        }
        $this->pdo = new \PDO('mysql:host=' . $dsn->getHost() . ';port=' . ($dsn->getPort() ?? '3306'), $dsn->getUser(), $dsn->getPassword());
        $this->databaseName = 'schedule_cleanup_' . bin2hex(random_bytes(6));
        $this->database = new Database(new MariaDB($this->pdo), new Cache(new None()));
        $this->database->setDatabase($this->databaseName)->setNamespace('test');
        $this->database->getAuthorization()->disable();
        $this->database->create();
        $this->database->createCollection('projects');
        $this->database->createCollection('functions');
        $attributes = [];
        foreach (['projectId', 'resourceId', 'resourceType', 'region', 'schedule'] as $id) {
            $attributes[] = new Document(['$id' => $id, 'type' => Database::VAR_STRING, 'size' => 255, 'required' => true, 'array' => false, 'filters' => []]);
        }
        $attributes[] = new Document(['$id' => 'resourceUpdatedAt', 'type' => Database::VAR_DATETIME, 'size' => 0, 'required' => true, 'array' => false, 'filters' => ['datetime']]);
        $attributes[] = new Document(['$id' => 'active', 'type' => Database::VAR_BOOLEAN, 'size' => 0, 'required' => true, 'array' => false, 'filters' => []]);
        $this->database->createCollection('schedules', $attributes);
        $this->region = System::getEnv('_APP_REGION', 'default');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->delete($this->databaseName);
        }
    }

    public function testSchedulerRemovesOrphanAndReportsItOnlyOnce(): void
    {
        $this->schedule('orphan', 'missing');
        $errors = [];
        $scheduler = new Scheduler(
            source: new Functions($this->database, fn () => $this->database, fn () => false, fn () => 0),
            onError: function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );
        $scheduler->reconcile(true);
        $this->assertTrue($this->database->getDocument('schedules', 'orphan')->isEmpty());
        $scheduler->reconcile(true);
        $this->assertCount(1, $errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $errors[0]);
    }

    public function testMissingProjectIsNotCachedWhenANewScheduleIsCreated(): void
    {
        $this->schedule('orphan', 'restored');
        $source = new Functions($this->database, fn () => $this->database, fn () => false, fn () => 0);
        $scheduler = new Scheduler(source: $source, onError: fn () => null);
        $scheduler->reconcile(true);
        $this->database->createDocument('projects', new Document(['$id' => 'restored']));
        $this->database->createDocument('functions', new Document(['$id' => 'function']));
        $this->schedule('new', 'restored');
        $row = iterator_to_array($source->snapshot())[0];
        $entry = $source->make($row);
        $this->assertSame('restored', $entry->payload['project']->getId());
        $this->assertFalse($this->database->getDocument('schedules', 'new')->isEmpty());
    }

    public function testMaintenanceRemovesActiveAndInactiveOrphansButPreservesLiveSchedules(): void
    {
        $this->database->createDocument('projects', new Document(['$id' => 'live']));
        $this->database->createDocument('functions', new Document(['$id' => 'function']));
        $this->schedule('active-orphan', 'missing');
        $this->schedule('inactive-orphan', 'missing', false);
        $this->schedule('backup-orphan', 'missing', true, 'backup');
        $this->schedule('live-active', 'live');
        $this->schedule('live-inactive', 'live', false);
        $this->schedule('live-backup', 'live', false, 'backup');
        $this->schedule('other-region', 'missing', true, 'function', 'other');
        $this->schedule('recent-orphan', 'missing', true, 'function', null, '2099-01-01 00:00:00.000');
        $this->runWorker(['type' => DELETE_TYPE_SCHEDULES, 'datetime' => '2026-01-01 00:00:00.000']);
        $ids = array_map(fn (Document $row) => $row->getId(), $this->database->find('schedules', [Query::limit(100)]));
        sort($ids);
        $this->assertSame(['live-active', 'live-backup', 'live-inactive', 'other-region', 'recent-orphan'], $ids);
    }

    public function testProjectSchedulesAreRemovedBeforeProjectStorageFailure(): void
    {
        $this->schedule('active', 'deleted');
        $this->schedule('inactive', 'deleted', false);
        $this->schedule('unrelated', 'other');
        $error = null;
        try {
            $this->runWorker(['type' => DELETE_TYPE_DOCUMENT, 'document' => [
                '$id' => 'deleted', '$sequence' => '42', '$collection' => 'projects', 'database' => 'mysql://unavailable',
            ]], fn () => throw new \RuntimeException('Project storage unavailable'));
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        $this->assertNotNull($error);
        $this->assertTrue($this->database->getDocument('schedules', 'active')->isEmpty());
        $this->assertTrue($this->database->getDocument('schedules', 'inactive')->isEmpty());
        $this->assertFalse($this->database->getDocument('schedules', 'unrelated')->isEmpty());
    }

    public function testProjectScheduleDeletionFailurePropagatesBeforeStorageCleanup(): void
    {
        $this->schedule('active', 'deleted');
        $this->pdo->exec("CREATE TRIGGER `{$this->databaseName}`.reject_schedule_delete BEFORE DELETE ON `{$this->databaseName}`.test_schedules FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected schedule delete failure'");
        $storageTouched = false;
        $error = null;
        try {
            $this->runWorker(['type' => DELETE_TYPE_DOCUMENT, 'document' => [
                '$id' => 'deleted', '$sequence' => '42', '$collection' => 'projects', 'database' => 'mysql://unavailable',
            ]], function () use (&$storageTouched): Database {
                $storageTouched = true;
                throw new \RuntimeException('Project storage unavailable');
            });
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        $this->assertNotNull($error);
        $this->assertFalse($storageTouched);
        $this->assertFalse($this->database->getDocument('schedules', 'active')->isEmpty());
        $this->assertStringContainsString('Injected schedule delete failure', $error->getMessage());
    }

    public function testSchedulerRetriesFailedDeletionWithoutLosingTheSchedule(): void
    {
        $this->schedule('orphan', 'missing');
        $this->pdo->exec("CREATE TRIGGER `{$this->databaseName}`.reject_schedule_delete BEFORE DELETE ON `{$this->databaseName}`.test_schedules FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected schedule delete failure'");
        $errors = [];
        $scheduler = new Scheduler(
            source: new Functions($this->database, fn () => $this->database, fn () => false, fn () => 0),
            onError: function (\Throwable $error) use (&$errors): void {
                $errors[] = $error;
            },
        );
        $scheduler->reconcile(true);
        $this->assertFalse($this->database->getDocument('schedules', 'orphan')->isEmpty());
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Injected schedule delete failure', $errors[0]->getMessage());
        $this->pdo->exec("DROP TRIGGER `{$this->databaseName}`.reject_schedule_delete");
        $scheduler->reconcile(true);
        $this->assertTrue($this->database->getDocument('schedules', 'orphan')->isEmpty());
        $this->assertCount(2, $errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $errors[1]);
        $scheduler->reconcile(true);
        $this->assertCount(2, $errors);
    }

    private function schedule(string $id, string $project, bool $active = true, string $type = 'function', ?string $region = null, string $updated = '2025-01-01 00:00:00.000'): void
    {
        $this->database->createDocument('schedules', new Document([
            '$id' => $id, 'projectId' => $project, 'resourceId' => 'function', 'resourceType' => $type,
            'resourceUpdatedAt' => $updated, 'active' => $active, 'region' => $region ?? $this->region, 'schedule' => '* * * * *',
        ]));
    }

    private function executionStore(?callable $getProjectDB): Store
    {
        $store = $this->createStub(Store::class);
        if ($getProjectDB !== null) {
            $store->method('deleteProject')->willReturnCallback($getProjectDB);
        }
        return $store;
    }

    protected function runWorker(array $payload, ?callable $getProjectDB = null): void
    {
        $message = (new Message())->setPayload($payload);
        $project = new Document(['$id' => 'console']);
        $device = $this->createStub(Device::class);
        (new Deletes())->action(
            $message,
            $project,
            $this->database,
            $getProjectDB ?? fn () => $this->database,
            fn () => $this->database,
            fn () => $this->database,
            $device,
            $device,
            $device,
            $device,
            $device,
            $this->createStub(Provider::class),
            $this->createStub(Executor::class),
            '1440',
            100,
            $this->createStub(DeletePublisher::class),
            $this->createStub(UsagePublisher::class),
            new Bus(),
            $this->executionStore($getProjectDB),
        );
    }
}
