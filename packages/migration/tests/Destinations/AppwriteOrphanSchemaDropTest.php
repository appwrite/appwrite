<?php

declare(strict_types=1);

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Database\Index as UtopiaIndex;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\Integer;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Query\Schema\ColumnType;

/**
 * A meta document and its schema counterpart are two separate, non-atomic writes, so a
 * destination can hold a meta document the schema no longer backs — from a run killed
 * between the two, or from a project wedged by the stuck-index bug fixed in
 * appwrite/appwrite#15471, where the index document outlived its schema index.
 *
 * An Overwrite migration drops the schema copy before recreating it. That drop must read
 * "already absent" as the end state it asked for: otherwise the `NotFound` aborts the
 * import before the meta delete that would have let the recreate through, and every retry
 * takes the same path, so the orphan can never be reconciled through migration at all.
 */
final class AppwriteOrphanSchemaDropTest extends TestCase
{
    use TransfersColumns;

    private const string OLDER_UPDATED_AT = '2020-01-01T00:00:00.000+00:00';

    private const string NEWER_UPDATED_AT = '2030-01-01T00:00:00.000+00:00';

    private const string INDEX_KEY = 'idx_title_slug';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    public function testAnIndexTheSchemaNoLongerHoldsIsRecreated(): void
    {
        $database = $this->projectDatabase();

        [$first] = $this->transferSchema(
            fn (Table $table): array => [...$this->columns($table), $this->index($table, ['ASC', 'ASC'])],
            $database,
            OnDuplicate::Fail,
            self::OLDER_UPDATED_AT,
        );
        $this->assertSame([], $this->errorMessages($first));
        $this->assertNotNull($this->schemaIndex($database), 'The index must reach the schema before it is orphaned.');

        $this->orphanIndex($database);

        [$second, $resources] = $this->transferSchema(
            fn (Table $table): array => [...$this->columns($table), $this->index($table, ['ASC', 'DESC'])],
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $this->assertSame(
            [],
            $this->errorMessages($second),
            'Dropping an index the schema no longer holds must not fail the import.',
        );

        $index = \end($resources);
        $this->assertInstanceOf(Index::class, $index);
        $this->assertSame(Resource::STATUS_SUCCESS, $index->getStatus());

        $recreated = $this->schemaIndex($database);
        $this->assertNotNull($recreated, 'The orphaned index must be recreated in the schema.');
        $this->assertSame(
            ['ASC', 'DESC'],
            \array_map(static fn (mixed $order): ?string => $order?->value, $recreated->orders),
            'The recreated index must carry the source spec, not the orphaned one.',
        );
        $this->assertCount(1, $this->indexDocuments($database), 'The orphan must be replaced, not duplicated.');
    }

    public function testAColumnTheSchemaNoLongerHoldsIsRecreated(): void
    {
        $database = $this->projectDatabase();

        [, $first] = $this->transferColumn(
            static fn (Table $table): Column => new Text('title', $table, size: 100),
            $database,
            OnDuplicate::Fail,
            self::OLDER_UPDATED_AT,
        );
        $this->assertSame([], $this->errorMessages($first));

        $this->orphanColumn($database, 'title');

        // A type change has no in-place primitive, so the overwrite goes through the drop.
        [, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new Integer('title', $table),
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $this->assertSame(
            [],
            $this->errorMessages($destination),
            'Dropping a column the schema no longer holds must not fail the import.',
        );
        $this->assertSame(Resource::STATUS_SUCCESS, $column->getStatus());
        $this->assertSame(
            ColumnType::Integer,
            $this->physicalColumn($database, 'title')->type,
            'The orphaned column must be recreated with the source type.',
        );
    }

    /**
     * Drops the schema copy of the index and leaves its meta document behind — the state
     * the upstream stuck-index bug left on a project.
     */
    private function orphanIndex(UtopiaDatabase $database): void
    {
        $collectionId = $this->tableCollectionId($database);
        $database->getAuthorization()->skip(
            static fn () => $database->deleteIndex($collectionId, self::INDEX_KEY),
        );
        $this->purgeTable($database);

        $this->assertNull($this->schemaIndex($database), 'The orphan must have no schema counterpart.');
        $this->assertCount(1, $this->indexDocuments($database), 'The orphan must keep its meta document.');
    }

    private function orphanColumn(UtopiaDatabase $database, string $key): void
    {
        $collectionId = $this->tableCollectionId($database);
        $database->getAuthorization()->skip(
            static fn () => $database->deleteAttribute($collectionId, $key),
        );
        $this->purgeTable($database);

        $this->assertFalse(
            $this->attributeDocument($database)->isEmpty(),
            'The orphan must keep its meta document.',
        );
    }

    /**
     * @return list<Column>
     */
    private function columns(Table $table): array
    {
        return [new Text('title', $table, size: 100), new Text('slug', $table, size: 100)];
    }

    /**
     * @param list<string> $orders
     */
    private function index(Table $table, array $orders): Index
    {
        return new Index(self::INDEX_KEY, self::INDEX_KEY, $table, 'key', ['title', 'slug'], [], $orders);
    }

    private function schemaIndex(UtopiaDatabase $database): ?UtopiaIndex
    {
        $collectionId = $this->tableCollectionId($database);
        $collection = $database->getAuthorization()->skip(
            static fn () => $database->getCollection($collectionId),
        );

        foreach ($collection->indexes() as $index) {
            if ($index->key === self::INDEX_KEY) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<UtopiaDocument>
     */
    private function indexDocuments(UtopiaDatabase $database): array
    {
        return $database->getAuthorization()->skip(
            static fn (): array => $database->find('indexes'),
        );
    }

    private function tableCollectionId(UtopiaDatabase $database): string
    {
        $shop = $this->document($database, 'databases', 'shop');
        $table = $this->document($database, 'database_'.$shop->getSequence(), 'products');

        return 'database_'.$shop->getSequence().'_collection_'.$table->getSequence();
    }

    /** The table document caches its subqueried columns and indexes; a direct schema edit bypasses that. */
    private function purgeTable(UtopiaDatabase $database): void
    {
        $shop = $this->document($database, 'databases', 'shop');
        $database->getAuthorization()->skip(
            static fn () => $database->purgeCachedDocument('database_'.$shop->getSequence(), 'products'),
        );
    }
}
