<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit\Destinations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database as UtopiaDatabase;
use Utopia\Database\Document as UtopiaDocument;
use Utopia\Migration\Destinations\OnDuplicate;
use Utopia\Migration\Resource;
use Utopia\Migration\Resources\Database\Column;
use Utopia\Migration\Resources\Database\Columns\DateTime;
use Utopia\Migration\Resources\Database\Columns\Integer;
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Table;

/**
 * A destination upgraded from 7.x keeps the attribute metadata 7.x wrote, which
 * spells some unchanged attributes differently from a fresh 8.0 install. An
 * Overwrite migration must still recognise those attributes as unchanged.
 */
final class AppwriteLegacyAttributeMetadataTest extends TestCase
{
    use TransfersColumns;

    private const string OLDER_UPDATED_AT = '2020-01-01T00:00:00.000+00:00';

    private const string NEWER_UPDATED_AT = '2030-01-01T00:00:00.000+00:00';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    /**
     * @return iterable<string, array{callable(Table): Column, array<string, mixed>}>
     */
    public static function legacyShapes(): iterable
    {
        yield 'signed datetime' => [
            static fn (Table $table): Column => new DateTime('publishedAt', $table),
            ['signed' => true],
        ];
        yield 'datetime without its type filter' => [
            static fn (Table $table): Column => new DateTime('publishedAt', $table),
            ['filters' => []],
        ];
        yield 'unsized 32-bit integer' => [
            static fn (Table $table): Column => new Integer('stock', $table, min: 0, max: 100),
            ['size' => 0],
        ];
        yield 'oversized 64-bit integer' => [
            static fn (Table $table): Column => new Integer('stock', $table),
            ['size' => 16],
        ];
    }

    /**
     * @param callable(Table): Column $makeColumn
     * @param array<string, mixed> $legacy
     */
    #[DataProvider('legacyShapes')]
    public function testAnUnchangedAttributeWithLegacyMetadataIsSkipped(callable $makeColumn, array $legacy): void
    {
        $column = $this->overwriteAfterRewritingMetadata($makeColumn, $makeColumn, $legacy);

        $this->assertSame(
            Resource::STATUS_SKIPPED,
            $column->getStatus(),
            'Metadata that differs only in its legacy spelling must match the source spec.',
        );
    }

    /**
     * @return iterable<string, array{callable(Table): Column, callable(Table): Column}>
     */
    public static function realChanges(): iterable
    {
        yield 'string size' => [
            static fn (Table $table): Column => new Text('title', $table, size: 100),
            static fn (Table $table): Column => new Text('title', $table, size: 120),
        ];
        yield 'required' => [
            static fn (Table $table): Column => new Text('title', $table, size: 100),
            static fn (Table $table): Column => new Text('title', $table, required: true, size: 100),
        ];
        yield 'integer width' => [
            static fn (Table $table): Column => new Integer('stock', $table, min: 0, max: 100),
            static fn (Table $table): Column => new Integer('stock', $table),
        ];
        yield 'integer range' => [
            static fn (Table $table): Column => new Integer('stock', $table, min: 0, max: 100),
            static fn (Table $table): Column => new Integer('stock', $table, min: 0, max: 200),
        ];
    }

    /**
     * @param callable(Table): Column $before
     * @param callable(Table): Column $after
     */
    #[DataProvider('realChanges')]
    public function testARealChangeBehindLegacyMetadataIsStillApplied(callable $before, callable $after): void
    {
        $column = $this->overwriteAfterRewritingMetadata($before, $after, ['signed' => true, 'filters' => []]);

        $this->assertSame(
            Resource::STATUS_SUCCESS,
            $column->getStatus(),
            'A semantic difference must still be migrated, whatever the metadata spelling.',
        );
    }

    /**
     * @param callable(Table): Column $before
     * @param callable(Table): Column $after
     * @param array<string, mixed> $legacy
     */
    private function overwriteAfterRewritingMetadata(callable $before, callable $after, array $legacy): Column
    {
        $database = $this->projectDatabase();
        [, $first] = $this->transferColumn($before, $database, OnDuplicate::Fail, self::OLDER_UPDATED_AT);
        $this->assertSame([], $this->errorMessages($first));

        $this->rewriteMetadata($database, $legacy);

        [, $destination, $column] = $this->transferColumn($after, $database, OnDuplicate::Overwrite, self::NEWER_UPDATED_AT);
        $this->assertSame([], $this->errorMessages($destination));

        return $column;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function rewriteMetadata(UtopiaDatabase $database, array $fields): void
    {
        $metadata = $this->attributeDocument($database);
        $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->updateDocument('attributes', $metadata->getId(), new UtopiaDocument($fields)),
        );
    }
}
