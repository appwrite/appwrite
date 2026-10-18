<?php

declare(strict_types=1);

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory as MemoryCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Attribute as UtopiaAttribute;
use Utopia\Database\Collection;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Database as DatabaseResource;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Tests\Adapters\MockSource;
use Utopia\Migration\Transfer;
use Utopia\Query\OrderDirection;
use Utopia\Query\Schema\ColumnType;

/**
 * A restored index must carry the source's own prefix lengths. The source
 * recorded them because the index only fits under the adapter's byte limit
 * with them; deriving lengths from the destination columns instead recreates
 * the index full-width and a backup of a healthy database fails to restore
 * its own indexes with "Index length is longer than the maximum" (DAT-2113,
 * nine consecutive customer restore failures).
 */
final class AppwriteIndexLengthsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
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
                    \Utopia\Database\Query::equal('collectionInternalId', [$document->getSequence()]),
                    \Utopia\Database\Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                ]),
            ),
        );
        UtopiaDatabase::addFilter(
            'subQueryIndexes',
            static fn (mixed $value) => null,
            static fn (mixed $value, UtopiaDocument $document, UtopiaDatabase $database): array => $database->getAuthorization()->skip(
                static fn (): array => $database->find('indexes', [
                    \Utopia\Database\Query::equal('collectionInternalId', [$document->getSequence()]),
                    \Utopia\Database\Query::equal('databaseInternalId', [$document->getAttribute('databaseInternalId')]),
                ]),
            ),
        );
    }

    public function testRestoredIndexCarriesTheSourcePrefixLengths(): void
    {
        [$destination, $database] = $this->transferIndex(lengths: [100, 20]);

        $this->assertSame([], $this->errorMessages($destination));

        $created = $this->indexDocument($database);
        $this->assertFalse($created->isEmpty(), 'The index must be created');
        $this->assertSame([100, 20], $created->getAttribute('lengths'));
    }

    public function testRestoredIndexWithoutSourceLengthsFailsTheAdapterLimit(): void
    {
        // The two 600-char columns exceed the Memory adapter's 1024-byte index
        // cap without prefixes, exactly like the production 767-byte MySQL cap:
        // an index that NEEDS its source lengths cannot be recreated without
        // them, which is the customer-facing failure this suite pins.
        [$destination, $database] = $this->transferIndex(lengths: []);

        $messages = $this->errorMessages($destination);
        $this->assertNotSame([], $messages, 'Expected the full-width index to exceed the adapter limit');
        $this->assertStringContainsString('Index length is longer than the maximum', $messages[0]);
        $this->assertTrue($this->indexDocument($database)->isEmpty(), 'The failed index must not be recorded');
    }

    public function testZeroSourceLengthMeansNoPrefix(): void
    {
        // Zero is how a source records "no prefix" for a position (a short
        // column needs none), and it must stay no-prefix rather than becoming a
        // zero-length one. The metadata collection types `lengths` as an integer
        // array, so no-prefix reads back as 0 — the shape a live production row
        // for exactly this case carries ([100, 0]).
        [$destination, $database] = $this->transferIndex(lengths: [100, 0], columnSizes: [600, 30]);

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame([100, 0], $this->indexDocument($database)->getAttribute('lengths'));
    }

    /**
     * An Overwrite migration onto an index whose ONLY difference is its prefix
     * lengths must recreate it. The spec comparison used to omit lengths, so
     * the index was treated as already matching and the destination silently
     * kept its own prefixes — the shape Greptile flagged on this PR.
     */
    public function testAnOverwriteDoesNotTreatALengthOnlyDifferenceAsAMatch(): void
    {
        [, , $overwritten] = $this->transferIndex(
            lengths: [100, 20],
            onDuplicate: OnDuplicate::Overwrite,
            existingLengths: [50, 20],
        );

        // The observable is the decision, not the recreate: an index whose only
        // difference is its prefix was reported as already matching and skipped,
        // so the destination silently kept its own lengths. The drop-and-recreate
        // that follows cannot be asserted on the in-memory adapter, which does
        // not support dropping an index the destination believes it holds — that
        // is an adapter limit, not the behaviour under test, and saying so beats
        // asserting an artifact of it.
        $this->assertNotSame(
            Resource::STATUS_SKIPPED,
            $overwritten->getStatus(),
            'A length-only difference must not be reported as already existing on the destination.',
        );
    }

    /**
     * @param array<int> $lengths
     * @param array<int> $columnSizes
     * @param array<int> $existingLengths
     * @return array{AppwriteDestination, UtopiaDatabase, Index}
     */
    private function transferIndex(
        array $lengths,
        array $columnSizes = [600, 600],
        OnDuplicate $onDuplicate = OnDuplicate::Fail,
        array $existingLengths = [],
    ): array {
        $database = $this->projectDatabase();

        $source = new MockSource();
        $databaseResource = new DatabaseResource(
            id: 'shop',
            name: 'Shop',
            type: 'tablesdb',
            database: 'source-dsn',
        );
        $table = new Table($databaseResource, 'Orders', 'orders');
        $source->pushMockResource($databaseResource);
        $source->pushMockResource($table);
        // Distinct ids: MockSource keys its map by resource id, and a Column
        // carries none by default, so two columns would collide onto one.
        $source->pushMockResource((new Text('reference', $table, size: $columnSizes[0]))->setId('reference'));
        $source->pushMockResource((new Text('channel', $table, size: $columnSizes[1]))->setId('channel'));

        // An Overwrite only applies when the source is newer than what the
        // destination holds, so the seeded index is stamped older than the one
        // that replaces it.
        $index = static fn (array $withLengths, string $updatedAt = ''): Index => new Index(
            id: 'idx_reference_channel',
            key: 'idx_reference_channel',
            table: $table,
            type: 'key',
            columns: ['reference', 'channel'],
            lengths: $withLengths,
            orders: [OrderDirection::Asc->value, OrderDirection::Asc->value],
            createdAt: $updatedAt,
            updatedAt: $updatedAt,
        );

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

        // Two passes: Transfer::GROUP_DATABASES_RESOURCES replays indexes BEFORE
        // columns, the reverse of what a real source emits, so a single call
        // would offer the index against a table with no columns yet. Selecting
        // the schema first and the index second reproduces the production order
        // this defect lives in.
        $transferred = $index($lengths, $existingLengths !== [] ? '2026-06-01 00:00:00' : '');

        $transfer = new Transfer($source, $destination);
        $database->getAuthorization()->skip(
            static function () use ($transfer, $source, $index, $transferred, $existingLengths): void {
                $noop = static function (): void {
                };
                $transfer->run([Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_COLUMN], $noop);

                if ($existingLengths !== []) {
                    // Seed the index the overwrite will meet, carrying the
                    // prefixes the destination already has.
                    $source->pushMockResource($index($existingLengths, '2026-01-01 00:00:00'));
                    $transfer->run([Resource::TYPE_INDEX], $noop);
                }

                $source->pushMockResource($transferred);
                $transfer->run([Resource::TYPE_INDEX], $noop);
            },
        );

        return [$destination, $database, $transferred];
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
            static fn ($error): string => $error->getMessage(),
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
}
