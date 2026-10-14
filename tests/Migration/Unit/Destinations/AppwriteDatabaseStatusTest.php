<?php

namespace Utopia\Tests\Unit\Destinations;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\DateTime as UtopiaDateTime;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;
use Utopia\Tests\Unit\Adapters\MockSource;

class CountingAppwriteDestination extends AppwriteDestination
{
    public int $runCount = 0;

    #[Override]
    public function run(
        array $resources,
        callable $callback,
        string $rootResourceId = '',
        string $rootResourceType = '',
    ): void {
        $this->runCount++;
        parent::run($resources, $callback, $rootResourceId, $rootResourceType);
    }
}

final class ReloadFailingProjectDatabase extends UtopiaDatabase
{
    public bool $failNextDatabasesRead = false;

    #[Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): UtopiaDocument
    {
        $document = parent::getDocument($collection, $id, $queries, $forUpdate);
        if ($this->failNextDatabasesRead && $collection === 'databases' && !$document->isEmpty()) {
            $this->failNextDatabasesRead = false;

            return new UtopiaDocument();
        }

        return $document;
    }
}

/**
 * Fails the reload and every status write while the metadata store is unavailable,
 * which is the only way production reaches a document stranded in `provisioning`:
 * markDatabaseFailed() swallows its own error so it cannot mask the original throw.
 */
class StrandedProvisioningProjectDatabase extends UtopiaDatabase
{
    public bool $failNextDatabasesRead = false;

    public bool $failDatabasesWrites = false;

    #[Override]
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): UtopiaDocument
    {
        $document = parent::getDocument($collection, $id, $queries, $forUpdate);
        if ($this->failNextDatabasesRead && $collection === 'databases' && !$document->isEmpty()) {
            $this->failNextDatabasesRead = false;

            return new UtopiaDocument();
        }

        return $document;
    }

    #[Override]
    public function updateDocument(string $collection, string $id, UtopiaDocument $document): UtopiaDocument
    {
        if ($this->failDatabasesWrites && $collection === 'databases') {
            throw new DatabaseException('metadata store unavailable');
        }

        return parent::updateDocument($collection, $id, $document);
    }
}

final class ReadyFailingProjectDatabase extends UtopiaDatabase
{
    /** @var list<string> */
    public array $readyWrites = [];

    /** @var list<string> */
    public array $failReadyWrites = [];

    #[Override]
    public function updateDocument(string $collection, string $id, UtopiaDocument $document): UtopiaDocument
    {
        if ($collection === 'databases' && $document->getAttribute('status') === 'ready') {
            $this->readyWrites[] = $id;

            if (\in_array($id, $this->failReadyWrites, true)) {
                throw new DatabaseException('ready status unavailable');
            }
        }

        return parent::updateDocument($collection, $id, $document);
    }
}

final class AppwriteDatabaseStatusTest extends TestCase
{
    public function testDatabaseCreationOmitsStatusThroughLegacyAndExplicitEntrypoints(): void
    {
        foreach ([false, true] as $explicit) {
            $database = $this->createProjectDatabase(withStatus: false);

            $destination = $this->runDatabaseTransfer($database, $explicit);

            $created = $this->getDatabaseDocument($database);
            $this->assertSame([], $this->errorMessages($destination));
            $this->assertSame(1, $destination->runCount);
            $this->assertFalse($created->isEmpty());
            $this->assertArrayNotHasKey('status', $created->getArrayCopy());
            $this->assertNotNull(
                $database->findCollection('database_'.$created->getSequence()),
                'Metadata collection must use the persisted database sequence',
            );
        }
    }

    public function testDatabaseCreationPreservesLifecycleThroughLegacyAndExplicitEntrypoints(): void
    {
        foreach ([false, true] as $explicit) {
            $database = $this->createProjectDatabase(withStatus: true);

            $destination = $this->runDatabaseTransfer($database, $explicit);

            $created = $this->getDatabaseDocument($database);
            $this->assertSame([], $this->errorMessages($destination));
            $this->assertSame(1, $destination->runCount);
            $this->assertFalse($created->isEmpty());
            $this->assertSame('ready', $created->getAttribute('status'));
            $this->assertNotNull(
                $database->findCollection('database_'.$created->getSequence()),
                'Metadata collection must use the persisted database sequence',
            );
        }
    }

    public function testReadyFlipAttemptsEveryDatabaseAndReportsEveryFailure(): void
    {
        $database = new ReadyFailingProjectDatabase(new MemoryAdapter(), new Cache(new MemoryCache()));
        $this->createProjectDatabase(withStatus: true, database: $database);
        $database->failReadyWrites = ['database-first', 'database-third'];

        $destination = $this->runDatabaseTransfer(
            $database,
            explicit: false,
            databaseIds: ['database-first', 'database-second', 'database-third'],
        );

        $errorIds = \array_map(
            static fn (MigrationException $error): string => $error->getResourceId(),
            $destination->getErrors(),
        );

        $this->assertSame(['database-first', 'database-second', 'database-third'], $database->readyWrites);
        $this->assertSame(['database-first', 'database-third'], $errorIds);
        $this->assertSame('provisioning', $this->getDatabaseDocument($database, 'database-first')->getAttribute('status'));
        $this->assertSame('ready', $this->getDatabaseDocument($database, 'database-second')->getAttribute('status'));
        $this->assertSame('provisioning', $this->getDatabaseDocument($database, 'database-third')->getAttribute('status'));
    }

