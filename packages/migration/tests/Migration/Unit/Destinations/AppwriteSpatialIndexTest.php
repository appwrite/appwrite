<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Destinations;

use Override;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Feature\Spatial;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Adapter\Pool;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Index as UtopiaIndex;
use Utopia\Database\Query;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Point;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Transfer;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\IndexType;
use Utopia\Tests\Unit\Adapters\MockSource;

final class SpatialMemoryAdapter extends MemoryAdapter implements Spatial
{
    #[Override]
    public function encode(mixed $value, ColumnType $type): string
    {
        return \json_encode($value, JSON_THROW_ON_ERROR);
    }

    #[Override]
    public function decode(string $value, ColumnType $type): array
    {
        $decoded = \json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}

final class AppwriteSpatialIndexTest extends TestCase
{
    private const string INDEX_KEY = 'idx_location';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    public function testTheDatabasesAdapterIsAPoolThatDoesNotImplementSpatialItself(): void
    {
        $databases = $this->pooledDatabase();

        $this->assertInstanceOf(Pool::class, $databases->getAdapter());
        $this->assertNotInstanceOf(Spatial::class, $databases->getAdapter());
        $this->assertTrue($databases->profile()->hasFeature(Spatial::class));
    }

    public function testASpatialIndexMigratesThroughAPoolWrappedAdapter(): void
    {
        [$destination, $project, $databases, $index] = $this->transferSpatialIndex();

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SUCCESS, $index->getStatus());

        $metadata = $this->indexDocument($project);
        $this->assertFalse($metadata->isEmpty(), 'The index metadata row must be written');
        $this->assertSame(IndexType::Spatial->value, $metadata->getAttribute('type'));

        $created = $this->physicalIndex($project, $databases);
        $this->assertNotNull($created, 'The spatial index must be created on the destination table');
        $this->assertSame(IndexType::Spatial, $created->type);
        $this->assertSame(['location'], $created->attributes);
    }

    /**
     * @return array{AppwriteDestination, UtopiaDatabase, UtopiaDatabase, Index}
     */
    private function transferSpatialIndex(): array
    {
        $project = $this->projectDatabase();
        $databases = $this->pooledDatabase();

        $source = new MockSource();
        $databaseResource = new DatabaseResource(
            id: 'maps',
            name: 'Maps',
            type: 'tablesdb',
            database: 'source-dsn',
        );
        $table = new Table($databaseResource, 'Places', 'places');
        $source->pushMockResource($databaseResource);
        $source->pushMockResource($table);
        $source->pushMockResource((new Point('location', $table, required: true))->setId('location'));

        $index = new Index(
            id: self::INDEX_KEY,
            key: self::INDEX_KEY,
            table: $table,
            type: IndexType::Spatial->value,
            columns: ['location'],
        );

        $destination = new AppwriteDestination(
            project: 'destination-project',
            endpoint: 'http://example.test/v1',
            key: 'test-key',
            dbForProject: $project,
            getDatabasesDB: static fn (UtopiaDocument $document): UtopiaDatabase => $databases,
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
            dbForPlatform: $project,
            projectInternalId: '1',
        );

        $transfer = new Transfer($source, $destination);
        $project->getAuthorization()->skip(
            static function () use ($transfer, $source, $index): void {
                $noop = static function (): void {
                };
                $transfer->run([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN], $noop);

                $source->pushMockResource($index);
                $transfer->run([Resource::TYPE_INDEX], $noop);
            },
        );

        return [$destination, $project, $databases, $index];
    }

    private function pooledDatabase(): UtopiaDatabase
    {
        $adapter = new SpatialMemoryAdapter();
        $pool = new UtopiaPool(new Stack(), 'spatial', 1, static fn (): SpatialMemoryAdapter => $adapter, timeout: 0.0);

        $database = new UtopiaDatabase(new Pool($pool), new Cache(new MemoryCache()));
        $database
            ->setDatabase('appwrite')
            ->setNamespace('_databases');

        return $database;
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

    private function indexDocument(UtopiaDatabase $database): UtopiaDocument
    {
        $indexes = $database->getAuthorization()->skip(
            static fn (): array => $database->find('indexes'),
        );

        return $indexes[0] ?? new UtopiaDocument();
    }

    private function physicalIndex(UtopiaDatabase $project, UtopiaDatabase $databases): ?UtopiaIndex
    {
        $maps = $this->document($project, 'databases', 'maps');
        $places = $this->document($project, 'database_'.$maps->getSequence(), 'places');
        $collectionId = 'database_'.$maps->getSequence().'_collection_'.$places->getSequence();

        $collection = $databases->getAuthorization()->skip(
            static fn (): ?Collection => $databases->findCollection($collectionId),
        );

        foreach ($collection?->indexes() ?? [] as $index) {
            if ($index->key === self::INDEX_KEY) {
                return $index;
            }
        }

        return null;
    }

    private function document(UtopiaDatabase $database, string $collection, string $id): UtopiaDocument
    {
        return $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->getDocument($collection, $id),
        );
    }
}
