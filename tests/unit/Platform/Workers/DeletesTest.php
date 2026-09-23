<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Execution\Store;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Execution\CapturingClient;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    public function testMaintenanceMakesStaleProcessingAttemptRetryable(): void
    {
        $database = $this->createDatabase();
        $migration = $this->createMigration($database, 'migration-1', 'processing', Deletes::PROCESSING_STUCK_RETENTION_SECONDS + 1);
        $project = $this->project();
        $publisher = new MockPublisher();

        $this->maintain($database, $this->sweeper());

        $terminal = $database->getDocument('migrations', $migration->getId());
        $this->assertSame('failed', $terminal->getAttribute('status'));
        $this->assertSame('finished', $terminal->getAttribute('stage'));
        $this->assertSame('attempt-1', $terminal->getAttribute('attemptId'));

        $retried = (new Claim(
            $database,
            static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
        ))->retry(
            project: $project,
            migrationId: $migration->getId(),
            platform: [],
            publisher: new MigrationPublisher($publisher, new Queue('migrations')),
        );

        $this->assertSame('pending', $retried->getAttribute('status'));
        $this->assertSame('finished', $retried->getAttribute('stage'));
        $this->assertNotSame('attempt-1', $retried->getAttribute('attemptId'));
        $queued = MigrationMessage::fromArray($publisher->getEvents('migrations')[0]);
        $this->assertInstanceOf(Document::class, $queued->terminal);
        $this->assertSame('attempt-1', $queued->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $queued->terminal->getAttribute('status'));
        $this->assertSame('finished', $queued->terminal->getAttribute('stage'));

        $late = $this->createMigration($database, 'migration-2', 'migrating', Deletes::PROCESSING_STUCK_RETENTION_SECONDS + 1, 'attempt-a');

        $race = new class () extends Deletes {
            public ?\Closure $beforeUpdate = null;

            #[\Override]
            protected function deleteByGroup(
                string $collection,
                array $queries,
                Database $database,
                ?callable $callback = null,
            ): void {
            }

            #[\Override]
            protected function listByGroup(
                string $collection,
                array $queries,
                Database $database,
                ?callable $callback = null,
            ): void {
                if ($collection !== 'migrations') {
                    return;
                }

                foreach ($database->find($collection, $queries) as $document) {
                    ($this->beforeUpdate ?? throw new \LogicException('Missing retry interleaving'))($document);
                    if ($callback !== null) {
                        $callback($document);
                    }
                }
            }
        };
        $newAttempt = '';
        $race->beforeUpdate = function (Document $snapshot) use ($database, $project, $publisher, &$newAttempt): void {
            $database->updateDocument('migrations', $snapshot->getId(), new Document([
                'status' => 'failed',
                'stage' => 'finished',
            ]));
            $claim = new Claim(
                $database,
                static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
            );
            $retried = $claim->retry(
                project: $project,
                migrationId: $snapshot->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations-race')),
            );
            $newAttempt = (string) $retried->getAttribute('attemptId');
        };

        $this->maintain($database, $race);

        $stored = $database->getDocument('migrations', $late->getId());
        $this->assertNotSame('', $newAttempt);
        $this->assertSame($newAttempt, $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }

    public function testMaintenanceFailsFinalizingAttemptOnceItsLeaseLapses(): void
    {
        $database = $this->createDatabase();
        $abandoned = $this->createMigration($database, 'migration-abandoned', 'finalizing', Claim::FINALIZING_LEASE + 60);
        $finalizing = $this->createMigration($database, 'migration-finalizing', 'finalizing', Claim::FINALIZING_LEASE - 3_600);
        $migrating = $this->createMigration($database, 'migration-migrating', 'migrating', Claim::FINALIZING_LEASE + 60);

        $this->maintain($database, $this->sweeper());

        $expired = $database->getDocument('migrations', $abandoned->getId());
        $this->assertSame('failed', $expired->getAttribute('status'));
        $this->assertSame('finished', $expired->getAttribute('stage'));
        $this->assertSame('attempt-1', $expired->getAttribute('attemptId'));

        foreach ([$finalizing, $migrating] as $running) {
            $stored = $database->getDocument('migrations', $running->getId());
            $this->assertSame('processing', $stored->getAttribute('status'), $running->getId() . ' is still within its lease');
            $this->assertSame($running->getAttribute('stage'), $stored->getAttribute('stage'));
            $this->assertSame($running->getUpdatedAt(), $stored->getUpdatedAt());
        }
    }

    private function createDatabase(): Database
    {
        // A SQL projection cannot return $version; the in-memory adapter hands it
        // back regardless, which is what hid this sweep expiring nothing at all.
        $database = new Database(
            new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])),
            new Cache(new NoCache()),
        );
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationMaintenanceRecovery')
            ->setNamespace('migration_maintenance_recovery_' . \uniqid());
        $database->create();
        $permissions = [
            Permission::create(Role::any()),
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ];
        $database->createCollection(new Collection(
            id: 'databases',
            attributes: [
                new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
            permissions: $permissions,
            documentSecurity: false,
        ));
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
            permissions: $permissions,
            documentSecurity: false,
        ));
        $database->createCollection(new Collection(
            id: 'targets',
            attributes: [new Attribute('expired', ColumnType::Boolean)],
            permissions: $permissions,
            documentSecurity: false,
        ));
        $database->createCollection(new Collection(
            id: 'transactions',
            attributes: [new Attribute('expiresAt', ColumnType::Datetime)],
            permissions: $permissions,
            documentSecurity: false,
        ));
        $database->createCollection(new Collection(
            id: 'presenceLogs',
            attributes: [new Attribute('expiresAt', ColumnType::Datetime)],
            permissions: $permissions,
            documentSecurity: false,
        ));

        return $database;
    }

    private function createMigration(
        Database $database,
        string $id,
        string $stage,
        int $age,
        string $attemptId = 'attempt-1',
    ): Document {
        $updatedAt = DateTime::addSeconds(new \DateTime(), -$age);
        $database->setPreserveDates(true);

        try {
            return $database->createDocument('migrations', new Document([
                '$id' => $id,
                '$createdAt' => $updatedAt,
                '$updatedAt' => $updatedAt,
                'attemptId' => $attemptId,
                'status' => 'processing',
                'stage' => $stage,
            ]));
        } finally {
            $database->setPreserveDates(false);
        }
    }

    private function project(): Document
    {
        return new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'auths' => [],
        ]);
    }

    private function sweeper(): Deletes
    {
        return new class () extends Deletes {
            #[\Override]
            protected function deleteByGroup(
                string $collection,
                array $queries,
                Database $database,
                ?callable $callback = null,
            ): void {
            }

            #[\Override]
            protected function listByGroup(
                string $collection,
                array $queries,
                Database $database,
                ?callable $callback = null,
            ): void {
                if ($collection === 'migrations') {
                    parent::listByGroup($collection, $queries, $database, $callback);
                }
            }
        };
    }

    private function maintain(Database $database, Deletes $worker): void
    {
        $project = $this->project();
        $now = DateTime::now();
        $publisher = new MockPublisher();
        $queue = new Queue('test');

        $worker->action(
            message: new Message([
                'pid' => 'pid-1',
                'queue' => 'v1-deletes',
                'timestamp' => \time(),
                'payload' => (new DeleteMessage(
                    project: $project,
                    type: DELETE_TYPE_MAINTENANCE,
                    datetime: $now,
                ))->toArray(),
            ]),
            project: $project,
            dbForPlatform: $database,
            getProjectDB: static fn (Document $document): Database => $database,
            getDatabasesDB: static fn (Document $document): Database => $database,
            deviceForFiles: $this->createStub(Device::class),
            deviceForFunctions: $this->createStub(Device::class),
            deviceForSites: $this->createStub(Device::class),
            deviceForBuilds: $this->createStub(Device::class),
            deviceForCache: $this->createStub(Device::class),
            certificates: $this->createStub(Provider::class),
            executor: $this->createStub(Executor::class),
            executionRetention: $now,
            executionsRetentionCount: 0,
            publisherForDeletes: new DeletePublisher($publisher, $queue),
            publisherForUsage: new UsagePublisher($publisher, $queue),
            bus: $this->createStub(Bus::class),
            executionStore: new Store(dsn: 'http://appwrite:secret@clickhouse:8123/appwrite', client: new CapturingClient()),
        );
    }
}
