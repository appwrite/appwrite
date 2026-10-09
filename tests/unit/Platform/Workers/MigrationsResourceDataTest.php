<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Event\Realtime;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Workers\Migrations;
use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Network\FixedLookup;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Client\Destinations\PublicInternet;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Migration\Destination;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Auth\User;
use Utopia\Migration\Source;
use Utopia\Migration\Transfer;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\Queue\Queue;
use Utopia\Storage\Device;

final class MigrationsResourceDataTest extends TestCase
{
    private const string MIGRATION_ID = 'migration';

    private const array ENVIRONMENT = [
        '_APP_MIGRATION_HOST' => 'localhost',
        '_APP_OPENSSL_KEY_V1' => 'migration-report-test-key',
    ];

    /**
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (self::ENVIRONMENT as $name => $value) {
            $this->environment[$name] = \getenv($name);
            \putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testLargeMigrationImportsEveryResourceAndKeepsMainsResourceData(): void
    {
        $failing = \array_map(self::userId(...), \range(0, 2_999, 100));
        $imported = new \ArrayObject();
        $database = $this->createDatabase([Resource::TYPE_USER]);

        $this->migrate(
            $database,
            $this->createSource(users: 3_000, batch: 500),
            $this->createDestination($failing, $imported),
        );

        $stored = $database->getDocument('migrations', self::MIGRATION_ID);
        $this->assertCount(3_000, $imported, 'Every user must reach the destination');
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertCount(\count($failing), $stored->getAttribute('errors'));
        $this->assertSame(2_970, $stored->getAttribute('statusCounters')[Resource::TYPE_USER][Resource::STATUS_SUCCESS]);
        $this->assertSame(30, $stored->getAttribute('statusCounters')[Resource::TYPE_USER][Resource::STATUS_ERROR]);
        $this->assertSame([], $stored->getAttribute('resourceData'), 'Main stored the encoded transfer cache, which is always {}');
    }

    public function testSmallMigrationKeepsMainsResourceData(): void
    {
        $imported = new \ArrayObject();
        $database = $this->createDatabase([Resource::TYPE_USER]);

        $this->migrate(
            $database,
            $this->createSource(users: 5, batch: 2),
            $this->createDestination([self::userId(3)], $imported),
        );

        $stored = $database->getDocument('migrations', self::MIGRATION_ID);
        $this->assertCount(5, $imported);
        $this->assertSame('failed', $stored->getAttribute('status'));
        $this->assertSame('finished', $stored->getAttribute('stage'));
        $this->assertSame([], $stored->getAttribute('resourceData'), 'Main stored the encoded transfer cache, which is always {}');
    }

    public function testATerminalWriteThatFailsSurfacesItsErrorWithoutARetry(): void
    {
        $writes = 0;
        $database = $this->createDatabase([Resource::TYPE_USER]);

        try {
            $this->migrate(
                $database,
                $this->createSource(users: 5, batch: 2),
                $this->createDestination([], new \ArrayObject()),
                static function () use (&$writes): void {
                    $writes++;

                    throw new Structure('Invalid document structure: Attribute "errors" has invalid type.');
                },
            );
            $this->fail('A terminal write that fails must surface its error as on main');
        } catch (Structure $error) {
            $this->assertStringContainsString('"errors"', $error->getMessage());
        }

        $this->assertSame(1, $writes);
        $this->assertSame('processing', $database->getDocument('migrations', self::MIGRATION_ID)->getAttribute('status'));
    }

    private static function userId(int $index): string
    {
        return \sprintf('user%024d', $index);
    }

    /**
     * @param array<string> $resources
     */
    private function createDatabase(array $resources): Database
    {
        $database = new Database(new Memory(), new Cache(new NoCache()));
        $database
            ->setAuthorization(new Authorization())
            ->setDatabase('migrationReports')
            ->setNamespace('migration_reports_' . \uniqid());
        $database->create();
        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: Config::getParam('collections', [])['projects']['databases']['attributes'],
        ));
        $database->createCollection(Collection::create(
            id: 'migrations',
            attributes: Config::getParam('collections', [])['projects']['migrations']['attributes'],
            permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
            documentSecurity: false,
        ));
        $database->createDocument('migrations', new Document([
            '$id' => self::MIGRATION_ID,
            'attemptId' => 'attempt-1',
            'status' => 'pending',
            'stage' => 'init',
            'source' => 'Test',
            'destination' => 'Test',
            'resources' => $resources,
            'statusCounters' => [],
            'resourceData' => [],
            'errors' => [],
        ]));

        return $database;
    }

    private function createSource(int $users, int $batch): Source
    {
        return new class ($users, $batch) extends Source {
            public function __construct(
                private readonly int $users,
                private readonly int $batch,
            ) {
            }

            public static function getName(): string
            {
                return 'Test';
            }

            public static function getSupportedResources(): array
            {
                return [Resource::TYPE_USER, Resource::TYPE_DATABASE];
            }

            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }

            public function getAuthBatchSize(): int
            {
                return $this->batch;
            }

            protected function exportGroupAuth(int $batchSize, array $resources): void
            {
                try {
                    for ($offset = 0; $offset < $this->users; $offset += $batchSize) {
                        $users = [];
                        for ($index = $offset; $index < \min($offset + $batchSize, $this->users); $index++) {
                            $users[] = new User(\sprintf('user%024d', $index), 'user' . $index . '@example.com', 'User ' . $index);
                        }

                        $this->callback($users);
                    }
                } catch (\Throwable $error) {
                    $this->addError(new MigrationException(
                        Resource::TYPE_USER,
                        Transfer::GROUP_AUTH,
                        message: $error->getMessage(),
                        code: (int) $error->getCode() ?: MigrationException::CODE_INTERNAL,
                        previous: $error,
                    ));
                }
            }

            protected function exportGroupDatabases(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupStorage(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupFunctions(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupMessaging(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupSites(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupIntegrations(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupBackups(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupProjects(int $batchSize, array $resources): void
            {
            }

            protected function exportGroupDomains(int $batchSize, array $resources): void
            {
            }
        };
    }

    /**
     * @param array<string> $failing
     * @param \ArrayObject<int, string> $imported
     */
    private function createDestination(array $failing, \ArrayObject $imported): Destination
    {
        return new class ($failing, $imported) extends Destination {
            /**
             * @param array<string> $failing
             * @param \ArrayObject<int, string> $imported
             */
            public function __construct(
                private readonly array $failing,
                private readonly \ArrayObject $imported,
            ) {
            }

            public static function getName(): string
            {
                return 'Test';
            }

            public static function getSupportedResources(): array
            {
                return [Resource::TYPE_USER, Resource::TYPE_DATABASE];
            }

            public function report(array $resources = [], array $resourceIds = []): array
            {
                return [];
            }

            protected function import(array $resources, callable $callback): void
            {
                foreach ($resources as $resource) {
                    if (\in_array($resource->getId(), $this->failing, true)) {
                        $resource->setStatus(Resource::STATUS_ERROR, 'User already exists on the destination');
                        $this->addError(new MigrationException(
                            resourceName: $resource->getName(),
                            resourceGroup: $resource->getGroup(),
                            resourceId: $resource->getId(),
                            message: 'User already exists on the destination',
                        ));
                    } else {
                        $resource->setStatus(Resource::STATUS_SUCCESS);
                    }

                    $this->imported->append($resource->getId());
                    $this->cache->update($resource);
                }

                $callback($resources);
            }
        };
    }

    /**
     * @param (\Closure(Document): void)|null $intercept Runs before each terminal write reaches the claim.
     */
    private function migrate(Database $database, Source $source, Destination $destination, ?\Closure $intercept = null): void
    {
        $worker = new class ($source, $destination, $intercept) extends Migrations {
            public function __construct(
                private readonly Source $migrationSource,
                private readonly Destination $migrationDestination,
                private readonly ?\Closure $intercept,
            ) {
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
            protected function updateMigrationDocument(Document $migration, Document $project, Realtime $queueForRealtime, bool $publish = true): Document
            {
                if ($this->intercept !== null && $migration->getAttribute('stage') === 'finished') {
                    ($this->intercept)($migration);
                }

                return parent::updateMigrationDocument($migration, $project, $queueForRealtime, $publish);
            }
        };

        $project = new Document([
            '$id' => 'project-1',
            '$sequence' => 1,
            'teamId' => 'team-1',
        ]);
        $message = new Message([
            'pid' => 'pid-1',
            'queue' => 'v1-migrations',
            'timestamp' => \time(),
            'payload' => (new MigrationMessage(
                project: $project,
                migration: $database->getDocument('migrations', self::MIGRATION_ID),
            ))->toArray(),
        ]);
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
            queueForRealtime: new class () extends Realtime {
                #[\Override]
                public function trigger(): string|bool
                {
                    return true;
                }
            },
            deviceForMigrations: $device,
            deviceForFiles: $device,
            publisherForMails: new MailPublisher($publisher, $queue),
            usage: new Context(),
            publisherForUsage: new UsagePublisher($publisher, $queue),
            plan: [],
            authorization: new Authorization(),
            locks: static fn (string $key, int $ttl, callable $callback, float $timeout): mixed => $callback(),
            publicHostname: new PublicHostname(new PublicInternet(), new FixedLookup()),
        );
    }
}
