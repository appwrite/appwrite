<?php

namespace Utopia\Migration\Tests\Destinations;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Query;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Exception as MigrationException;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

final class AppwriteRunCompletionTest extends TestCase
{
    private const SOURCE_OLDER = '2000-01-01 00:00:00';

    private const SOURCE_NEWER = '2999-01-01 00:00:00';

    public function testRunMarksItsDatabasesReady(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);

        $this->import($database, $destination, [
            $this->databaseResource('first'),
            $this->databaseResource('second'),
        ]);

        $this->assertSame([], $destination->getErrors());
        $this->assertSame('ready', $this->databaseStatus($database, 'first'), 'run() must flip every database it provisioned to ready');
        $this->assertSame('ready', $this->databaseStatus($database, 'second'), 'run() must flip every database it provisioned to ready');
    }

    public function testInterruptedRunLeavesItsDatabasesProvisioning(): void
    {
        $database = $this->projectDatabase();
        $destination = $this->destination($database);

        try {
            $this->import(
                $database,
                $destination,
                [$this->databaseResource('first')],
                static function (): void {
                    throw new TransferInterrupted('transfer interrupted');
                },
            );
            $this->fail('The interrupted transfer must throw');
        } catch (TransferInterrupted $error) {
            $this->assertSame('transfer interrupted', $error->getMessage());
        }

        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'), 'An interrupted run must never be marked ready');
        $this->assertSame([], $destination->getErrors());
    }

    public function testOneFailedReadyFlipStillSweepsEveryOverwrittenTable(): void
    {
        $database = $this->projectDatabase();
        $this->seedTablesWithLegacyColumns($database);
        $database->failReadyWrites = ['first'];

        $destination = $this->destination($database, OnDuplicate::Overwrite);
        $this->import($database, $destination, $this->newerSchemaWithoutLegacyColumns());

        $this->assertSame(['name'], $this->columnKeys($database, 'first', 'first-items'), 'The sweep must run for the database whose flip failed');
        $this->assertSame(['name'], $this->columnKeys($database, 'second', 'second-items'), 'The sweep must run for every other database');
        $this->assertSame('provisioning', $this->databaseStatus($database, 'first'));
        $this->assertSame('ready', $this->databaseStatus($database, 'second'));
        $this->assertSame(
            [[Resource::TYPE_DATABASE, Transfer::GROUP_DATABASES, 'first']],
            $this->subjects($destination->getErrors()),
        );
        $this->assertSame('ready status unavailable', $destination->getErrors()[0]->getMessage());
    }

    public function testOrphanSweepFailureThrowsAfterEveryDatabaseIsReady(): void
    {
        $database = $this->projectDatabase();
        $this->seedTablesWithLegacyColumns($database);
        $database->failAttributeScanOf = $this->table($database, 'first', 'first-items');

        $destination = $this->destination($database, OnDuplicate::Overwrite);

        try {
            $this->import($database, $destination, $this->newerSchemaWithoutLegacyColumns());
            $this->fail('A failed sweep must fail the run');
        } catch (DatabaseException $error) {
            $this->assertSame('attribute scan unavailable', $error->getMessage());
        }

        $database->failAttributeScanOf = null;
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'first', 'first-items'));
        $this->assertSame('ready', $this->databaseStatus($database, 'first'), 'Databases are flipped before any sweep can fail');
        $this->assertSame('ready', $this->databaseStatus($database, 'second'), 'Databases are flipped before any sweep can fail');
    }

    private function seedTablesWithLegacyColumns(FailingCompletionDatabase $database): void
    {
        $resources = [];
        foreach (['first', 'second'] as $databaseId) {
            $resource = $this->databaseResource($databaseId);
            $table = new Table($resource, 'Items', $databaseId.'-items', updatedAt: self::SOURCE_OLDER);
            \array_push(
                $resources,
                $resource,
                $table,
                (new Text('name', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-name'),
                (new Text('legacy', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-legacy'),
            );
        }

        $destination = $this->destination($database);
        $this->import($database, $destination, $resources);

        $this->assertSame([], $destination->getErrors());
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'first', 'first-items'));
        $this->assertSame(['legacy', 'name'], $this->columnKeys($database, 'second', 'second-items'));
    }

    /**
     * Newer and renamed, because an overwrite only reclaims a database whose source is newer and differs.
     *
     * @return list<Resource>
     */
    private function newerSchemaWithoutLegacyColumns(): array
    {
        $resources = [];
        foreach (['first', 'second'] as $databaseId) {
            $resource = $this->databaseResource($databaseId, 'Renamed', self::SOURCE_NEWER);
            $table = new Table($resource, 'Items', $databaseId.'-items', updatedAt: self::SOURCE_OLDER);
            \array_push(
                $resources,
                $resource,
                $table,
                (new Text('name', $table, size: 64, updatedAt: self::SOURCE_OLDER))->setId($databaseId.'-name'),
            );
        }

        return $resources;
    }

    private function databaseResource(string $id, string $name = 'Database', string $updatedAt = ''): DatabaseResource
    {
        return new DatabaseResource(
            id: $id,
            name: $name,
            updatedAt: $updatedAt,
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        );
    }

    private function destination(
        FailingCompletionDatabase $database,
        OnDuplicate $onDuplicate = OnDuplicate::Fail,
    ): AppwriteDestination {
        return new AppwriteDestination(
            project: 'destination-project',
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $database,
            getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $database,
            collectionStructure: [
                'attributes' => [
                    $this->attributeArray('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                    $this->attributeArray('databaseId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                    $this->attributeArray('name', ColumnType::String, size: 256),
                    $this->attributeArray('description', ColumnType::String, size: 1024),
                    $this->attributeArray('enabled', ColumnType::Boolean, default: true),
                    $this->attributeArray('documentSecurity', ColumnType::Boolean, default: false),
                    $this->attributeArray('search', ColumnType::String, size: 16384),
                ],
                'indexes' => [],
            ],
            dbForPlatform: $database,
            projectInternalId: '1',
            onDuplicate: $onDuplicate,
        );
    }

    /**
     * @param list<Resource> $resources
     */
    private function import(
        FailingCompletionDatabase $database,
        AppwriteDestination $destination,
        array $resources,
        ?callable $callback = null,
    ): void {
        $source = new class () extends MockSource {
            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }
        };
        $types = [];
        foreach ($resources as $resource) {
            $source->pushMockResource($resource);
            $types[$resource->getName()] = true;
        }

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static fn () => $transfer->run(\array_keys($types), $callback ?? static function (): void {
            }),
        );
    }

    private function databaseStatus(FailingCompletionDatabase $database, string $databaseId): mixed
    {
        return $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument('databases', $databaseId),
        )->getAttribute('status');
    }

    private function table(FailingCompletionDatabase $database, string $databaseId, string $tableId): UtopiaDocument
    {
        return $database->getAuthorization()->skip(static function () use ($database, $databaseId, $tableId): UtopiaDocument {
            $metadata = $database->getDocument('databases', $databaseId);

            return $database->getDocument('database_'.$metadata->getSequence(), $tableId);
        });
    }

    /**
     * @return list<string>
     */
    private function columnKeys(FailingCompletionDatabase $database, string $databaseId, string $tableId): array
    {
        $table = $this->table($database, $databaseId, $tableId);
        $columns = $database->getAuthorization()->skip(static fn (): array => $database->find('attributes', [
            Query::equal('databaseInternalId', [$table->getAttribute('databaseInternalId')]),
            Query::equal('collectionInternalId', [$table->getSequence()]),
        ]));
        $keys = \array_map(static fn (UtopiaDocument $column): string => $column->getAttribute('key'), $columns);
        \sort($keys);

        return $keys;
    }

    /**
     * @param array<MigrationException> $errors
     * @return list<array{string, string, string}>
     */
    private function subjects(array $errors): array
    {
        return \array_values(\array_map(
            static fn (MigrationException $error): array => [
                $error->getResourceName(),
                $error->getResourceGroup(),
                $error->getResourceId(),
            ],
            $errors,
        ));
    }

    private function projectDatabase(): FailingCompletionDatabase
    {
        $database = new FailingCompletionDatabase(
            new MemoryAdapter(),
            new Cache(new MemoryCache()),
        );
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_project');
        $database->create();

        $database->createCollection(Collection::create(
            id: 'databases',
            attributes: [
                $this->attribute('name', ColumnType::String, required: true, size: 256),
                $this->attribute('description', ColumnType::String, size: 1024),
                $this->attribute('enabled', ColumnType::Boolean, default: true),
                $this->attribute('search', ColumnType::String, size: 16384),
                $this->attribute('originalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, default: 'tablesdb', size: 128),
                $this->attribute('database', ColumnType::String, size: 2000),
                $this->attribute('status', ColumnType::String, size: 16),
            ],
        ));

        $database->createCollection(Collection::create(
            id: 'attributes',
            attributes: [
                $this->attribute('key', ColumnType::String, size: 256),
                $this->attribute('description', ColumnType::String, size: 1024),
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('databaseId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, size: 256),
                $this->attribute('status', ColumnType::String, size: 64),
                $this->attribute('size', ColumnType::Integer),
                $this->attribute('required', ColumnType::Boolean, default: false),
                $this->attribute('signed', ColumnType::Boolean, default: true),
                $this->attribute('default', ColumnType::String, size: 16384),
                $this->attribute('array', ColumnType::Boolean, default: false),
                $this->attribute('format', ColumnType::String, size: 64),
                $this->attribute('formatOptions', ColumnType::String, size: 16384, filters: ['json']),
                $this->attribute('filters', ColumnType::String, size: 64, array: true),
                $this->attribute('options', ColumnType::String, size: 16384, filters: ['json']),
            ],
        ));

        $database->createCollection(Collection::create(
            id: 'indexes',
            attributes: [
                $this->attribute('key', ColumnType::String, size: 256),
                $this->attribute('description', ColumnType::String, size: 1024),
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
            ],
        ));

        return $database;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeArray(
        string $id,
        ColumnType $type,
        mixed $default = null,
        int $size = 0,
    ): array {
        return [
            '$id' => $id,
            'type' => $type->value,
            'size' => $size,
            'required' => false,
            'default' => $default,
            'array' => false,
            'signed' => true,
            'filters' => [],
        ];
    }

    /**
     * @param array<string> $filters
     */
    private function attribute(
        string $id,
        ColumnType $type,
        bool $required = false,
        mixed $default = null,
        int $size = 0,
        bool $array = false,
        array $filters = [],
    ): UtopiaAttribute {
        return UtopiaAttribute::fromArray([
            'key' => $id,
            'type' => $type,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'filters' => $filters,
        ]);
    }
}
