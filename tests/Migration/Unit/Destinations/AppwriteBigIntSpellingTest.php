<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Destinations;

use PHPUnit\Framework\TestCase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\BigInt;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Query\Schema\ColumnType;

/**
 * A big integer is stored in Appwrite's attribute metadata as `bigint`, the one
 * spelling its API reads back, while the schema itself keeps the
 * {@see ColumnType::BigInteger} case. A migrated column that disagrees with
 * either side can no longer be updated through the API.
 */
final class AppwriteBigIntSpellingTest extends TestCase
{
    use TransfersColumns;

    public const string FORMAT = 'range';

    private const string NEWER_UPDATED_AT = '2030-01-01T00:00:00.000+00:00';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    public function testTheMigratedBigIntColumnCarriesThePersistedSpelling(): void
    {
        [$database, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new BigInt('total', $table, required: true),
        );

        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SUCCESS, $column->getStatus());

        $metadata = $this->attributeDocument($database);
        $this->assertFalse($metadata->isEmpty(), 'The column metadata row must be written');
        $this->assertSame('total', $metadata->getAttribute('key'));
        $this->assertSame(
            'bigint',
            $metadata->getAttribute('type'),
            'The metadata row must hold the spelling the destination reads back when the column is updated.',
        );

        $this->assertSame(
            ColumnType::BigInteger,
            $this->physicalColumn($database, 'total')->type,
            'The table itself must still hold a big integer column.',
        );
    }

    public function testAFormattedBigIntColumnReachesTheFormatDecision(): void
    {
        [, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new class ('total', $table) extends BigInt {
                public function getFormat(): string
                {
                    return AppwriteBigIntSpellingTest::FORMAT;
                }
            },
        );

        $this->assertSame(Resource::STATUS_ERROR, $column->getStatus());

        $messages = $this->errorMessages($destination);
        $this->assertCount(1, $messages);
        $this->assertStringStartsWith(
            'Format '.self::FORMAT.' not available for column type',
            $messages[0],
            'A formatted big integer must reach the format decision instead of failing to resolve its column type.',
        );
    }

    public function testAStoredBigIntegerSpellingIsNotDroppedAndRecreated(): void
    {
        $database = $this->projectDatabase();
        $this->transferColumn(
            static fn (Table $table): Column => new BigInt('total', $table, required: true),
            $database,
        );

        $created = $this->attributeDocument($database);
        $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->updateDocument(
                'attributes',
                $created->getId(),
                new UtopiaDocument(['type' => ColumnType::BigInteger->value]),
            ),
        );

        [, $destination, $column] = $this->transferColumn(
            static fn (Table $table): Column => new BigInt('total', $table, required: true),
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $stored = $this->attributeDocument($database);
        $this->assertSame([], $this->errorMessages($destination));
        $this->assertSame(Resource::STATUS_SKIPPED, $column->getStatus());
        $this->assertSame(
            ColumnType::BigInteger->value,
            $stored->getAttribute('type'),
            'A row holding the other big-integer spelling must match the desired column instead of being recreated.',
        );
        $this->assertSame(
            (string) $created->getCreatedAt(),
            (string) $stored->getCreatedAt(),
            'The column metadata row must be the one the first transfer wrote.',
        );
        $this->assertSame(
            ColumnType::BigInteger,
            $this->physicalColumn($database, 'total')->type,
            'The table itself must still hold a big integer column.',
        );
    }
}
