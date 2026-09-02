<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Modules\Migrations\Delivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Exception\Contention;
use Utopia\Migration\Destinations\Appwrite\ProvisioningOwner;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

require_once __DIR__ . '/../../../../../app/init.php';

final class StandaloneClaimMemory extends Memory
{
    #[\Override]
    public function withTransaction(callable $callback): mixed
    {
        return $callback();
    }
}

final class InterleavingClaimDatabase extends Database
{
    public ?\Closure $afterMigrationRead = null;

    #[\Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $document = parent::getDocument($collection, $id, $queries, $forUpdate);
        if ($forUpdate && $collection === 'migrations' && $this->afterMigrationRead !== null) {
            $callback = $this->afterMigrationRead;
            $this->afterMigrationRead = null;
            $callback();
        }

        return $document;
    }
}

final class ClaimTest extends TestCase
{
    private string|false $claimEnabled;

    private Database $database;

    protected function setUp(): void
    {
        $this->claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');

        $this->database = new InterleavingClaimDatabase(new StandaloneClaimMemory(), new Cache(new NoCache()));
        $this->database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationClaims')
            ->setNamespace('migration_claims_' . \uniqid());
        $this->database->create();
        $this->database->createCollection(new Collection(
            id: 'databases',
            attributes: [
                new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
        ));
        $this->database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::delete(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
    }

    protected function tearDown(): void
    {
        \putenv($this->claimEnabled === false
            ? '_APP_MIGRATIONS_CLAIM_ENABLED'
            : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $this->claimEnabled);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function missingOwnershipAttributes(): \Iterator
    {
        yield 'database migration ID' => ['migrationId'];
        yield 'database migration attempt ID' => ['migrationAttemptId'];
        yield 'migration attempt ID' => ['attemptId'];
    }

    #[DataProvider('missingOwnershipAttributes')]
    public function testReadinessFailsClosedWhenOwnershipAttributeIsMissing(string $missing): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationClaimReadiness')
            ->setNamespace('migration_claim_readiness_' . $missing . '_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'databases',
            attributes: \array_values(\array_filter([
                $missing === 'migrationId' ? null : new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                $missing === 'migrationAttemptId' ? null : new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ])),
        ));
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: $missing === 'attemptId' ? [] : [
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
        ));

        try {
            (new Claim($database, $this->locks()))->assertReady();
            $this->fail('Expected incomplete ownership schema to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_SCHEMA_NOT_READY, $error->getType());
            $this->assertSame(503, $error->getCode());
            $this->assertStringContainsString($missing, $error->getMessage());
        }
    }

    public function testReadinessAcceptsCompleteOwnershipSchema(): void
    {
        (new Claim($this->database, $this->locks()))->assertReady();

        $this->addToAssertionCount(1);
    }

    public function testDisabledProtocolRefusesProducerButStillConsumesLegacyDelivery(): void
    {
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=disabled');
        $claims = new Claim($this->database, $this->locks());

        try {
            $claims->assertReady();
            $this->fail('Expected disabled claim protocol to refuse producers');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_CLAIM_DISABLED, $error->getType());
            $this->assertSame(503, $error->getCode());
        }

        $terminal = $this->createFailedMigration();
        $publisher = new MockPublisher();
        try {
            $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $terminal->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected disabled claim protocol to refuse retry');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_CLAIM_DISABLED, $error->getType());
        }
        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertEmpty($publisher->getEvents('migrations'));
        $this->database->deleteDocument('migrations', $terminal->getId());

        $queued = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $delivery = $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        ));

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertIsString($delivery->migration->getAttribute('attemptId'));
        $this->assertSame('processing', $delivery->migration->getAttribute('status'));
    }

    public function testRetryRefusesIncompleteOwnershipSchemaBeforeMutatingTerminal(): void
    {
        $terminal = $this->createFailedMigration();
        $this->database->deleteAttribute('databases', 'migrationAttemptId');
        $publisher = new MockPublisher();

        try {
            (new Claim($this->database, $this->locks()))->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $terminal->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected incomplete ownership schema to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_SCHEMA_NOT_READY, $error->getType());
            $this->assertSame(503, $error->getCode());
        }

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertEmpty($publisher->getEvents('migrations'));
    }

    public function testRetryPersistsClaimAndDeliveryConsumesItOnce(): void
    {
        $terminal = $this->createFailedMigration();
        $publisher = new MockPublisher();
        $claims = new Claim($this->database, $this->locks());

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $terminal->getId(),
            platform: ['name' => 'test-platform'],
            publisher: new MigrationPublisher($publisher, new Queue('migrations')),
        );

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('pending', $claimed->getAttribute('status'));
        $this->assertSame('finished', $claimed->getAttribute('stage'));
        $this->assertIsString($claimed->getAttribute('attemptId'));
        $this->assertNotSame($terminal->getAttribute('attemptId'), $claimed->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame($claimed->getAttribute('attemptId'), $stored->getAttribute('attemptId'));

        $events = $publisher->getEvents('migrations');
        $this->assertCount(1, $events);
        $message = MigrationMessage::fromArray($events[0]);
        $this->assertInstanceOf(Document::class, $message->terminal);
        $this->assertSame(['$id', 'attemptId', 'status', 'stage'], \array_keys($message->terminal->getArrayCopy()));
        $this->assertSame('failed', $message->terminal->getAttribute('status'));
        $this->assertSame('attempt-terminal', $message->terminal->getAttribute('attemptId'));
        $database = new Document([
            'migrationId' => $terminal->getId(),
            'migrationAttemptId' => 'attempt-terminal',
        ]);
        $owner = $claims->recoverable($database, $message->terminal);
        $this->assertInstanceOf(ProvisioningOwner::class, $owner);
        $this->assertSame($terminal->getId(), $owner->migrationId);
        $this->assertSame('attempt-terminal', $owner->attemptId);

        try {
            $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $terminal->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected the active retry claim to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
        }

        $delivery = $claims->consume('project-1', $message);
        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertSame($message->terminal, $delivery->terminal);
        $processing = $delivery->migration;
        $this->assertSame('processing', $processing->getAttribute('status'));
        $this->assertSame('processing', $processing->getAttribute('stage'));
        $owner = $claims->recoverable($database, $message->terminal);
        $this->assertInstanceOf(ProvisioningOwner::class, $owner);
        $this->assertSame($terminal->getId(), $owner->migrationId);
        $this->assertSame('attempt-terminal', $owner->attemptId);

        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', $message));
        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame('processing', $stored->getAttribute('stage'));
    }

    public function testRetryRestoresTerminalStateWhenEnqueueFails(): void
    {
        $terminal = $this->createFailedMigration();
        $publisher = new class () implements Publisher {
            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };
        $claims = new Claim($this->database, $this->locks());

        try {
            $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $terminal->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame('attempt-terminal', $stored->getAttribute('attemptId'));
    }

    public function testRetryRollbackDoesNotOverwriteNewerGeneration(): void
    {
        $terminal = $this->createFailedMigration();
        $database = $this->database;
        $publisher = new class ($database, $terminal->getId()) implements Publisher {
            public function __construct(
                private readonly Database $database,
                private readonly string $migrationId,
            ) {
            }

            #[\Override]
            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->database->updateDocument('migrations', $this->migrationId, new Document([
                    'attemptId' => 'attempt-newer',
                    'status' => 'pending',
                    'stage' => 'finished',
                ]));

                throw new \RuntimeException('Ambiguous enqueue failure');
            }

            #[\Override]
            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };
        $claims = new Claim($this->database, $this->locks());

        try {
            $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $terminal->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Ambiguous enqueue failure', $error->getMessage());
        }

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('attempt-newer', $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }

    public function testInitialPublishFailureDeletesOnlyItsExactGeneration(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-initial',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $claims = new Claim($this->database, $this->locks());
        $publisher = new class () implements Publisher {
            #[\Override]
            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            #[\Override]
            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };

        try {
            $claims->initial(
                project: new Document(['$id' => 'project-1']),
                migration: $migration,
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }

        $this->assertTrue($this->database->getDocument('migrations', $migration->getId())->isEmpty());
    }

    public function testInitialRollbackDoesNotDeleteNewerGeneration(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-initial',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $database = $this->database;
        $publisher = new class ($database, $migration->getId()) implements Publisher {
            public function __construct(
                private readonly Database $database,
                private readonly string $migrationId,
            ) {
            }

            #[\Override]
            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->database->updateDocument('migrations', $this->migrationId, new Document([
                    'attemptId' => 'attempt-newer',
                    'status' => 'pending',
                    'stage' => 'init',
                ]));

                throw new \RuntimeException('Ambiguous enqueue failure');
            }

            #[\Override]
            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };
        $claims = new Claim($this->database, $this->locks());

        try {
            $claims->initial(
                project: new Document(['$id' => 'project-1']),
                migration: $migration,
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Ambiguous enqueue failure', $error->getMessage());
        }

        $stored = $this->database->getDocument('migrations', $migration->getId());
        $this->assertSame('attempt-newer', $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('init', $stored->getAttribute('stage'));
    }

    public function testStaleInitialProducerCannotPublishAfterLeaseExpires(): void
    {
        $original = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-original',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $publisher = new MockPublisher();
        $migrationPublisher = new MigrationPublisher($publisher, new Queue('migrations'));
        $claims = new Claim($this->database, $this->locks());

        // Producer A holds this snapshot past its lease. Producer B claims it first.
        $claimed = $claims->initial(
            project: new Document(['$id' => 'project-1']),
            migration: $original,
            platform: [],
            publisher: $migrationPublisher,
        );

        $this->assertNotSame($original->getAttribute('attemptId'), $claimed->getAttribute('attemptId'));
        $this->assertCount(1, $publisher->getEvents('migrations'));

        try {
            $claims->initial(
                project: new Document(['$id' => 'project-1']),
                migration: $original,
                platform: [],
                publisher: $migrationPublisher,
            );
            $this->fail('Expected the stale producer generation to be refused');
        } catch (\LogicException $error) {
            $this->assertSame('Initial migration generation is no longer publishable', $error->getMessage());
        }

        $this->assertCount(1, $publisher->getEvents('migrations'));
        $queued = MigrationMessage::fromArray($publisher->getEvents('migrations')[0]);
        $this->assertSame($claimed->getAttribute('attemptId'), $queued->migration->getAttribute('attemptId'));
        $this->assertSame($claimed->getUpdatedAt(), $queued->migration->getUpdatedAt());
    }

    public function testWorkerPersistenceRefusesSupersededGeneration(): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $this->database->updateDocument('migrations', $active->getId(), new Document([
            'attemptId' => 'attempt-b',
            'status' => 'pending',
            'stage' => 'finished',
        ]));
        $active->setAttribute('status', 'completed');
        $active->setAttribute('stage', 'finished');

        $this->assertNotInstanceOf(Document::class, (new Claim($this->database))->persist($active));

        $stored = $this->database->getDocument('migrations', $active->getId());
        $this->assertSame('attempt-b', $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }

    public function testWorkerPersistenceLosesStorageRaceAfterGenerationRead(): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $database = $this->database;
        $this->assertInstanceOf(InterleavingClaimDatabase::class, $database);
        $database->afterMigrationRead = static function () use ($active, $database): void {
            $database->updateDocument('migrations', $active->getId(), new Document([
                'attemptId' => 'attempt-b',
                'status' => 'pending',
                'stage' => 'finished',
            ]));
        };
        $active->setAttribute('status', 'completed');
        $active->setAttribute('stage', 'finished');

        $this->assertNotInstanceOf(Document::class, (new Claim($database))->persist($active));

        $stored = $database->getDocument('migrations', $active->getId());
        $this->assertSame('attempt-b', $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }

    public function testInitialClaimRefusesDocumentDeletedAfterGenerationRead(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $publisher = new MockPublisher();
        $this->deleteAfterRead($migration->getId());

        try {
            (new Claim($this->database))->initial(
                project: new Document(['$id' => 'project-1']),
                migration: $migration,
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected deleted initial claim to lose ownership');
        } catch (Conflict) {
            $this->assertEmpty($publisher->getEvents('migrations'));
        }
    }

    public function testRetryClaimRefusesDocumentDeletedAfterGenerationRead(): void
    {
        $migration = $this->createFailedMigration();
        $publisher = new MockPublisher();
        $this->deleteAfterRead($migration->getId());

        try {
            (new Claim($this->database))->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $migration->getId(),
                platform: [],
                publisher: new MigrationPublisher($publisher, new Queue('migrations')),
            );
            $this->fail('Expected deleted retry claim to lose ownership');
        } catch (Conflict) {
            $this->assertEmpty($publisher->getEvents('migrations'));
        }
    }

    public function testConsumeRefusesDocumentDeletedAfterGenerationRead(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $this->deleteAfterRead($migration->getId());

        $delivery = (new Claim($this->database))->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $migration,
            platform: [],
        ));

        $this->assertNotInstanceOf(Delivery::class, $delivery);
    }

    public function testWorkerPersistenceRefusesDocumentDeletedAfterGenerationRead(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $this->deleteAfterRead($migration->getId());
        $migration->setAttribute('stage', 'finalizing');

        $this->assertNotInstanceOf(Document::class, (new Claim($this->database))->persist($migration));
    }

    public function testExpirationRefusesDocumentDeletedAfterGenerationRead(): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $this->deleteAfterRead($migration->getId());

        $this->assertNotInstanceOf(Document::class, (new Claim($this->database))->expire($migration));
    }

    public function testFinalizingGenerationCannotBeExpired(): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'finalizing',
            'resourceData' => [],
        ]));

        $this->assertNotInstanceOf(Document::class, (new Claim($this->database))->expire($active));

        $stored = $this->database->getDocument('migrations', $active->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame('finalizing', $stored->getAttribute('stage'));
        $this->assertSame('attempt-a', $stored->getAttribute('attemptId'));
    }

    public function testConcurrentRetryClaimHasSingleWinnerAfterLeaseExpires(): void
    {
        $terminal = $this->createFailedMigration();
        $held = false;
        $locks = static function (string $key, int $ttl, callable $callback, float $timeout) use (&$held): mixed {
            if ($held) {
                throw new Contention();
            }

            $held = true;
            try {
                return $callback();
            } finally {
                $held = false;
            }
        };
        $claims = new Claim($this->database, $locks);
        $publisher = new class () implements Publisher {
            public ?\Closure $duringEnqueue = null;
            public int $published = 0;

            #[\Override]
            public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
            {
                $this->published++;
                ($this->duringEnqueue ?? throw new \LogicException('Missing concurrent retry'))();

                return true;
            }

            #[\Override]
            public function enqueueMany(Queue $queue, array $payloads, bool $priority = false): bool
            {
                throw new \LogicException('Not used');
            }

            #[\Override]
            public function retry(Queue $queue, ?int $limit = null): void
            {
            }

            #[\Override]
            public function getQueueSize(Queue $queue, bool $failedJobs = false): int
            {
                return 0;
            }
        };
        $migrationPublisher = new MigrationPublisher($publisher, new Queue('migrations'));
        $refusals = 0;
        $publisher->duringEnqueue = static function () use ($claims, $migrationPublisher, $terminal, &$refusals): void {
            try {
                $claims->retry(
                    project: new Document(['$id' => 'project-1']),
                    migrationId: $terminal->getId(),
                    platform: [],
                    publisher: $migrationPublisher,
                );
            } catch (Exception $error) {
                self::assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
                $refusals++;
            }
        };

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $terminal->getId(),
            platform: [],
            publisher: $migrationPublisher,
        );

        $this->assertSame(1, $publisher->published);
        $this->assertSame(1, $refusals);
        $this->assertSame($claimed->getAttribute('attemptId'), $this->database
            ->getDocument('migrations', $terminal->getId())
            ->getAttribute('attemptId'));
    }

    public function testConsumeDerivesTerminalSnapshotForLegacyRetryDelivery(): void
    {
        $terminal = $this->createFailedMigration();
        $queued = new Document($terminal->getArrayCopy());
        $queued
            ->setAttribute('status', 'pending')
            ->setAttribute('stage', 'finished');
        $claims = new Claim($this->database, $this->locks());

        $claimed = $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        ));

        $this->assertInstanceOf(Delivery::class, $claimed);
        $this->assertSame('processing', $claimed->migration->getAttribute('status'));
        $this->assertIsString($claimed->migration->getAttribute('attemptId'));
        $this->assertNotSame('attempt-terminal', $claimed->migration->getAttribute('attemptId'));
        $this->assertInstanceOf(Document::class, $claimed->terminal);
        $this->assertSame(['$id', 'attemptId', 'status', 'stage'], \array_keys($claimed->terminal->getArrayCopy()));
        $this->assertSame($terminal->getId(), $claimed->terminal->getId());
        $this->assertSame('attempt-terminal', $claimed->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $claimed->terminal->getAttribute('status'));
        $this->assertSame('finished', $claimed->terminal->getAttribute('stage'));
        $owner = $claims->recoverable(new Document([
            'migrationId' => $terminal->getId(),
            'migrationAttemptId' => 'attempt-terminal',
        ]), $claimed->terminal);
        $this->assertInstanceOf(ProvisioningOwner::class, $owner);
        $this->assertSame($terminal->getId(), $owner->migrationId);
        $this->assertSame('attempt-terminal', $owner->attemptId);
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        )));
    }

    public function testConsumeRejectsPendingRetryWithoutTerminalSnapshot(): void
    {
        $terminal = $this->createFailedMigration();
        $pending = $this->database->updateDocument('migrations', $terminal->getId(), new Document([
            'status' => 'pending',
            'stage' => 'finished',
        ]));
        $claims = new Claim($this->database, $this->locks());

        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $pending,
            platform: [],
        )));
    }

    public function testConsumeCreatesAttemptForLegacyInitialDelivery(): void
    {
        $queued = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $claims = new Claim($this->database, $this->locks());

        $delivery = $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        ));

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertNotInstanceOf(Document::class, $delivery->terminal);
        $this->assertSame('processing', $delivery->migration->getAttribute('status'));
        $this->assertSame('processing', $delivery->migration->getAttribute('stage'));
        $this->assertIsString($delivery->migration->getAttribute('attemptId'));
        $this->assertNotSame('', $delivery->migration->getAttribute('attemptId'));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        )));
    }

    public function testConsumePreservesCurrentAttemptAndRejectsDuplicateInitialDelivery(): void
    {
        $queued = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-current',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $claims = new Claim($this->database, $this->locks());
        $message = new MigrationMessage(
            project: new Document(['$id' => 'project-1']),
            migration: $queued,
            platform: [],
        );

        $delivery = $claims->consume('project-1', $message);

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertSame('attempt-current', $delivery->migration->getAttribute('attemptId'));
        $this->assertSame('processing', $delivery->migration->getAttribute('status'));
        $this->assertSame('processing', $delivery->migration->getAttribute('stage'));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', $message));
    }

    public function testRecoveryRequiresExactAuthoritativeOwnerLifecycle(): void
    {
        $claims = new Claim($this->database, $this->locks());
        $failed = $this->createFailedMigration();
        $completed = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-completed',
            'attemptId' => 'attempt-completed',
            'status' => 'completed',
            'stage' => 'finished',
            'resourceData' => [],
        ]));
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-active',
            'attemptId' => 'attempt-active',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));

        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(new Document([
            'migrationId' => $failed->getId(),
            'migrationAttemptId' => 'attempt-terminal',
        ])));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(new Document([
            'migrationId' => $completed->getId(),
            'migrationAttemptId' => 'attempt-completed',
        ])));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(
            new Document([
                'migrationId' => $active->getId(),
                'migrationAttemptId' => 'attempt-active',
            ]),
        ));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(
            new Document(['migrationId' => 'migration-unknown']),
        ));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(new Document()));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(new Document(['migrationId' => ['malformed']])));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(new Document([
            'migrationId' => $failed->getId(),
            'migrationAttemptId' => 'attempt-mismatch',
        ])));
        $this->assertNotInstanceOf(ProvisioningOwner::class, $claims->recoverable(
            new Document([
                'migrationId' => $active->getId(),
                'migrationAttemptId' => 'attempt-active',
            ]),
            new Document([
                '$id' => $failed->getId(),
                'attemptId' => 'attempt-terminal',
                'status' => 'failed',
                'stage' => 'finished',
            ]),
        ));
    }

    private function createFailedMigration(): Document
    {
        return $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-terminal',
            'status' => 'failed',
            'stage' => 'finished',
            'resourceData' => [
                [
                    'resource' => 'database',
                    'id' => 'database-1',
                    'status' => 'success',
                    'message' => '',
                ],
            ],
        ]));
    }

    private function deleteAfterRead(string $id): void
    {
        $database = $this->database;
        $this->assertInstanceOf(InterleavingClaimDatabase::class, $database);
        $database->afterMigrationRead = static function () use ($database, $id): void {
            $database->deleteDocument('migrations', $id);
        };
    }

    private function locks(): \Closure
    {
        return static function (string $key, int $ttl, callable $callback, float $timeout): mixed {
            self::assertSame('migration:project-1:migration-1', $key);
            self::assertSame(30, $ttl);
            self::assertSame(10.0, $timeout);

            return $callback();
        };
    }
}
