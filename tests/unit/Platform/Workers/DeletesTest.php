<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Workers\Deletes;
use Executor\Executor;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Bus\Bus;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

require_once __DIR__ . '/../../../../app/init.php';

final class DeletesTest extends TestCase
{
    public function testMaintenanceMakesStaleProcessingAttemptRetryable(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
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

        $stale = DateTime::addSeconds(
            new \DateTime(),
            -Deletes::PROCESSING_STUCK_RETENTION_SECONDS - 1,
        );
        $database->setPreserveDates(true);
        try {
            $migration = $database->createDocument('migrations', new Document([
                '$id' => 'migration-1',
                '$createdAt' => $stale,
                '$updatedAt' => $stale,
                'attemptId' => 'attempt-1',
                'status' => 'processing',
                'stage' => 'processing',
            ]));
        } finally {
            $database->setPreserveDates(false);
        }

        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'auths' => [],
        ]);
        $now = DateTime::now();
        $message = new Message([
            'pid' => 'pid-1',
            'queue' => 'v1-deletes',
            'timestamp' => \time(),
            'payload' => (new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_MAINTENANCE,
                datetime: $now,
                hourlyUsageRetentionDatetime: $now,
            ))->toArray(),
        ]);
        $worker = new class () extends Deletes {
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
        $publisher = new MockPublisher();
        $queue = new Queue('test');
        $run = function (Deletes $deletes) use ($database, $message, $now, $project, $publisher, $queue): void {
            $deletes->action(
                message: $message,
                project: $project,
                dbForPlatform: $database,
                getProjectDB: static fn (Document $document): Database => $database,
                getDatabasesDB: static fn (Document $document): Database => $database,
                getLogsDB: static fn (Document $document): Database => $database,
                deviceForFiles: $this->createStub(Device::class),
                deviceForFunctions: $this->createStub(Device::class),
                deviceForSites: $this->createStub(Device::class),
                deviceForBuilds: $this->createStub(Device::class),
                deviceForCache: $this->createStub(Device::class),
                certificates: $this->createStub(Provider::class),
                executor: $this->createStub(Executor::class),
                executionRetention: $now,
                executionsRetentionCount: 0,
                log: $this->createStub(Log::class),
                publisherForDeletes: new DeletePublisher($publisher, $queue),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                bus: $this->createStub(Bus::class),
            );
        };

        $run($worker);

        $terminal = $database->getDocument('migrations', $migration->getId());
        $this->assertSame('failed', $terminal->getAttribute('status'));
        $this->assertSame('finished', $terminal->getAttribute('stage'));
        $this->assertSame('attempt-1', $terminal->getAttribute('attemptId'));

        $claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');
        try {
            $retried = (new Claim(
                $database,
                static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
            ))->retry(
                project: $project,
                migrationId: $migration->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
        } finally {
            \putenv($claimEnabled === false
                ? '_APP_MIGRATIONS_CLAIM_ENABLED'
                : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $claimEnabled);
        }

        $this->assertSame('pending', $retried->getAttribute('status'));
        $this->assertSame('finished', $retried->getAttribute('stage'));
        $this->assertNotSame('attempt-1', $retried->getAttribute('attemptId'));
        $queued = MigrationMessage::fromArray($publisher->getEvents('migrations')[0]);
        $this->assertInstanceOf(Document::class, $queued->terminal);
        $this->assertSame('attempt-1', $queued->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $queued->terminal->getAttribute('status'));
        $this->assertSame('finished', $queued->terminal->getAttribute('stage'));

        $database->setPreserveDates(true);
        try {
            $late = $database->createDocument('migrations', new Document([
                '$id' => 'migration-2',
                '$createdAt' => $stale,
                '$updatedAt' => $stale,
                'attemptId' => 'attempt-a',
                'status' => 'processing',
                'stage' => 'migrating',
            ]));
        } finally {
            $database->setPreserveDates(false);
        }

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

        $claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');
        try {
            $run($race);
        } finally {
            \putenv($claimEnabled === false
                ? '_APP_MIGRATIONS_CLAIM_ENABLED'
                : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $claimEnabled);
        }

        $stored = $database->getDocument('migrations', $late->getId());
        $this->assertNotSame('', $newAttempt);
        $this->assertSame($newAttempt, $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }
}
