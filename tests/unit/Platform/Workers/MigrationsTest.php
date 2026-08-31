<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Modules\Migrations\Superseded;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Resource;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

final class MigrationsTest extends TestCase
{
    public function testSuccessHooksRunOnlyAfterFinalizingClaimIsPersisted(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
            });
        $source->expects($this->never())->method('error');
        $destination->expects($this->never())->method('error');

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('completed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:finalizing',
            'destination:success',
            'source:success',
            'persist:completed:finished',
        ], $events);
    }

    public function testThrowingSourceSuccessHookPersistsFailedMigrationWithoutRerunningHooks(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
                throw new \RuntimeException('Finalization failed');
            });
        $source
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:error';
            });
        $destination
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:error';
            });

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('failed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:finalizing',
            'destination:success',
            'source:success',
            'persist:failed:finished',
            'source:error',
            'destination:error',
        ], $events);
        $this->assertNotContains('persist:completed:finished', $events);
    }

    public function testSuccessHookErrorsPreventCompletedMigration(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createMock(Destination::class);
        $error = new \Utopia\Migration\Exception(
            resourceName: Resource::TYPE_DATABASE,
            resourceGroup: Transfer::GROUP_DATABASES,
            message: 'Database finalization failed',
        );

        $destination->method('getErrors')->willReturnOnConsecutiveCalls([], [$error], [$error]);
        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
            });
        $source
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:error';
            });
        $destination
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:error';
            });
        $destination->expects($this->once())->method('shutdown');
        $destination->expects($this->once())->method('cleanUp');

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('failed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:finalizing',
            'destination:success',
            'source:success',
            'persist:failed:finished',
            'source:error',
            'destination:error',
        ], $events);
        $this->assertNotContains('persist:completed:finished', $events);
    }

    public function testSupersededFinalizingClaimPreventsSuccessHooksAndStillCleansUp(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination->expects($this->never())->method('success');
        $source->expects($this->never())->method('success');
        $source->expects($this->never())->method('error');
        $destination->expects($this->never())->method('error');

        $migration = $this->createMigration();
        $processor = $this->createProcessor(
            $source,
            $destination,
            $events,
            static function (Document $migration): Document {
                if ($migration->getAttribute('stage') === 'finalizing') {
                    throw new Superseded('Migration attempt was superseded');
                }

                return $migration;
            },
        );

        $this->process($processor, $migration);

        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:finalizing',
        ], $events);
    }

    public function testNullResourceTypeUsesEmptyResourceSelector(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:success';
            });
        $source
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'source:success';
            });
        $source->expects($this->never())->method('error');
        $destination->expects($this->never())->method('error');

        $migration = $this->createMigration(null);
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('completed', $migration->getAttribute('status'));
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:finalizing',
            'destination:success',
            'source:success',
            'persist:completed:finished',
        ], $events);
    }

    public function testResourceContextUsesCanonicalRelationFields(): void
    {
        $worker = new class () extends Migrations {
            /**
             * @return array{resourceId: string, resourceInternalId: string, resourceType: string, parentResourceId: string, parentResourceInternalId: string, parentResourceType: string}
             */
            public function context(Document $migration): array
            {
                return $this->resolveResourceContext($migration);
            }
        };

        $this->assertSame([
            'resourceId' => 'table-a',
            'resourceInternalId' => '201',
            'resourceType' => Resource::TYPE_COLLECTION,
            'parentResourceId' => 'database-a',
            'parentResourceInternalId' => '101',
            'parentResourceType' => Resource::TYPE_DATABASE,
        ], $worker->context(new Document([
            'resourceId' => 'table-a',
            'resourceInternalId' => '201',
            'resourceType' => Resource::TYPE_COLLECTION,
            'parentResourceId' => 'database-a',
            'parentResourceInternalId' => '101',
            'parentResourceType' => Resource::TYPE_DATABASE,
        ])));

        $this->assertSame([
            'resourceId' => 'table-a',
            'resourceInternalId' => '',
            'resourceType' => Resource::TYPE_COLLECTION,
            'parentResourceId' => 'database-a',
            'parentResourceInternalId' => '',
            'parentResourceType' => Resource::TYPE_DATABASE,
        ], $worker->context(new Document([
            'resourceId' => 'database-a:table-a',
            'resourceType' => Resource::TYPE_DATABASE,
        ])));
    }

    public function testActionClearsSourceProjectBetweenDeliveries(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationWorkerReuse')
            ->setNamespace('migration_worker_reuse_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'teamId' => 'team-1',
        ]);
        $messages = [];
        foreach (['migration-1', 'migration-2'] as $index => $migrationId) {
            $migration = $database->createDocument('migrations', new Document([
                '$id' => $migrationId,
                'attemptId' => 'attempt-' . $index,
                'status' => 'pending',
                'stage' => 'init',
                'resourceData' => [],
            ]));
            $messages[] = new Message([
                'pid' => 'pid-' . $index,
                'queue' => 'v1-migrations',
                'timestamp' => \time(),
                'payload' => (new MigrationMessage(
                    project: $project,
                    migration: $migration,
                ))->toArray(),
            ]);
        }

        $worker = new class () extends Migrations {
            /** @var array<?string> */
            public array $sourceProjects = [];

            #[\Override]
            protected function processMigration(
                Document $migration,
                Realtime $queueForRealtime,
                MailPublisher $publisherForMails,
                Context $usage,
                UsagePublisher $publisherForUsage,
                array $platform,
                Authorization $authorization,
            ): void {
                $this->sourceProjects[] = $this->sourceProject?->getId();
                $this->sourceProject = new Document(['$id' => 'source-' . $migration->getId()]);
            }
        };
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $device = $this->createStub(Device::class);
        $locks = static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback();

        foreach ($messages as $message) {
            $worker->action(
                message: $message,
                project: $project,
                dbForProject: $database,
                dbForPlatform: $database,
                getDatabasesDB: static fn (Document $document): Database => $database,
                getProjectDB: static fn (Document $document): Database => $database,
                logError: static function (): void {
                },
                queueForRealtime: new Realtime(),
                deviceForMigrations: $device,
                deviceForFiles: $device,
                publisherForMails: new MailPublisher($publisher, $queue),
                usage: new Context(),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                plan: [],
                authorization: new Authorization(),
                locks: $locks,
            );
        }

        $this->assertSame([null, null], $worker->sourceProjects);
    }

    public function testApiKeyFailureAfterClaimFinalizesAttemptForRetry(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationWorkerApiKeyFailure')
            ->setNamespace('migration_worker_api_key_failure_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'databases',
            attributes: [
                new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
                new Attribute('errors', ColumnType::String, size: 1_000_000, array: true),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'teamId' => 'team-1',
        ]);
        $migration = $database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-1',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
            'errors' => [],
        ]));
        $message = new Message([
            'pid' => 'pid-1',
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => (new MigrationMessage(
                project: $project,
                migration: $migration,
            ))->toArray(),
        ]);
        $worker = new class () extends Migrations {
            public int $apiKeyCalls = 0;

            #[\Override]
            protected function generateAPIKey(Document $project): string
            {
                $this->apiKeyCalls++;
                throw new \RuntimeException('API key generation failed');
            }
        };
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $device = $this->createStub(Device::class);
        $locks = static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback();
        $realtime = new class () extends Realtime {
            public int $triggers = 0;

            #[\Override]
            public function trigger(): string|bool
            {
                $this->triggers++;
                throw new \RuntimeException('Realtime unavailable');
            }
        };
        $action = static function () use ($database, $device, $locks, $message, $project, $publisher, $queue, $realtime, $worker): void {
            $worker->action(
                message: $message,
                project: $project,
                dbForProject: $database,
                dbForPlatform: $database,
                getDatabasesDB: static fn (Document $document): Database => $database,
                getProjectDB: static fn (Document $document): Database => $database,
                logError: static function (): void {
                },
                queueForRealtime: $realtime,
                deviceForMigrations: $device,
                deviceForFiles: $device,
                publisherForMails: new MailPublisher($publisher, $queue),
                usage: new Context(),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                plan: [],
                authorization: new Authorization(),
                locks: $locks,
            );
        };

        $action();

        $stored = $database->getDocument('migrations', $migration->getId());
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame('attempt-1', $stored->getAttribute('attemptId'));
        $this->assertCount(1, $stored->getAttribute('errors'));
        $this->assertStringContainsString('unexpected error', (string) $stored->getAttribute('errors')[0]);
        $this->assertSame(1, $realtime->triggers);

        $action();
        $this->assertSame(1, $worker->apiKeyCalls);
        $this->assertSame('failed', $database->getDocument('migrations', $migration->getId())->getAttribute('status'));

        $claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');
        try {
            $migrationPublisher = new MockPublisher();
            $retried = (new Claim($database, $locks))->retry(
                project: $project,
                migrationId: $migration->getId(),
                platform: [],
                publisher: new MigrationPublisher($migrationPublisher, new Queue('migrations')),
            );
        } finally {
            \putenv($claimEnabled === false
                ? '_APP_MIGRATIONS_CLAIM_ENABLED'
                : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $claimEnabled);
        }

        $this->assertSame('pending', $retried->getAttribute('status'));
        $this->assertSame('finished', $retried->getAttribute('stage'));
        $this->assertNotSame('attempt-1', $retried->getAttribute('attemptId'));
        $queued = MigrationMessage::fromArray($migrationPublisher->getEvents('migrations')[0]);
        $this->assertInstanceOf(Document::class, $queued->terminal);
        $this->assertSame('attempt-1', $queued->terminal->getAttribute('attemptId'));
        $this->assertSame('failed', $queued->terminal->getAttribute('status'));
        $this->assertSame('finished', $queued->terminal->getAttribute('stage'));
    }

    public function testActionRefusesLateProgressFailureAndCompletionAfterRetry(): void
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationWorkerGenerationFence')
            ->setNamespace('migration_worker_generation_fence_' . \uniqid());
        $database->create();
        $database->createCollection(new Collection(
            id: 'databases',
            attributes: [
                new Attribute('migrationId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('migrationAttemptId', ColumnType::String, size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(new Collection(
            id: 'migrations',
            attributes: [
                new Attribute('status', ColumnType::String, size: 255, required: true),
                new Attribute('stage', ColumnType::String, size: 255, required: true),
                new Attribute('attemptId', ColumnType::String, size: Database::LENGTH_KEY),
                new Attribute('resourceData', ColumnType::String, size: 131_070, required: true, filters: ['json']),
            ],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'teamId' => 'team-1',
        ]);
        $migration = $database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-a',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
        ]));
        $message = new Message([
            'pid' => 'pid-1',
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => (new MigrationMessage(
                project: $project,
                migration: $migration,
            ))->toArray(),
        ]);
        $migrationPublisher = new MockPublisher();
        $worker = new class () extends Migrations {
            public ?MockPublisher $publisher = null;
            public string $newAttempt = '';
            /** @var array<string> */
            public array $refused = [];

            #[\Override]
            protected function processMigration(
                Document $migration,
                Realtime $queueForRealtime,
                MailPublisher $publisherForMails,
                Context $usage,
                UsagePublisher $publisherForUsage,
                array $platform,
                Authorization $authorization,
            ): void {
                $database = $this->dbForProject ?? throw new \LogicException('Project database missing');
                $project = $this->project ?? throw new \LogicException('Project missing');
                $publisher = $this->publisher ?? throw new \LogicException('Migration publisher missing');

                $database->updateDocument('migrations', $migration->getId(), new Document([
                    'status' => 'failed',
                    'stage' => 'finished',
                ]));
                $retried = (new Claim(
                    $database,
                    static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
                ))->retry(
                    project: $project,
                    migrationId: $migration->getId(),
                    platform: [],
                    publisher: new MigrationPublisher($publisher, new Queue('migrations')),
                );
                $this->newAttempt = (string) $retried->getAttribute('attemptId');

                foreach ([
                    'progress' => ['processing', 'migrating'],
                    'failure' => ['failed', 'finished'],
                    'ready' => ['completed', 'finished'],
                ] as $name => [$status, $stage]) {
                    $late = new Document($migration->getArrayCopy());
                    $late->setAttribute('status', $status);
                    $late->setAttribute('stage', $stage);
                    $late->setAttribute('resourceData', [['state' => $name]]);

                    try {
                        $this->updateMigrationDocument($late, $project, $queueForRealtime);
                    } catch (Superseded) {
                        $this->refused[] = $name;
                    }
                }
            }
        };
        $worker->publisher = $migrationPublisher;
        $realtime = new class () extends Realtime {
            public int $triggers = 0;

            #[\Override]
            public function trigger(): string|bool
            {
                $this->triggers++;

                return true;
            }
        };
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $device = $this->createStub(Device::class);
        $locks = static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback();
        $claimEnabled = \getenv('_APP_MIGRATIONS_CLAIM_ENABLED');
        \putenv('_APP_MIGRATIONS_CLAIM_ENABLED=enabled');
        try {
            $worker->action(
                message: $message,
                project: $project,
                dbForProject: $database,
                dbForPlatform: $database,
                getDatabasesDB: static fn (Document $document): Database => $database,
                getProjectDB: static fn (Document $document): Database => $database,
                logError: static function (): void {
                },
                queueForRealtime: $realtime,
                deviceForMigrations: $device,
                deviceForFiles: $device,
                publisherForMails: new MailPublisher($publisher, $queue),
                usage: new Context(),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                plan: [],
                authorization: new Authorization(),
                locks: $locks,
            );
        } finally {
            \putenv($claimEnabled === false
                ? '_APP_MIGRATIONS_CLAIM_ENABLED'
                : '_APP_MIGRATIONS_CLAIM_ENABLED=' . $claimEnabled);
        }

        $this->assertSame(['progress', 'failure', 'ready'], $worker->refused);
        $stored = $database->getDocument('migrations', $migration->getId());
        $this->assertNotSame('', $worker->newAttempt);
        $this->assertSame($worker->newAttempt, $stored->getAttribute('attemptId'));
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame([], $stored->getAttribute('resourceData'));
        $this->assertSame(0, $realtime->triggers);
        $this->assertCount(1, $migrationPublisher->getEvents('migrations'));
    }

    private function createSourceMock(): Source&MockObject
    {
        $target = $this->createMock(Source::class);
        $target->method('getErrors')->willReturn([]);
        $target->expects($this->once())->method('shutdown');
        $target->expects($this->once())->method('cleanUp');

        return $target;
    }

    private function createDestinationMock(): Destination&MockObject
    {
        $target = $this->createMock(Destination::class);
        $target->method('getErrors')->willReturn([]);
        $target->expects($this->once())->method('shutdown');
        $target->expects($this->once())->method('cleanUp');

        return $target;
    }

    private function createMigration(?string $resourceType = ''): Document
    {
        return new Document([
            '$id' => 'migration',
            '$sequence' => 1,
            'credentials' => [],
            'destination' => 'TestDestination',
            'options' => [],
            'resourceId' => '',
            'resourceType' => $resourceType,
            'resources' => [],
            'source' => 'TestSource',
            'stage' => 'pending',
            'status' => 'pending',
        ]);
    }

    /**
     * @param array<string> $events
     */
    private function createProcessor(
        Source $source,
        Destination $destination,
        array &$events,
        ?\Closure $persist = null,
    ): \Closure {
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };
        $worker = new class ($source, $destination, $record, $persist) extends Migrations {
            public function __construct(
                private readonly Source $migrationSource,
                private readonly Destination $migrationDestination,
                private readonly \Closure $record,
                private readonly ?\Closure $persist,
            ) {
            }

            public function process(
                Document $migration,
                Document $project,
                Realtime $queueForRealtime,
                MailPublisher $publisherForMails,
                Context $usage,
                UsagePublisher $publisherForUsage,
                Authorization $authorization,
            ): void {
                $this->project = $project;
                $this->logError = static function (): void {
                };

                $this->processMigration(
                    $migration,
                    $queueForRealtime,
                    $publisherForMails,
                    $usage,
                    $publisherForUsage,
                    [],
                    $authorization,
                );
            }

            #[\Override]
            protected function generateAPIKey(Document $project): string
            {
                return 'key';
            }

            #[\Override]
            protected function processSource(Document $migration): Source
            {
                return $this->migrationSource;
            }

            #[\Override]
            protected function processDestination(Document $migration): Destination
            {
                return $this->migrationDestination;
            }

            #[\Override]
            protected function updateMigrationDocument(
                Document $migration,
                Document $project,
                Realtime $queueForRealtime,
            ): Document {
                ($this->record)('persist:'
                    . $migration->getAttribute('status')
                    . ':'
                    . $migration->getAttribute('stage'));

                if ($this->persist !== null) {
                    return ($this->persist)($migration);
                }

                return $migration;
            }
        };

        return $worker->process(...);
    }

    private function process(\Closure $processor, Document $migration): void
    {
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $host = \getenv('_APP_MIGRATION_HOST');
        \putenv('_APP_MIGRATION_HOST=localhost');

        try {
            $processor(
                $migration,
                new Document([
                    '$id' => 'project',
                    '$sequence' => 1,
                ]),
                new Realtime(),
                new MailPublisher($publisher, $queue),
                new Context(),
                new UsagePublisher($publisher, $queue),
                new Authorization(),
            );
        } finally {
            \putenv($host === false ? '_APP_MIGRATION_HOST' : '_APP_MIGRATION_HOST=' . $host);
        }
    }
}