    public function testReloadFailureMarksTheDatabaseFailed(): void
    {
        $database = new ReloadFailingProjectDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $this->createProjectDatabase(withStatus: true, database: $database);
        $database->failNextDatabasesRead = true;

        $destination = $this->runDatabaseTransfer($database, explicit: false);

        $created = $this->getDatabaseDocument($database);
        $this->assertNotSame([], $this->errorMessages($destination));
        $this->assertStringContainsString('Failed to reload created database', $this->errorMessages($destination)[0]);
        $this->assertSame('failed', $created->getAttribute('status'));
        $this->assertNull(
            $database->findCollection('database_'.$created->getSequence()),
            'A reload failure must not leave a backing collection behind the metadata document',
        );
    }

    public function testFailedDatabaseRetrySucceedsUnderOnDuplicateFail(): void
    {
        $database = new ReloadFailingProjectDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $this->createProjectDatabase(withStatus: true, database: $database);
        $database->failNextDatabasesRead = true;

        $this->runDatabaseTransfer($database, explicit: false);

        $failed = $this->getDatabaseDocument($database);
        $this->assertSame('failed', $failed->getAttribute('status'));
        $this->assertNull(
            $database->findCollection('database_'.$failed->getSequence()),
        );

        $destination = $this->runDatabaseTransfer($database, explicit: false);

        $recovered = $this->getDatabaseDocument($database);
        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame('ready', $recovered->getAttribute('status'));
        $this->assertSame($failed->getSequence(), $recovered->getSequence());
        $this->assertNotNull(
            $database->findCollection('database_'.$recovered->getSequence()),
            'A Fail retry must recreate the backing collection for a previously failed database',
        );
    }

    public function testProvisioningDatabaseRetrySucceedsUnderOnDuplicateFail(): void
    {
        $database = new StrandedProvisioningProjectDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $this->createProjectDatabase(withStatus: true, database: $database);
        $database->failNextDatabasesRead = true;
        $database->failDatabasesWrites = true;

        $this->runDatabaseTransfer($database, explicit: false);

        $stranded = $this->getDatabaseDocument($database);
        $this->assertSame(
            'provisioning',
            $stranded->getAttribute('status'),
            'The reload threw and the write that would record the failure threw too, so the document is stranded mid-provision',
        );
        $this->assertNull(
            $database->findCollection('database_'.$stranded->getSequence()),
            'The backing collection was never created, so the database is unusable',
        );
        $database->failDatabasesWrites = false;

        $destination = $this->runDatabaseTransfer($database, explicit: false);

        $recovered = $this->getDatabaseDocument($database);
        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame('ready', $recovered->getAttribute('status'));
        $this->assertSame($stranded->getSequence(), $recovered->getSequence());
        $this->assertNotNull(
            $database->findCollection('database_'.$recovered->getSequence()),
            'A Fail retry must recover a database stranded in provisioning, not keep colliding with its metadata id',
        );
    }

    /** @return array<string, array{OnDuplicate, string}> */
    public static function duplicatePolicies(): array
    {
        return [
            'fail' => [OnDuplicate::Fail, ''],
            'skip' => [OnDuplicate::Skip, ''],
            'overwrite with an older source' => [OnDuplicate::Overwrite, '2000-01-01T00:00:00.000+00:00'],
            'overwrite with a newer source' => [OnDuplicate::Overwrite, '2099-01-01T00:00:00.000+00:00'],
        ];
    }

    #[DataProvider('duplicatePolicies')]
    public function testIncompleteDatabasesAreRecoveredImmediately(OnDuplicate $onDuplicate, string $resourceUpdatedAt): void
    {
        $database = $this->createProjectDatabase(withStatus: true);
        $provisioning = $this->seedIncompleteDatabase($database, 'database-provisioning', 'provisioning', name: 'Incomplete database', withCollection: true, updatedAt: $this->secondsAgo(1));
        $failed = $this->seedIncompleteDatabase($database, 'database-failed', 'failed', name: 'Incomplete database', updatedAt: $this->secondsAgo(1));

        $destination = $this->runDatabaseTransfer(
            $database,
            explicit: false,
            databaseIds: ['database-provisioning', 'database-failed'],
            onDuplicate: $onDuplicate,
            resourceUpdatedAt: $resourceUpdatedAt,
        );

        $this->assertSame([], $this->errorMessages($destination));
        foreach ([$provisioning, $failed] as $seeded) {
            $recovered = $this->getDatabaseDocument($database, $seeded->getId());
            $this->assertSame('ready', $recovered->getAttribute('status'), $seeded->getId());
            $this->assertSame('Database', $recovered->getAttribute('name'), $seeded->getId().' must be overwritten from the source whatever the duplicate policy');
            $this->assertSame($seeded->getSequence(), $recovered->getSequence(), $seeded->getId());
            $this->assertNotNull($database->findCollection('database_'.$recovered->getSequence()), $seeded->getId());
        }
    }

