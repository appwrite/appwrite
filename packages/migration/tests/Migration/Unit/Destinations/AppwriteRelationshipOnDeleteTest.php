<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Destinations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Query;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Relationship;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Transfer;
use Utopia\Query\Schema\ColumnType;
use Utopia\Tests\Unit\Adapters\MockSource;

/**
 * An overwrite that reaches an existing two-way relationship with no usable
 * `onDelete` in the source must leave the destination's action alone: the
 * library reads a null action as "unchanged", and both sides of the Appwrite
 * metadata must keep agreeing with it.
 */
final class AppwriteRelationshipOnDeleteTest extends TestCase
{
    private const string DESTINATION_ACTION = 'cascade';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    /** @return array<string, array{?string}> */
    public static function unusableSourceActions(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'unknown' => ['archive-invented'],
        ];
    }

    #[DataProvider('unusableSourceActions')]
    public function testOverwriteWithoutAUsableSourceActionKeepsTheDestinationAction(?string $sourceAction): void
    {
        $database = $this->projectDatabase();
        $created = $this->transfer(
            $database,
            OnDuplicate::Fail,
            $this->relationship(self::DESTINATION_ACTION, '2020-01-01T00:00:00.000+00:00'),
        );
        $this->assertSame([], $this->errorMessages($created));
        $this->assertSame(self::DESTINATION_ACTION, $this->libraryAction($database, 'products', 'category'));

        $relationship = $this->relationship(self::DESTINATION_ACTION, '2030-01-01T00:00:00.000+00:00');
        $options = &$relationship->getOptions();
        if ($sourceAction === null) {
            unset($options['onDelete']);
        } else {
            $options['onDelete'] = $sourceAction;
        }

        $overwritten = $this->transfer($database, OnDuplicate::Overwrite, $relationship);

        $this->assertSame([], $this->errorMessages($overwritten));
        $this->assertSame(self::DESTINATION_ACTION, $this->libraryAction($database, 'products', 'category'));
        $this->assertSame(self::DESTINATION_ACTION, $this->libraryAction($database, 'categories', 'products'));
        $this->assertSame(self::DESTINATION_ACTION, $this->metadataAction($database, 'products', 'category'));
        $this->assertSame(self::DESTINATION_ACTION, $this->metadataAction($database, 'categories', 'products'));
    }

    public function testOverwriteWithAKnownSourceActionStillUpdatesBothSides(): void
    {
        $database = $this->projectDatabase();
        $this->transfer(
            $database,
            OnDuplicate::Fail,
            $this->relationship(self::DESTINATION_ACTION, '2020-01-01T00:00:00.000+00:00'),
        );

        $overwritten = $this->transfer(
            $database,
            OnDuplicate::Overwrite,
            $this->relationship(RelationshipDeleteAction::SetNull->value, '2030-01-01T00:00:00.000+00:00'),
        );

        $this->assertSame([], $this->errorMessages($overwritten));
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $this->libraryAction($database, 'products', 'category'));
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $this->libraryAction($database, 'categories', 'products'));
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $this->metadataAction($database, 'products', 'category'));
        $this->assertSame(RelationshipDeleteAction::SetNull->value, $this->metadataAction($database, 'categories', 'products'));
    }

    /** @return array<string, array{string}> */
    public static function retiredDeleteActions(): array
    {
        return [
            'set default' => ['setDefault'],
            'no action' => ['noAction'],
        ];
    }

    #[DataProvider('retiredDeleteActions')]
    public function testARelationshipWithARetiredDeleteActionIsRejected(string $onDelete): void
    {
        $database = $this->projectDatabase();

        $destination = $this->transfer(
            $database,
            OnDuplicate::Fail,
            $this->relationship($onDelete, '2020-01-01T00:00:00.000+00:00'),
        );

        $messages = $this->errorMessages($destination);
        $this->assertCount(1, $messages, 'A delete action outside cascade, restrict and setNull must fail the column.');
        $this->assertStringStartsWith('Unsupported relationship onDelete action "'.$onDelete.'"', $messages[0]);
        $this->assertNull(
            $this->metadataDocument($database, 'products', 'category'),
            'A rejected relationship must not leave its metadata row behind.',
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

    private function relationship(string $onDelete, string $updatedAt): Relationship
    {
        $database = new DatabaseResource(id: 'shop', name: 'Shop', type: 'tablesdb', database: 'source-dsn');
        $relationship = new Relationship(
            'category',
            new Table($database, 'Products', 'products'),
            relatedTable: 'categories',
            relationType: RelationshipType::ManyToOne->value,
            twoWay: true,
            twoWayKey: 'products',
            onDelete: $onDelete,
            updatedAt: $updatedAt,
        );
        $relationship->setId('column-category');

        return $relationship;
    }

    private function transfer(UtopiaDatabase $database, OnDuplicate $onDuplicate, Relationship $relationship): AppwriteDestination
    {
        $shop = $relationship->getTable()->getDatabase();
        $source = new MockSource();
        $source->pushMockResource($shop);
        $source->pushMockResource(new Table($shop, 'Products', 'products'));
        $source->pushMockResource(new Table($shop, 'Categories', 'categories'));
        $source->pushMockResource($relationship);

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
            onDuplicate: $onDuplicate,
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

        return $destination;
    }

    private function libraryAction(UtopiaDatabase $database, string $tableId, string $key): mixed
    {
        $collection = $database->getAuthorization()->skip(
            fn (): Collection => $database->getCollection($this->tableCollectionId($database, $tableId)),
        );
        foreach ($collection->attributes() as $attribute) {
            if ($attribute->key === $key) {
                return $attribute->relationship?->onDelete->value;
            }
        }

        $this->fail("Relationship {$key} not found on {$tableId}");
    }

    private function metadataAction(UtopiaDatabase $database, string $tableId, string $key): mixed
    {
        return $this->metadataDocument($database, $tableId, $key)?->getAttribute('options', [])['onDelete'] ?? null;
    }

    private function metadataDocument(UtopiaDatabase $database, string $tableId, string $key): ?UtopiaDocument
    {
        $shop = $this->document($database, 'databases', 'shop');
        $table = $this->document($database, 'database_'.$shop->getSequence(), $tableId);
        $metadataId = $shop->getSequence().'_'.$table->getSequence().'_'.$key;
        $metadata = $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument('attributes', $metadataId),
        );

        return $metadata->isEmpty() ? null : $metadata;
    }

    private function tableCollectionId(UtopiaDatabase $database, string $tableId): string
    {
        $shop = $this->document($database, 'databases', 'shop');
        $table = $this->document($database, 'database_'.$shop->getSequence(), $tableId);

        return 'database_'.$shop->getSequence().'_collection_'.$table->getSequence();
    }

    private function document(UtopiaDatabase $database, string $collection, string $documentId): UtopiaDocument
    {
        $document = $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument($collection, $documentId),
        );
        $this->assertFalse($document->isEmpty(), "{$collection}/{$documentId} must exist");

        return $document;
    }

    private function projectDatabase(): UtopiaDatabase
    {
        $database = new UtopiaDatabase(new MemoryAdapter(), new Cache(new MemoryCache()));
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

    /** @return list<string> */
    private function errorMessages(AppwriteDestination $destination): array
    {
        return \array_map(
            static fn (\Throwable $error): string => $error->getMessage(),
            $destination->getErrors(),
        );
    }
}
