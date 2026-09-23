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
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Lock\Exception\Contention;
use Utopia\Migration\Destinations\Appwrite\ProvisioningOwner;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Publisher\Synchronous as Publisher;
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

final class ExclusiveClaimLock
{
    /**
     * @var array<int, string>
     */
    public array $keys = [];

    /**
     * @var array<string, true>
     */
    private array $held = [];

    public function __invoke(string $key, int $ttl, callable $callback, float $timeout): mixed
    {
        if (isset($this->held[$key])) {
            throw new Contention('Failed to acquire lock: ' . $key);
        }

        $this->keys[] = $key;
        $this->held[$key] = true;

        try {
            return $callback();
        } finally {
            unset($this->held[$key]);
        }
    }
}

final class ClaimTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
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

    public function testProducersAndLegacyDeliveriesNeedNothingButTheOwnershipSchema(): void
    {
        $claims = new Claim($this->database, $this->locks());
        $claims->assertReady();

        $terminal = $this->createFailedMigration();
        $publisher = new MockPublisher();
        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $terminal->getId(),
            platform: [],
            publisher: new MigrationPublisher($publisher, new Queue('migrations')),
        );

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame($claimed->getAttribute('attemptId'), $stored->getAttribute('attemptId'));
        $this->assertNotSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertCount(1, $publisher->getEvents('migrations'));

        $queued = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-legacy',
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
        $this->assertSame(
            [],
            \array_diff(\array_keys($message->terminal->getArrayCopy()), ['$id', 'attemptId', 'status', 'stage']),
            'Terminal snapshot must not carry the migration payload onto the queue, credentials included'
        );
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

    public function testReclaimClaimsAGenerationConsumeAcceptsWithoutPublishing(): void
    {
        $terminal = $this->createFailedMigration();
        $locks = $this->locks();
        $claims = new Claim($this->database, $locks);
        $project = new Document(['$id' => 'project-1']);

        $retry = $claims->reclaim($project->getId(), $terminal->getId());

        $this->assertSame(['migration:project-1:migration-1'], $locks->keys);
        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertNotSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame($stored->getAttribute('attemptId'), $retry->migration->getAttribute('attemptId'));
        $this->assertSame($stored->getUpdatedAt(), $retry->migration->getUpdatedAt());
        $this->assertSame(
            [],
            \array_diff(\array_keys($retry->terminal->getArrayCopy()), ['$id', 'attemptId', 'status', 'stage']),
            'Terminal snapshot must not carry the migration payload onto the queue, credentials included'
        );
        $this->assertSame($terminal->getId(), $retry->terminal->getId());
        $this->assertSame('attempt-terminal', $retry->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $retry->terminal->getAttribute('status'));
        $this->assertSame('finished', $retry->terminal->getAttribute('stage'));

        $message = $retry->message($project, ['name' => 'test-platform']);
        $this->assertSame($project, $message->project);
        $this->assertSame($retry->migration, $message->migration);
        $this->assertSame($retry->terminal, $message->terminal);
        $this->assertSame(['name' => 'test-platform'], $message->platform);

        $delivery = $claims->consume($project->getId(), MigrationMessage::fromArray($message->toArray()));

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertSame($retry->migration->getAttribute('attemptId'), $delivery->migration->getAttribute('attemptId'));
        $this->assertSame('processing', $delivery->migration->getAttribute('status'));
        $this->assertSame('processing', $delivery->migration->getAttribute('stage'));
        $this->assertInstanceOf(Document::class, $delivery->terminal);
        $this->assertSame('attempt-terminal', $delivery->terminal->getAttribute('attemptId'));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume($project->getId(), new MigrationMessage(
            project: $project,
            migration: $terminal,
        )));

        try {
            $claims->reclaim($project->getId(), $terminal->getId());
            $this->fail('Expected the running attempt to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
        }
    }

    /** @return \Iterator<string, array{string, string}> */
    public static function unfinishedLifecycles(): \Iterator
    {
        yield 'queued' => ['pending', 'init'];
        yield 'queued retry' => ['pending', 'finished'];
        yield 'processing' => ['processing', 'processing'];
        yield 'migrating' => ['processing', 'migrating'];
        yield 'finalizing' => ['processing', 'finalizing'];
        yield 'completed' => ['completed', 'finished'];
    }

    #[DataProvider('unfinishedLifecycles')]
    public function testReclaimRefusesAMigrationThatHasNotFailed(string $status, string $stage): void
    {
        $migration = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-current',
            'status' => $status,
            'stage' => $stage,
            'resourceData' => [],
        ]));

        try {
            (new Claim($this->database, $this->locks()))->reclaim('project-1', $migration->getId());
            $this->fail('Expected a migration that has not failed to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
        }

        $stored = $this->database->getDocument('migrations', $migration->getId());
        $this->assertSame('attempt-current', $stored->getAttribute('attemptId'));
        $this->assertSame($status, $stored->getAttribute('status'));
        $this->assertSame($stage, $stored->getAttribute('stage'));
        $this->assertSame($migration->getUpdatedAt(), $stored->getUpdatedAt());
    }

    public function testReclaimRefusesAnUnknownMigration(): void
    {
        try {
            (new Claim($this->database, $this->locks()))->reclaim('project-1', 'migration-unknown');
            $this->fail('Expected an unknown migration to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_NOT_FOUND, $error->getType());
        }
    }

    public function testReclaimRefusesIncompleteOwnershipSchemaBeforeMutatingTerminal(): void
    {
        $terminal = $this->createFailedMigration();
        $this->database->deleteAttribute('databases', 'migrationId');

        try {
            (new Claim($this->database, $this->locks()))->reclaim('project-1', $terminal->getId());
            $this->fail('Expected incomplete ownership schema to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_SCHEMA_NOT_READY, $error->getType());
        }

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame($terminal->getUpdatedAt(), $stored->getUpdatedAt());
    }

    public function testRetryRestoresTerminalStateWhenEnqueueFails(): void
    {
        $terminal = $this->createFailedMigration();
        $publisher = new class () implements Publisher {
            public function publish(Queue $queue, array $payload): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            public function publishMany(Queue $queue, array $payloads): bool
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
            public function publish(Queue $queue, array $payload): bool
            {
                $this->database->updateDocument('migrations', $this->migrationId, new Document([
                    'attemptId' => 'attempt-newer',
                    'status' => 'pending',
                    'stage' => 'finished',
                ]));

                throw new \RuntimeException('Ambiguous enqueue failure');
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
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
            public function publish(Queue $queue, array $payload): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
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
            public function publish(Queue $queue, array $payload): bool
            {
                $this->database->updateDocument('migrations', $this->migrationId, new Document([
                    'attemptId' => 'attempt-newer',
                    'status' => 'pending',
                    'stage' => 'init',
                ]));

                throw new \RuntimeException('Ambiguous enqueue failure');
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
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

    /** @return \Iterator<string, array{string, int|string|null}> */
    public static function staleIdentities(): \Iterator
    {
        yield 'different attempt with identical timestamp' => ['attemptId', 'attempt-b'];
        yield 'different immutable sequence' => ['$sequence', 'replacement'];
    }

    #[DataProvider('staleIdentities')]
    public function testWorkerPersistenceRejectsMatchingTimestampWithWrongIdentity(string $attribute, int|string|null $value): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-identity',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $stale = new Document($active->getArrayCopy());
        $stale->setAttribute($attribute, $value);
        $stale->setAttribute('status', 'completed');
        $stale->setAttribute('stage', 'finished');

        $this->assertNotInstanceOf(Document::class, (new Claim($this->database))->persist($stale));

        $stored = $this->database->getDocument('migrations', $active->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame($active->getUpdatedAt(), $stored->getUpdatedAt());
        $this->assertSame($active->getSequence(), $stored->getSequence());
    }

    public function testWorkerPersistenceRefusesAGenerationItCannotCompare(): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-undated',
            'attemptId' => 'attempt-a',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));
        $undated = new Document($active->getArrayCopy());
        $undated->removeAttribute('$updatedAt');
        $undated->setAttribute('status', 'completed');
        $undated->setAttribute('stage', 'finished');

        try {
            (new Claim($this->database))->persist($undated);
            $this->fail('A generation that cannot be compared must be refused, not treated as superseded');
        } catch (\LogicException) {
        }

        $stored = $this->database->getDocument('migrations', $active->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame('migrating', $stored->getAttribute('stage'));
        $this->assertSame($active->getUpdatedAt(), $stored->getUpdatedAt());
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

    public function testFinalizingGenerationExpiresOnlyAfterItsLease(): void
    {
        $claims = new Claim($this->database, $this->locks());
        $finalizing = $this->createStaleMigration('migration-finalizing', 'attempt-a', 'processing', 'finalizing', Claim::FINALIZING_LEASE - 3_600);
        $abandoned = $this->createStaleMigration('migration-abandoned', 'attempt-b', 'processing', 'finalizing', Claim::FINALIZING_LEASE + 60);

        $this->assertNotInstanceOf(Document::class, $claims->expire($finalizing));

        $stored = $this->database->getDocument('migrations', $finalizing->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame('finalizing', $stored->getAttribute('stage'));
        $this->assertSame('attempt-a', $stored->getAttribute('attemptId'));
        $this->assertSame($finalizing->getUpdatedAt(), $stored->getUpdatedAt());

        $expired = $claims->expire($abandoned);

        $this->assertInstanceOf(Document::class, $expired);
        $this->assertSame('failed', $expired->getAttribute('status'));
        $this->assertSame('finished', $expired->getAttribute('stage'));
        $this->assertSame('attempt-b', $expired->getAttribute('attemptId'));

        $retried = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $abandoned->getId(),
            platform: [],
            publisher: new MigrationPublisher(new MockPublisher(), new Queue('migrations')),
        );
        $this->assertSame('pending', $retried->getAttribute('status'));
        $this->assertNotSame('attempt-b', $retried->getAttribute('attemptId'));
    }

    /** @return \Iterator<string, array{string}> */
    public static function runningStages(): \Iterator
    {
        yield 'processing' => ['processing'];
        yield 'migrating' => ['migrating'];
    }

    #[DataProvider('runningStages')]
    public function testExpirationRefusesAnAttemptWithinItsLivenessLease(string $stage): void
    {
        $claims = new Claim($this->database, $this->locks());
        $running = $this->createStaleMigration('migration-running', 'attempt-a', 'processing', $stage, 60);
        $silent = $this->createStaleMigration('migration-silent', 'attempt-b', 'processing', $stage, Claim::LIVENESS_LEASE + 60);

        $this->assertNotInstanceOf(Document::class, $claims->expire($running));
        $stored = $this->database->getDocument('migrations', $running->getId());
        $this->assertSame('processing', $stored->getAttribute('status'));
        $this->assertSame($stage, $stored->getAttribute('stage'));
        $this->assertSame($running->getUpdatedAt(), $stored->getUpdatedAt());

        $expired = $claims->expire($silent);
        $this->assertInstanceOf(Document::class, $expired);
        $this->assertSame('failed', $expired->getAttribute('status'));
        $this->assertSame('finished', $expired->getAttribute('stage'));
    }

    /** @return \Iterator<string, array{string}> */
    public static function failedStages(): \Iterator
    {
        yield 'failed before processing' => ['init'];
        yield 'failed while processing' => ['processing'];
        yield 'failed while migrating' => ['migrating'];
        yield 'failed while finalizing' => ['finalizing'];
        yield 'failed and finished' => ['finished'];
    }

    #[DataProvider('failedStages')]
    public function testRetryAcceptsAFailedMigrationInAnyStage(string $stage): void
    {
        $failed = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-terminal',
            'status' => 'failed',
            'stage' => $stage,
            'resourceData' => [],
        ]));
        $publisher = new MockPublisher();
        $claims = new Claim($this->database, $this->locks());

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $failed->getId(),
            platform: [],
            publisher: new MigrationPublisher($publisher, new Queue('migrations')),
        );

        $this->assertSame('pending', $claimed->getAttribute('status'));
        $this->assertSame('finished', $claimed->getAttribute('stage'));
        $this->assertNotSame('attempt-terminal', $claimed->getAttribute('attemptId'));
        $events = $publisher->getEvents('migrations');
        $this->assertCount(1, $events);
        $message = MigrationMessage::fromArray($events[0]);
        $this->assertInstanceOf(Document::class, $message->terminal);
        $this->assertSame('attempt-terminal', $message->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $message->terminal->getAttribute('status'));
        $this->assertSame($stage, $message->terminal->getAttribute('stage'));

        $delivery = $claims->consume('project-1', $message);

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertSame($claimed->getAttribute('attemptId'), $delivery->migration->getAttribute('attemptId'));
        $this->assertSame('processing', $delivery->migration->getAttribute('status'));
        $this->assertSame('processing', $delivery->migration->getAttribute('stage'));
    }

    public function testRetryRollbackRestoresTheFailedStageItClaimedFrom(): void
    {
        $failed = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-terminal',
            'status' => 'failed',
            'stage' => 'migrating',
            'resourceData' => [],
        ]));

        try {
            (new Claim($this->database, $this->locks()))->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $failed->getId(),
                platform: [],
                publisher: new MigrationPublisher($this->unavailablePublisher(), new Queue('migrations')),
            );
            $this->fail('Expected enqueue failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }

        $stored = $this->database->getDocument('migrations', $failed->getId());
        $this->assertSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('migrating', $stored->getAttribute('stage'));
    }

    public function testConcurrentRetryClaimHasSingleWinnerAfterLeaseExpires(): void
    {
        $terminal = $this->createFailedMigration();
        $claims = new Claim($this->database, $this->locks());
        $publisher = new class () implements Publisher {
            public ?\Closure $duringEnqueue = null;
            public int $published = 0;

            #[\Override]
            public function publish(Queue $queue, array $payload): bool
            {
                $this->published++;
                ($this->duringEnqueue ?? throw new \LogicException('Missing concurrent retry'))();

                return true;
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
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

    public function testCompetingClaimForTheSameMigrationIsRefusedWhileTheLockIsHeld(): void
    {
        $terminal = $this->createFailedMigration();
        $locks = $this->locks();
        $claims = new Claim($this->database, $locks);
        $publisher = new MockPublisher();
        $migrationPublisher = new MigrationPublisher($publisher, new Queue('migrations'));
        $refusals = 0;
        $database = $this->database;
        $this->assertInstanceOf(InterleavingClaimDatabase::class, $database);
        $database->afterMigrationRead = static function () use ($claims, $migrationPublisher, $terminal, &$refusals): void {
            try {
                $claims->retry(
                    project: new Document(['$id' => 'project-1']),
                    migrationId: $terminal->getId(),
                    platform: [],
                    publisher: $migrationPublisher,
                );
            } catch (Contention) {
                $refusals++;
            }
        };

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $terminal->getId(),
            platform: [],
            publisher: $migrationPublisher,
        );

        $this->assertSame(1, $refusals);
        $this->assertCount(1, $locks->keys);
        $this->assertCount(1, $publisher->getEvents('migrations'));

        $stored = $this->database->getDocument('migrations', $terminal->getId());
        $this->assertSame($claimed->getAttribute('attemptId'), $stored->getAttribute('attemptId'));
        $this->assertNotSame('attempt-terminal', $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
    }

    public function testClaimsForDistinctMigrationsAreNotSerialized(): void
    {
        $first = $this->createFailedMigration();
        $second = $this->createFailedMigration('migration-2');
        $locks = $this->locks();
        $claims = new Claim($this->database, $locks);
        $publisher = new MockPublisher();
        $migrationPublisher = new MigrationPublisher($publisher, new Queue('migrations'));
        $concurrent = null;
        $database = $this->database;
        $this->assertInstanceOf(InterleavingClaimDatabase::class, $database);
        $database->afterMigrationRead = static function () use ($claims, $migrationPublisher, $second, &$concurrent): void {
            $concurrent = $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $second->getId(),
                platform: [],
                publisher: $migrationPublisher,
            );
        };

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $first->getId(),
            platform: [],
            publisher: $migrationPublisher,
        );

        $this->assertInstanceOf(Document::class, $concurrent);
        $this->assertCount(2, $locks->keys);
        $this->assertCount(2, \array_unique($locks->keys));
        $this->assertCount(2, $publisher->getEvents('migrations'));

        foreach ([$first->getId() => $claimed, $second->getId() => $concurrent] as $id => $expected) {
            $stored = $this->database->getDocument('migrations', $id);
            $this->assertSame('pending', $stored->getAttribute('status'));
            $this->assertSame('finished', $stored->getAttribute('stage'));
            $this->assertSame($expected->getAttribute('attemptId'), $stored->getAttribute('attemptId'));
        }
    }

    public function testLockIsReleasedWhenTheGuardedClaimIsRefused(): void
    {
        $active = $this->database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-active',
            'status' => 'processing',
            'stage' => 'processing',
            'resourceData' => [],
        ]));
        $locks = $this->locks();
        $claims = new Claim($this->database, $locks);
        $publisher = new MockPublisher();
        $migrationPublisher = new MigrationPublisher($publisher, new Queue('migrations'));

        try {
            $claims->retry(
                project: new Document(['$id' => 'project-1']),
                migrationId: $active->getId(),
                platform: [],
                publisher: $migrationPublisher,
            );
            $this->fail('Expected the active migration to be refused');
        } catch (Exception $error) {
            $this->assertSame(Exception::MIGRATION_IN_PROGRESS, $error->getType());
        }

        $this->database->updateDocument('migrations', $active->getId(), new Document([
            'status' => 'failed',
            'stage' => 'finished',
        ]));

        $claimed = $claims->retry(
            project: new Document(['$id' => 'project-1']),
            migrationId: $active->getId(),
            platform: [],
            publisher: $migrationPublisher,
        );

        $this->assertCount(2, $locks->keys);
        $this->assertCount(1, \array_unique($locks->keys));
        $this->assertSame('pending', $claimed->getAttribute('status'));
        $this->assertCount(1, $publisher->getEvents('migrations'));
    }

    public function testLockKeysSeparateProjectsAndMigrations(): void
    {
        $first = $this->createFailedMigration();
        $second = $this->createFailedMigration('migration-2');
        $locks = $this->locks();
        $claims = new Claim($this->database, $locks);
        $project = new Document(['$id' => 'project-1']);

        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(project: $project, migration: $first)));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(project: $project, migration: $first)));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-2', new MigrationMessage(project: $project, migration: $first)));
        $this->assertNotInstanceOf(Delivery::class, $claims->consume('project-1', new MigrationMessage(project: $project, migration: $second)));

        $this->assertCount(4, $locks->keys);

        [$taken, $repeated, $otherProject, $otherMigration] = $locks->keys;
        $this->assertSame($taken, $repeated);
        $this->assertNotSame($taken, $otherProject);
        $this->assertNotSame($taken, $otherMigration);
        $this->assertNotSame($otherProject, $otherMigration);
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
        $this->assertSame(
            [],
            \array_diff(\array_keys($claimed->terminal->getArrayCopy()), ['$id', 'attemptId', 'status', 'stage']),
            'Terminal snapshot must not carry the migration payload onto the queue, credentials included'
        );
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

    private function createFailedMigration(string $id = 'migration-1'): Document
    {
        return $this->database->createDocument('migrations', new Document([
            '$id' => $id,
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

    private function createStaleMigration(string $id, string $attemptId, string $status, string $stage, int $age): Document
    {
        $updatedAt = DateTime::addSeconds(new \DateTime(), -$age);
        $this->database->setPreserveDates(true);

        try {
            return $this->database->createDocument('migrations', new Document([
                '$id' => $id,
                '$createdAt' => $updatedAt,
                '$updatedAt' => $updatedAt,
                'attemptId' => $attemptId,
                'status' => $status,
                'stage' => $stage,
                'resourceData' => [],
            ]));
        } finally {
            $this->database->setPreserveDates(false);
        }
    }

    private function unavailablePublisher(): Publisher
    {
        return new class () implements Publisher {
            #[\Override]
            public function publish(Queue $queue, array $payload): bool
            {
                throw new \RuntimeException('Queue unavailable');
            }

            #[\Override]
            public function publishMany(Queue $queue, array $payloads): bool
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
    }

    private function deleteAfterRead(string $id): void
    {
        $database = $this->database;
        $this->assertInstanceOf(InterleavingClaimDatabase::class, $database);
        $database->afterMigrationRead = static function () use ($database, $id): void {
            $database->deleteDocument('migrations', $id);
        };
    }

    private function locks(): ExclusiveClaimLock
    {
        return new ExclusiveClaimLock();
    }
}