    private function createProjectDatabase(bool $withStatus, ?UtopiaDatabase $database = null): UtopiaDatabase
    {
        $database ??= new UtopiaDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_project');
        $database->create();

        $attributes = [
            $this->attribute('name', ColumnType::String, required: true, size: 256),
            $this->attribute('enabled', ColumnType::Boolean, default: true),
            $this->attribute('search', ColumnType::String, size: 16384),
            $this->attribute('originalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
            $this->attribute('type', ColumnType::String, default: 'tablesdb', size: 128),
            $this->attribute('database', ColumnType::String, size: 2000),
        ];

        if ($withStatus) {
            $attributes[] = $this->attribute('status', ColumnType::String, size: 16);
        }

        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: $attributes,
        ));

        return $database;
    }

    private function seedIncompleteDatabase(
        UtopiaDatabase $database,
        string $databaseId,
        string $status,
        string $name = 'Database',
        bool $withCollection = false,
        string $updatedAt = '',
    ): UtopiaDocument {
        $seeded = $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->withPreserveDates(
                true,
                static fn (): UtopiaDocument => $database->createDocument('databases', new UtopiaDocument([
                    '$id' => $databaseId,
                    'name' => $name,
                    'enabled' => true,
                    'search' => $databaseId.' '.$name,
                    'originalId' => null,
                    'type' => 'tablesdb',
                    'database' => '',
                    'status' => $status,
                    '$createdAt' => $updatedAt,
                    '$updatedAt' => $updatedAt,
                ])),
            ),
        );

        if ($withCollection) {
            $database->createCollection(Collection::create(id: 'database_'.$seeded->getSequence()));
        }

        return $seeded;
    }

    private function secondsAgo(int $seconds): string
    {
        return UtopiaDateTime::format(new \DateTime('-'.$seconds.' seconds'));
    }

    private function attribute(
        string $id,
        ColumnType $type,
        bool $required = false,
        mixed $default = null,
        int $size = 0,
    ): UtopiaAttribute {
        return UtopiaAttribute::fromArray([
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
        ]);
    }

    /**
     * @param list<string> $databaseIds
     */
    private function runDatabaseTransfer(
        UtopiaDatabase $database,
        bool $explicit,
        array $databaseIds = ['database'],
        OnDuplicate $onDuplicate = OnDuplicate::Fail,
        string $resourceUpdatedAt = '',
    ): CountingAppwriteDestination {
        $source = new class () extends MockSource {
            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }
        };
        foreach ($databaseIds as $databaseId) {
            $source->pushMockResource(new DatabaseResource(
                id: $databaseId,
                name: 'Database',
                updatedAt: $resourceUpdatedAt,
                type: 'tablesdb',
                database: 'source-dsn',
                databaseStatus: 'ready',
            ));
        }

        $destination = $this->createDestination($database, $onDuplicate);

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static function () use ($explicit, $transfer): void {
                if ($explicit) {
                    $transfer->runWithResourceSelector(
                        [Resource::TYPE_DATABASE],
                        static function (): void {
                        },
                        resourceId: 'database',
                        resourceInternalId: '1',
                        resourceType: Resource::TYPE_DATABASE,
                        parentResourceId: '',
                        parentResourceInternalId: '',
                        parentResourceType: '',
                    );
                } else {
                    $transfer->run(
                        [Resource::TYPE_DATABASE],
                        static function (): void {
                        },
                    );
                }
            },
        );

        return $destination;
    }

    private function createDestination(UtopiaDatabase $database, OnDuplicate $onDuplicate): CountingAppwriteDestination
    {
        return new CountingAppwriteDestination(
            project: 'destination-project',
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $database,
            getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $database,
            collectionStructure: ['attributes' => [], 'indexes' => []],
            dbForPlatform: $database,
            projectInternalId: '1',
            onDuplicate: $onDuplicate,
        );
    }

    private function getDatabaseDocument(UtopiaDatabase $database, string $databaseId = 'database'): UtopiaDocument
    {
        return $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument('databases', $databaseId),
        );
    }

    /**
     * @return list<string>
     */
    private function errorMessages(AppwriteDestination $destination): array
    {
        return \array_map(
            static fn (\Throwable $error): string => $error->getMessage(),
            $destination->getErrors(),
        );
    }
}
