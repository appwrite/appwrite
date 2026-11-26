<?php

declare(strict_types=1);

namespace Utopia\Migration\Tests\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter\Limits;
use Utopia\Database\Adapter\Memory as MemoryAdapter;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Query\Schema\ColumnType;

/**
 * In-memory storage, with the two limits a SQL destination enforces on a table:
 * how many columns it may hold, and the bytes they add up to in one row. The
 * memory adapter declares neither, so a transfer against it can never reach them.
 */
final class LimitedMemoryAdapter extends MemoryAdapter
{
    public function __construct(?int $documentSize = null, ?int $attributes = null)
    {
        parent::__construct();

        $defaults = parent::limits();

        $this->limits = new Limits(
            string: $defaults->string,
            varchar: $defaults->varchar,
            integer: $defaults->integer,
            bigInteger: $defaults->bigInteger,
            attributes: $attributes ?? $defaults->attributes,
            indexes: $defaults->indexes,
            defaultAttributes: $defaults->defaultAttributes,
            defaultIndexes: $defaults->defaultIndexes,
            indexLength: $defaults->indexLength,
            uidLength: $defaults->uidLength,
            documentSize: $documentSize ?? $defaults->documentSize,
            minDateTime: $defaults->minDateTime,
            maxDateTime: $defaults->maxDateTime,
            idType: $defaults->idType,
            keywords: $defaults->keywords,
            internalIndexKeys: $defaults->internalIndexKeys,
        );
    }

    /**
     * The byte accounting SQL adapters do, for the column types this test transfers.
     */
    #[\Override]
    public function getAttributeWidth(UtopiaDocument $collection): int
    {
        // The internal columns every row carries, `_uid varchar(255)` the bulk of them.
        $total = 1067;

        foreach (self::collectionAttributes($collection) as $attribute) {
            $size = $attribute->size ?? 0;

            $total += match (true) {
                // Stored externally, so only the pointer sits in the row.
                $attribute->array => 20,
                $attribute->type !== ColumnType::String => 8,
                $size > $this->limits()->varchar => 20,
                $size > 255 => $size * 4 + 2,
                default => $size * 4 + 1,
            };
        }

        return $total;
    }
}

/**
 * A column the destination table cannot hold is refused by one of two limits: the
 * number of columns, or the width of the row they share. Which of the two it was,
 * and by how much, is only in the database library's message, and it is the only
 * part a user can act on — reporting a static "Attribute limit exceeded" for both
 * named the count limit even when the real problem was the row width.
 */
final class AppwriteColumnLimitTest extends TestCase
{
    use TransfersColumns;

    /** Every SQL adapter caps a row at 65535 bytes. */
    private const int DOCUMENT_SIZE = 65535;

    /** A string column of this size spends 16002 bytes of the row, so five do not fit. */
    private const int WIDE_COLUMN_SIZE = 4000;

    /**
     * The widest project metadata collection the destination writes declares 18
     * columns, so this is the smallest cap the transfer can set up under.
     */
    private const int COLUMN_COUNT = 18;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    public function testAColumnThatDoesNotFitTheRowReportsTheRowWidth(): void
    {
        $database = $this->projectDatabase(new LimitedMemoryAdapter(documentSize: self::DOCUMENT_SIZE));

        [$destination, $columns] = $this->transferSchema(
            static fn (Table $table): array => \array_map(
                static fn (int $index): Column => new Text('wide'.$index, $table, size: self::WIDE_COLUMN_SIZE),
                \range(0, 4),
            ),
            $database,
            OnDuplicate::Fail,
            '',
        );

        $failed = $this->failedColumns($columns);
        $this->assertCount(1, $failed, 'Only the column that no longer fits the row must fail');
        $this->assertSame('wide4', $failed[0]->getKey());

        $message = $failed[0]->getMessage();
        $this->assertStringContainsString('Row width limit reached', $message);
        $this->assertStringContainsString('the maximum is '.self::DOCUMENT_SIZE.' bytes', $message);
        $this->assertSame([$message], $this->errorMessages($destination));

        $error = $destination->getErrors()[0] ?? null;
        $this->assertInstanceOf(\Throwable::class, $error);
        $this->assertSame(
            $message,
            $error->getPrevious()?->getMessage(),
            'The error must keep the library exception that named the limit',
        );
    }

    public function testAColumnPastTheCountLimitReportsTheCount(): void
    {
        $adapter = new LimitedMemoryAdapter(
            attributes: (new MemoryAdapter())->limits()->defaultAttributes + self::COLUMN_COUNT,
        );
        $database = $this->projectDatabase($adapter);

        [$destination, $columns] = $this->transferSchema(
            static fn (Table $table): array => \array_map(
                static fn (int $index): Column => new Text('column'.$index, $table),
                \range(0, self::COLUMN_COUNT),
            ),
            $database,
            OnDuplicate::Fail,
            '',
        );

        $failed = $this->failedColumns($columns);
        $this->assertCount(1, $failed, 'Only the column past the count limit must fail');
        $this->assertSame('column'.self::COLUMN_COUNT, $failed[0]->getKey());

        $message = $failed[0]->getMessage();
        $this->assertStringContainsString('Column limit reached', $message);
        $this->assertStringContainsString('the maximum is '.$adapter->limits()->attributes, $message);
        $this->assertSame([$message], $this->errorMessages($destination));
    }

    /**
     * @param  list<Column|\Utopia\Migration\Resources\Database\Index>  $resources
     * @return list<Column>
     */
    private function failedColumns(array $resources): array
    {
        return \array_values(\array_filter(
            $resources,
            static fn (Resource $resource): bool => $resource instanceof Column
                && $resource->getStatus() === Resource::STATUS_ERROR,
        ));
    }
}
