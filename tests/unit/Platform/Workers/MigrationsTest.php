<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\Platform\Modules\Migrations\Superseded;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Event\MockPublisher;
use Tests\Unit\Network\FixedLookup;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Filter;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Exception\Aborted;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Exception\Finalization;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Resources\Database\Database as ResourceDatabase;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Synchronous as Publisher;
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
            'source:error',
            'destination:error',
            'persist:failed:finished',
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
            'source:error',
            'destination:error',
            'persist:failed:finished',
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

    public function testFailureHooksRunBeforeFailedMigrationIsPersisted(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function (): void {
                throw new \RuntimeException('Upload failed');
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

        $this->process($this->createProcessor($source, $destination, $events), $migration);

        $this->assertSame(
            ['source:error', 'destination:error', 'persist:failed:finished'],
            \array_slice($events, -3)
        );
    }


    public function testAThrowingSourceErrorHookStillLetsTheDestinationRecordTheFailure(): void
    {
        $events = [];
        $source = $this->createSourceMock();
        $destination = $this->createDestinationMock();

        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function (): void {
                throw new \RuntimeException('Upload failed');
            });
        $source
            ->expects($this->once())
            ->method('error')
            ->willThrowException(new \RuntimeException('Lock release failed'));
        $destination
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function () use (&$events): void {
                $events[] = 'destination:error';
            });

        $migration = $this->createMigration();

        $this->process($this->createProcessor($source, $destination, $events), $migration);

        $this->assertContains('destination:error', $events);
        $this->assertSame('persist:failed:finished', \end($events));
        $this->assertSame('failed', $migration->getAttribute('status'));
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
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                Attribute::string('migrationId', size: Database::LENGTH_KEY),
                Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: [
                Attribute::string('status', size: 255, required: true),
                Attribute::string('stage', size: 255, required: true),
                Attribute::string('attemptId', size: Database::LENGTH_KEY),
                Attribute::string('resourceData', size: 131_070, required: true, filters: [Filter::Json]),
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
                queueForRealtime: new Realtime(),
                deviceForMigrations: $device,
                deviceForFiles: $device,
                publisherForMails: new MailPublisher($publisher, $queue),
                usage: new Context(),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                plan: [],
                authorization: new Authorization(),
                locks: $locks,
                publicHostname: new PublicHostname(new PublicInternet(), new FixedLookup()),
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
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                Attribute::string('migrationId', size: Database::LENGTH_KEY),
                Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: [
                Attribute::string('status', size: 255, required: true),
                Attribute::string('stage', size: 255, required: true),
                Attribute::string('attemptId', size: Database::LENGTH_KEY),
                Attribute::string('resourceData', size: 131_070, required: true, filters: [Filter::Json]),
                Attribute::string('errors', size: 1_000_000, array: true),
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
                queueForRealtime: $realtime,
                deviceForMigrations: $device,
                deviceForFiles: $device,
                publisherForMails: new MailPublisher($publisher, $queue),
                usage: new Context(),
                publisherForUsage: new UsagePublisher($publisher, $queue),
                plan: [],
                authorization: new Authorization(),
                locks: $locks,
                publicHostname: new PublicHostname(new PublicInternet(), new FixedLookup()),
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

        $migrationPublisher = new MockPublisher();
        $retried = (new Claim($database, $locks))->retry(
            project: $project,
            migrationId: $migration->getId(),
            platform: [],
            publisher: new MigrationPublisher($migrationPublisher, new Queue('migrations')),
        );

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
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                Attribute::string('migrationId', size: Database::LENGTH_KEY),
                Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY),
            ],
        ));
        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: [
                Attribute::string('status', size: 255, required: true),
                Attribute::string('stage', size: 255, required: true),
                Attribute::string('attemptId', size: Database::LENGTH_KEY),
                Attribute::string('resourceData', size: 131_070, required: true, filters: [Filter::Json]),
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
        $worker->action(
            message: $message,
            project: $project,
            dbForProject: $database,
            dbForPlatform: $database,
            getDatabasesDB: static fn (Document $document): Database => $database,
            getProjectDB: static fn (Document $document): Database => $database,
            queueForRealtime: $realtime,
            deviceForMigrations: $device,
            deviceForFiles: $device,
            publisherForMails: new MailPublisher($publisher, $queue),
            usage: new Context(),
            publisherForUsage: new UsagePublisher($publisher, $queue),
            plan: [],
            authorization: new Authorization(),
            locks: $locks,
            publicHostname: new PublicHostname(new PublicInternet(), new FixedLookup()),
        );

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

    public function testInProcessRetryRunsTheNextAttemptThroughTheClaimProtocol(): void
    {
        $database = $this->createClaimDatabase();
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
        $worker = new class () extends Migrations {
            /** @var array<string> */
            public array $attempts = [];

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
                $project = $this->project ?? throw new \LogicException('Project missing');
                $this->attempts[] = (string) $migration->getAttribute('attemptId');
                $first = \count($this->attempts) === 1;

                $migration->setAttribute('status', $first ? 'failed' : 'completed');
                $migration->setAttribute('stage', 'finished');
                $migration->setAttribute('errors', $first ? ['Dedicated database is still starting'] : []);
                $this->updateMigrationDocument($migration, $project, $queueForRealtime);
            }
        };
        $queued = $this->migrationDelivery($project, $migration);

        $this->deliverClaim($worker, $database, $project, $queued);

        $failed = $database->getDocument('migrations', $migration->getId());
        $this->assertSame(['attempt-1'], $worker->attempts);
        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertSame(['Dedicated database is still starting'], $failed->getAttribute('errors'));

        $retry = (new Claim($database, $this->claimLocks()))->reclaim($project->getId(), $migration->getId());
        $this->deliverClaim($worker, $database, $project, new Message([
            'pid' => 'pid-retry',
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => $retry->message($project)->toArray(),
        ]));

        $completed = $database->getDocument('migrations', $migration->getId());
        $this->assertSame(['attempt-1', $retry->migration->getAttribute('attemptId')], $worker->attempts);
        $this->assertSame('completed', $completed->getAttribute('status'));
        $this->assertSame('finished', $completed->getAttribute('stage'));
        $this->assertSame([], $completed->getAttribute('errors'));
        $this->assertSame($retry->migration->getAttribute('attemptId'), $completed->getAttribute('attemptId'));

        $this->deliverClaim($worker, $database, $project, $queued);
        $this->assertCount(2, $worker->attempts, 'A replay of the first delivery must not run a third attempt');
    }

    public function testRedeliveryResumesAnAttemptWhoseWorkerDied(): void
    {
        $database = $this->createClaimDatabase();
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
        $worker = new class () extends Migrations {
            /** @var array<string> */
            public array $attempts = [];

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
                $project = $this->project ?? throw new \LogicException('Project missing');
                $this->attempts[] = (string) $migration->getAttribute('attemptId');

                $migration->setAttribute('stage', 'migrating');
                $migration = $this->updateMigrationDocument($migration, $project, $queueForRealtime);

                if (\count($this->attempts) === 1) {
                    return;
                }

                $migration->setAttribute('status', 'completed');
                $migration->setAttribute('stage', 'finished');
                $this->updateMigrationDocument($migration, $project, $queueForRealtime);
            }
        };
        $delivery = $this->migrationDelivery($project, $migration);

        $this->deliverClaim($worker, $database, $project, $delivery);
        $database->setPreserveDates(true);
        try {
            $database->updateDocument('migrations', $migration->getId(), new Document([
                '$updatedAt' => DateTime::addSeconds(new \DateTime(), -Claim::LIVENESS_LEASE - 60),
                'errors' => ['last progress write before the worker died'],
            ]));
        } finally {
            $database->setPreserveDates(false);
        }

        $this->deliverClaim($worker, $database, $project, $delivery);

        $this->assertCount(2, $worker->attempts);
        $this->assertSame('attempt-1', $worker->attempts[0]);
        $this->assertNotSame('attempt-1', $worker->attempts[1]);
        $stored = $database->getDocument('migrations', $migration->getId());
        $this->assertSame('completed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame($worker->attempts[1], $stored->getAttribute('attemptId'));
    }

    public function testActionRunsADeliveryBeforeTheOwnershipSchemaUnclaimedAsOnMain(): void
    {
        $database = $this->createClaimDatabase(ownership: false);
        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'teamId' => 'team-1',
        ]);
        $migration = $database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
            'errors' => [],
        ]));
        $worker = new class () extends Migrations {
            /** @var array<mixed> */
            public array $attempts = [];

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
                $this->attempts[] = $migration->getAttribute('attemptId');
            }
        };
        $delivery = $this->migrationDelivery($project, $migration);

        $this->deliverClaim($worker, $database, $project, $delivery);

        $this->assertSame([null], $worker->attempts, 'Main ran every delivery, before V26 without an attempt');
        $stored = $database->getDocument('migrations', $migration->getId());
        $this->assertSame('pending', $stored->getAttribute('status'));
        $this->assertSame('init', $stored->getAttribute('stage'));
        $this->assertSame($migration->getUpdatedAt(), $stored->getUpdatedAt());

        $database->createAttribute('databases', Attribute::string('migrationId', size: Database::LENGTH_KEY));
        $database->createAttribute('databases', Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY));
        $database->createAttribute('migrations', Attribute::string('attemptId', size: Database::LENGTH_KEY));

        $this->deliverClaim($worker, $database, $project, $delivery);

        $this->assertCount(2, $worker->attempts);
        $this->assertIsString($worker->attempts[1]);
        $this->assertSame($worker->attempts[1], $database->getDocument('migrations', $migration->getId())->getAttribute('attemptId'));
    }

    public function testRealtimeUpdatesCarryMainsPayloadAndSkipTheFinalizingClaim(): void
    {
        $database = $this->createClaimDatabase();
        $migration = $database->createDocument('migrations', new Document([
            '$id' => 'migration-1',
            'attemptId' => 'attempt-1',
            'status' => 'processing',
            'stage' => 'migrating',
            'resourceData' => [],
            'errors' => [],
        ]));
        $realtime = new class () extends Realtime {
            /** @var array<array<string, mixed>> */
            public array $payloads = [];

            #[\Override]
            public function trigger(): string|bool
            {
                $this->payloads[] = $this->getPayload();

                return true;
            }
        };
        $worker = new class ($database) extends Migrations {
            public function __construct(Database $database)
            {
                $this->claims = new Claim($database);
            }

            public function update(Document $migration, Realtime $realtime, bool $publish): Document
            {
                return $this->updateMigrationDocument($migration, new Document(['$id' => 'project-1']), $realtime, $publish);
            }
        };

        $migration->setAttribute('resourceData', '{}');
        $migration->setAttribute('statusCounters', '{"user":{"success":1}}');
        $migration = $worker->update($migration, $realtime, true);
        $migration->setAttribute('stage', Claim::STAGE_FINALIZING);
        $worker->update($migration, $realtime, false);

        $this->assertCount(1, $realtime->payloads, 'Main published no update while it ran the success hooks');
        $this->assertArrayNotHasKey('attemptId', $realtime->payloads[0], 'Main had no attempts to publish');
        $this->assertSame('migrating', $realtime->payloads[0]['stage']);
        $this->assertSame('{}', $realtime->payloads[0]['resourceData'], 'Main published the worker document as it set it');
        $this->assertSame('{"user":{"success":1}}', $realtime->payloads[0]['statusCounters']);
        $this->assertSame(Claim::STAGE_FINALIZING, $database->getDocument('migrations', 'migration-1')->getAttribute('stage'));
    }

    public function testRealtimeUpdatesCarryTheUpdateTimestampMainEnqueued(): void
    {
        $database = $this->createClaimDatabase();
        $project = new Document(['$id' => 'project-1', '$sequence' => 1, 'teamId' => 'team-1']);
        $created = $database->createDocument('migrations', new Document([
            '$id' => 'migration-new',
            'attemptId' => 'attempt-1',
            'status' => 'pending',
            'stage' => 'init',
            'resourceData' => [],
            'errors' => [],
        ]));
        $failed = $database->createDocument('migrations', new Document([
            '$id' => 'migration-failed',
            'attemptId' => 'attempt-terminal',
            'status' => 'failed',
            'stage' => 'finished',
            'resourceData' => [],
            'errors' => [],
        ]));
        \usleep(5_000);
        $retry = (new Claim($database, $this->claimLocks()))->reclaim($project->getId(), $failed->getId());
        \usleep(5_000);

        $realtime = new class () extends Realtime {
            /** @var array<string, string> */
            public array $updatedAt = [];

            #[\Override]
            public function trigger(): string|bool
            {
                $payload = $this->getPayload();
                $this->updatedAt[$payload['$id']] = $payload['$updatedAt'];

                return true;
            }
        };
        $worker = new class () extends Migrations {
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
                $migration->setAttribute('stage', 'migrating');
                $this->updateMigrationDocument($migration, $this->project ?? throw new \LogicException('Project missing'), $queueForRealtime);
            }
        };

        $this->deliverClaim($worker, $database, $project, $this->migrationDelivery($project, $created), $realtime);
        $this->deliverClaim($worker, $database, $project, new Message([
            'pid' => 'pid-retry',
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => $retry->message($project)->toArray(),
        ]), $realtime);

        $this->assertSame($created->getCreatedAt(), $realtime->updatedAt['migration-new'], 'Main published a new migration as it created it');
        $this->assertSame($failed->getUpdatedAt(), $realtime->updatedAt['migration-failed'], 'Main published a retried migration as it failed');
        $this->assertNotSame($failed->getUpdatedAt(), $database->getDocument('migrations', 'migration-failed')->getUpdatedAt());
    }

    private function createClaimDatabase(bool $ownership = true): Database
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDropUnknownAttributes(true)
            ->setDatabase('migrationWorkerClaims')
            ->setNamespace('migration_worker_claims_' . \uniqid());
        $database->create();
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: $ownership
                ? [
                    Attribute::string('migrationId', size: Database::LENGTH_KEY),
                    Attribute::string('migrationAttemptId', size: Database::LENGTH_KEY),
                ]
                : [Attribute::string('name', size: 256)],
        ));
        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: \array_values(\array_filter([
                Attribute::string('status', size: 255, required: true),
                Attribute::string('stage', size: 255, required: true),
                $ownership ? Attribute::string('attemptId', size: Database::LENGTH_KEY) : null,
                Attribute::string('resourceData', size: 131_070, required: true, filters: [Filter::Json]),
                Attribute::string('errors', size: 1_000_000, array: true),
            ])),
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));

        return $database;
    }

    private function migrationDelivery(Document $project, Document $migration): Message
    {
        return new Message([
            'pid' => 'pid-' . $migration->getId(),
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => (new MigrationMessage(
                project: $project,
                migration: $migration,
            ))->toArray(),
        ]);
    }

    private function claimLocks(): \Closure
    {
        return static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback();
    }

    private function deliverClaim(Migrations $worker, Database $database, Document $project, Message $message, ?Realtime $realtime = null): void
    {
        $publisher = $this->createStub(Publisher::class);
        $queue = new Queue('test');
        $device = $this->createStub(Device::class);

        $worker->action(
            message: $message,
            project: $project,
            dbForProject: $database,
            dbForPlatform: $database,
            getDatabasesDB: static fn (Document $document): Database => $database,
            getProjectDB: static fn (Document $document): Database => $database,
            queueForRealtime: $realtime ?? new Realtime(),
            deviceForMigrations: $device,
            deviceForFiles: $device,
            publisherForMails: new MailPublisher($publisher, $queue),
            usage: new Context(),
            publisherForUsage: new UsagePublisher($publisher, $queue),
            plan: [],
            authorization: new Authorization(),
            locks: $this->claimLocks(),
            publicHostname: new PublicHostname(new PublicInternet(), new FixedLookup()),
        );
    }

    public function testSupersededProgressCallbackStopsTransferBeforeTheNextGroup(): void
    {
        $events = [];
        $exported = [];
        $imported = [];

        $source = $this->createGroupedSource(
            true,
            static function (string $group) use (&$exported): void {
                $exported[] = $group;
            },
        );
        $destination = $this->createGroupedDestination(
            static function (string $group) use (&$imported): void {
                $imported[] = $group;
            },
        );

        $migration = $this->createMigration();
        $migration->setAttribute('resources', [Resource::TYPE_USER, Resource::TYPE_DATABASE]);

        $processor = $this->createProcessor(
            $source,
            $destination,
            $events,
            static function (Document $migration) use (&$events): Document {
                if (\count(\array_keys($events, 'persist:processing:migrating', true)) > 1) {
                    throw new Superseded('Migration attempt was superseded');
                }

                return $migration;
            },
        );

        $this->process($processor, $migration);

        $this->assertSame([Transfer::GROUP_AUTH], $exported, 'The transfer exported a group after the superseded progress write.');
        $this->assertSame([Transfer::GROUP_AUTH], $imported);
        $this->assertSame([], $source->getErrors(), 'The superseded progress write was recorded as a resource error.');
        $this->assertSame([
            'persist:processing:processing',
            'persist:processing:migrating',
            'persist:processing:migrating',
        ], $events);
        $this->assertSame('processing', $migration->getAttribute('status'));
        $this->assertSame('migrating', $migration->getAttribute('stage'));
    }

    public function testSupersededProgressCallbackEndsTransferEvenWhenSourceRecordsIt(): void
    {
        $events = [];
        $exported = [];

        $source = $this->createGroupedSource(
            false,
            static function (string $group) use (&$exported): void {
                $exported[] = $group;
            },
        );
        $destination = $this->createGroupedDestination(static function (string $group): void {
        });

        $migration = $this->createMigration();
        $migration->setAttribute('resources', [Resource::TYPE_USER, Resource::TYPE_DATABASE]);

        $processor = $this->createProcessor(
            $source,
            $destination,
            $events,
            static function (Document $migration) use (&$events): Document {
                if (\count(\array_keys($events, 'persist:processing:migrating', true)) > 1) {
                    throw new Superseded('Migration attempt was superseded');
                }

                return $migration;
            },
        );

        $this->process($processor, $migration);

        $this->assertSame([Transfer::GROUP_AUTH, Transfer::GROUP_DATABASES], $exported);
        $this->assertNotContains('persist:processing:finalizing', $events);
        $this->assertNotContains('persist:failed:finished', $events);
        $this->assertSame('processing', $migration->getAttribute('status'), 'The transfer did not end with the superseded attempt.');
        $this->assertSame('migrating', $migration->getAttribute('stage'));
    }

    public function testFinalizationFailureStoresOneErrorPerRecordedFailure(): void
    {
        $events = [];
        $failures = [
            new MigrationException(
                resourceName: Resource::TYPE_DATABASE,
                resourceGroup: Transfer::GROUP_DATABASES,
                resourceId: 'first',
                message: 'Database status could not be updated',
                code: MigrationException::CODE_INTERNAL,
            ),
            new MigrationException(
                resourceName: Resource::TYPE_TABLE,
                resourceGroup: Transfer::GROUP_DATABASES,
                resourceId: 'second',
                message: 'Table could not be swept',
                code: MigrationException::CODE_INTERNAL,
            ),
        ];

        $recorded = [];
        $source = $this->createSourceMock();
        $destination = $this->createMock(Destination::class);
        $destination->expects($this->once())->method('shutdown');
        $destination->expects($this->once())->method('cleanUp');
        $destination->method('getErrors')->willReturnCallback(static function () use (&$recorded): array {
            return $recorded;
        });
        $destination
            ->expects($this->once())
            ->method('success')
            ->willReturnCallback(static function () use (&$recorded, $failures): void {
                $recorded = $failures;

                throw new Finalization($failures);
            });
        $source->expects($this->never())->method('success');

        $migration = $this->createMigration();
        $processor = $this->createProcessor($source, $destination, $events);

        $this->process($processor, $migration);

        $this->assertSame('failed', $migration->getAttribute('status'));
        $this->assertSame('finished', $migration->getAttribute('stage'));

        $stored = $migration->getAttribute('errors');
        $this->assertCount(2, $stored, 'A failed finalization is listed more than once per recorded failure.');
        $this->assertSame(
            ['first', 'second'],
            \array_map(static fn (string $error): string => \json_decode($error, true)['resourceId'], $stored),
        );
    }

    private function createGroupedSource(bool $rethrowsAbort, \Closure $record): Source
    {
        return new class ($rethrowsAbort, $record) extends Source {
            public function __construct(
                private readonly bool $rethrowsAbort,
                private readonly \Closure $record,
            ) {
            }

            public static function getName(): string
            {
                return 'TestSource';
            }

            public static function getSupportedResources(): array
            {
                return [Resource::TYPE_USER, Resource::TYPE_DATABASE];
            }

            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }

            #[\Override]
            protected function exportGroupAuth(int $batchSize, array $resources): void
            {
                $this->export(Resource::TYPE_USER, Transfer::GROUP_AUTH, new User('user', 'user@example.test'));
            }

            #[\Override]
            protected function exportGroupDatabases(int $batchSize, array $resources): void
            {
                $this->export(Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, new ResourceDatabase('database', 'database'));
            }

            #[\Override]
            protected function exportGroupStorage(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupFunctions(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupMessaging(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupSites(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupIntegrations(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupBackups(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupProjects(int $batchSize, array $resources): void
            {
            }

            #[\Override]
            protected function exportGroupDomains(int $batchSize, array $resources): void
            {
            }

            private function export(string $type, string $group, Resource $resource): void
            {
                ($this->record)($group);

                try {
                    $this->callback([$resource]);
                } catch (Aborted $abort) {
                    if ($this->rethrowsAbort) {
                        throw $abort;
                    }

                    $this->addError(new MigrationException(
                        resourceName: $type,
                        resourceGroup: $group,
                        message: $abort->getMessage(),
                        code: MigrationException::CODE_INTERNAL,
                        previous: $abort,
                    ));
                } catch (\Throwable $error) {
                    $this->addError(new MigrationException(
                        resourceName: $type,
                        resourceGroup: $group,
                        message: $error->getMessage(),
                        code: MigrationException::CODE_INTERNAL,
                        previous: $error,
                    ));
                }
            }
        };
    }

    private function createGroupedDestination(\Closure $record): Destination
    {
        return new class ($record) extends Destination {
            public function __construct(private readonly \Closure $record)
            {
            }

            public static function getName(): string
            {
                return 'TestDestination';
            }

            public static function getSupportedResources(): array
            {
                return [Resource::TYPE_USER, Resource::TYPE_DATABASE];
            }

            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }

            #[\Override]
            protected function import(array $resources, callable $callback): void
            {
                foreach ($resources as $resource) {
                    $resource->setStatus(Resource::STATUS_SUCCESS);
                    ($this->record)($resource->getGroup());
                }

                $callback($resources);
            }
        };
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
                bool $publish = true,
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
