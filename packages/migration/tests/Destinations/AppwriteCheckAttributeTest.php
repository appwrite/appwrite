<?php

declare(strict_types=1);

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
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;

/**
 * Records every column the destination offers to the project database's limit
 * check, and otherwise behaves exactly like the real database.
 */
final class CheckedColumnRecordingDatabase extends UtopiaDatabase
{
    /** @var list<UtopiaAttribute> */
    public array $checkedAttributes = [];

    /** @var list<string> */
    public array $checkedCollections = [];

    #[Override]
    public function checkAttribute(string $collection, UtopiaAttribute $attribute): bool
    {
        $this->checkedAttributes[] = $attribute;
        $this->checkedCollections[] = $collection;

        return parent::checkAttribute($collection, $attribute);
    }
}

/**
 * `checkAttribute` decides whether one more column still fits the destination
 * table, so it has to be offered the column that is about to be created. The
 * metadata row built alongside it is keyed by the destination's own composite
 * attribute id, so offering that row instead measures a column the table will
 * never hold.
 */
final class AppwriteCheckAttributeTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    public function testTheCheckedColumnIsKeyedByTheResourceKeyNotTheMetadataId(): void
    {
        [$database, $destination, $column] = $this->transferColumn();

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SUCCESS, $column->getStatus());

        $metadata = $this->attributeDocument($database);
        $this->assertFalse($metadata->isEmpty(), 'The column metadata row must be written');
        $this->assertSame('title', $metadata->getAttribute('key'));
        $this->assertNotSame(
            'title',
            $metadata->getId(),
            'The metadata row must be keyed by something other than the column key, or the two identities cannot be told apart.',
        );

        $this->assertCount(1, $database->checkedAttributes, 'The transfer must offer its column to the limit check');
        $this->assertSame('title', $database->checkedAttributes[0]->key);
        $this->assertSame(
            'string',
            $database->checkedAttributes[0]->toDocument()->getAttribute('type'),
            'The checked column must be the text column the transfer creates.',
        );
    }

    public function testTheLimitCheckMeasuresThePhysicalTableCollection(): void
    {
        [$database] = $this->transferColumn();

        $metadata = $this->attributeDocument($database);
        $this->assertSame(
            ['database_'.$metadata->getAttribute('databaseInternalId').'_collection_'.$metadata->getAttribute('collectionInternalId')],
            $database->checkedCollections,
            'The limit check must measure the collection the column is created in, not the table metadata row.',
        );
    }

    public function testTheCheckedColumnCarriesTheSpecificationBeingCreated(): void
    {
        [$database] = $this->transferColumn();

        $this->assertCount(1, $database->checkedAttributes);
        $checked = $database->checkedAttributes[0];

        $this->assertSame(ColumnType::String, $checked->type);
        $this->assertSame(128, $checked->size);
        $this->assertTrue($checked->required);
    }

    private static function registerSubqueryFilters(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        // The per-database meta collection exposes its tables' columns and
        // indexes through the same virtual subquery attributes Appwrite
        // registers, so the destination reads the table exactly as it does in
        // production.
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

    /**
     * @return array{CheckedColumnRecordingDatabase, AppwriteDestination, Text}
     */
    private function transferColumn(): array
    {
        $database = $this->projectDatabase();

        $source = new MockSource();
        $databaseResource = new DatabaseResource(
            id: 'shop',
            name: 'Shop',
            type: 'tablesdb',
            database: 'source-dsn',
        );
        $table = new Table($databaseResource, 'Products', 'products');
        $column = new Text('title', $table, required: true, size: 128);
        $column->setId('column-title');

        $source->pushMockResource($databaseResource);
        $source->pushMockResource($table);
        $source->pushMockResource($column);

        $destination = new AppwriteDestination(
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

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static function () use ($transfer): void {
                $transfer->run(
                    [Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN],
                    static function (): void {
                    },
                );
            },
        );

        return [$database, $destination, $column];
    }

    private function projectDatabase(): CheckedColumnRecordingDatabase
    {
        $database = new CheckedColumnRecordingDatabase(
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
                $this->attribute('error', ColumnType::String, size: 2048),
            ],
        ));

        $database->createCollection(Collection::create(
            id: 'indexes',
            attributes: [
                $this->attribute('key', ColumnType::String, size: 256),
                $this->attribute('status', ColumnType::String, size: 64),
                $this->attribute('databaseInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('databaseId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionInternalId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('collectionId', ColumnType::String, size: UtopiaDatabase::LENGTH_KEY),
                $this->attribute('type', ColumnType::String, size: 16),
                $this->attribute('attributes', ColumnType::String, size: 256, array: true),
                $this->attribute('lengths', ColumnType::Integer, array: true),
                $this->attribute('orders', ColumnType::String, size: 4, array: true),
                $this->attribute('error', ColumnType::String, size: 2048),
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
        bool $required = false,
        mixed $default = null,
        int $size = 0,
        bool $array = false,
        array $filters = [],
    ): array {
        return [
            '$id' => $id,
            'type' => $type->value,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
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

    /**
     * @return array<string>
     */
    private function errorMessages(AppwriteDestination $destination): array
    {
        return \array_map(
            static fn (\Throwable $error): string => $error->getMessage(),
            $destination->getErrors(),
        );
    }

    private function attributeDocument(UtopiaDatabase $database): UtopiaDocument
    {
        $attributes = $database->getAuthorization()->skip(
            static fn (): array => $database->find('attributes'),
        );

        return $attributes[0] ?? new UtopiaDocument();
    }
}
