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
use Utopia\Database\Query;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Row;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

final class AppwriteRowImportTest extends TestCase
{
    private const string DATABASE_ID = 'shop';

    private const string TABLE_ID = 'items';

    private UtopiaDatabase $database;

    private AppwriteDestination $destination;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
        $this->database = $this->projectDatabase();
        $this->destination = $this->destination();
    }

    public function testRowImportDropsConsecutiveUndeclaredFields(): void
    {
        $this->import(['name'], [
            'row' => [
                'name' => 'kept',
                'orphanFirst' => 'dropped',
                'orphanSecond' => 'dropped',
                'orphanThird' => 'dropped',
            ],
        ]);

        $this->assertSame([], $this->destination->getErrors());
        $this->assertSame(['name' => 'kept'], $this->storedFields('row'));
    }

    public function testRowImportKeepsDeclaredFieldsBetweenUndeclaredOnes(): void
    {
        $this->import(['name', 'title'], [
            'row' => [
                'orphanFirst' => 'dropped',
                'name' => 'kept',
                'orphanSecond' => 'dropped',
                'orphanThird' => 'dropped',
                'title' => 'also kept',
            ],
        ]);

        $this->assertSame([], $this->destination->getErrors());
        $this->assertSame(['name' => 'kept', 'title' => 'also kept'], $this->storedFields('row'));
    }

    public function testRowImportStoresFullyDeclaredRowsUnchanged(): void
    {
        $this->import(['name', 'title'], [
            'first' => ['name' => 'one', 'title' => 'first title'],
            'second' => ['name' => 'two', 'title' => 'second title'],
        ]);

        $this->assertSame([], $this->destination->getErrors());
        $this->assertSame(['name' => 'one', 'title' => 'first title'], $this->storedFields('first'));
        $this->assertSame(['name' => 'two', 'title' => 'second title'], $this->storedFields('second'));
    }

    /**
     * @param list<string> $columnKeys
     * @param array<string, array<string, mixed>> $rows
     */
    private function import(array $columnKeys, array $rows): void
    {
        $database = new DatabaseResource(
            id: self::DATABASE_ID,
            name: 'Shop',
            type: 'tablesdb',
            database: 'source-dsn',
            databaseStatus: 'ready',
        );
        $table = new Table($database, 'Items', self::TABLE_ID);

        $source = new class () extends MockSource {
            #[Override]
            public function supportsDatabaseStatus(): bool
            {
                return true;
            }
        };
        $source->pushMockResource($database);
        $source->pushMockResource($table);
        foreach ($columnKeys as $columnKey) {
            $source->pushMockResource((new Text($columnKey, $table, size: 64))->setId($columnKey));
        }
        foreach ($rows as $rowId => $data) {
            $source->pushMockResource(new Row($rowId, $table, $data));
        }

        $transfer = new Transfer($source, $this->destination);
        $this->database->getAuthorization()->skip(
            static fn () => $transfer->run(
                [Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN, Resource::TYPE_ROW],
                static function (): void {
                },
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedFields(string $rowId): array
    {
        $database = $this->database;
        $row = $database->getAuthorization()->skip(static function () use ($database, $rowId): UtopiaDocument {
            $metadata = $database->getDocument('databases', self::DATABASE_ID);
            $table = $database->getDocument('database_'.$metadata->getSequence(), self::TABLE_ID);

            return $database->getDocument(
                'database_'.$metadata->getSequence().'_collection_'.$table->getSequence(),
                $rowId,
            );
        });

        $this->assertFalse($row->isEmpty(), "Row '{$rowId}' must be stored");

        return \array_filter(
            $row->getArrayCopy(),
            static fn (string $key): bool => !\str_starts_with($key, '$'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private static function registerSubqueryFilters(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        UtopiaDatabase::addFilter(
            'subQueryAttributes',
            static fn (mixed $value) => null,
            static fn (mixed $value, UtopiaDocument $document, UtopiaDatabase $database): array => $database->getAuthorization()->skip(
                static fn (): array => $database->find('attributes', [
                    Query::equal('collectionInternalId', [$document->getSequence()]),
                    Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                ]),
            ),
        );
        UtopiaDatabase::addFilter(
            'subQueryIndexes',
            static fn (mixed $value) => null,
            static fn (mixed $value, UtopiaDocument $document, UtopiaDatabase $database): array => $database->getAuthorization()->skip(
                static fn (): array => $database->find('indexes', [
                    Query::equal('collectionInternalId', [$document->getSequence()]),
                    Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                ]),
            ),
        );
    }

    private function destination(): AppwriteDestination
    {
        $database = $this->database;

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
                    $this->attributeArray('enabled', ColumnType::Boolean, default: true),
                    $this->attributeArray('documentSecurity', ColumnType::Boolean, default: false),
                    $this->attributeArray('search', ColumnType::String, size: 16384),
                    $this->attributeArray('attributes', ColumnType::String, size: 16384, filters: ['subQueryAttributes']),
                    $this->attributeArray('indexes', ColumnType::String, size: 16384, filters: ['subQueryIndexes']),
                ],
                'indexes' => [],
            ],
            dbForPlatform: $database,
            projectInternalId: '1',
        );
    }

    private function projectDatabase(): UtopiaDatabase
    {
        $database = new UtopiaDatabase(
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
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
            ],
        ));

        return $database;
    }

    /**
     * @param array<string> $filters
     * @return array<string, mixed>
     */
    private function attributeArray(
        string $id,
        ColumnType $type,
        mixed $default = null,
        int $size = 0,
        array $filters = [],
    ): array {
        return [
            '$id' => $id,
            'type' => $type->value,
            'size' => $size,
            'required' => false,
            'default' => $default,
            'array' => false,
            'signed' => true,
            'filters' => $filters,
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
