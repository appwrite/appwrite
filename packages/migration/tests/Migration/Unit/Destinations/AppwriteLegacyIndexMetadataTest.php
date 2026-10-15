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
use Utopia\Migration\Resources\Database\Columns\Text;
use Utopia\Migration\Resources\Database\Index;
use Utopia\Migration\Resources\Database\Table;

/**
 * A destination upgraded from 7.x keeps the index metadata 7.x wrote, which
 * spells some unchanged indexes differently from a fresh 8.0 install. An
 * Overwrite migration must still recognise those indexes as unchanged.
 */
final class AppwriteLegacyIndexMetadataTest extends TestCase
{
    use TransfersColumns;

    private const string OLDER_UPDATED_AT = '2020-01-01T00:00:00.000+00:00';

    private const string NEWER_UPDATED_AT = '2030-01-01T00:00:00.000+00:00';

    private const string KEY = 'idx_title_slug';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        self::registerSubqueryFilters();
    }

    /**
     * @return iterable<string, array{string, list<int>, list<string>, array<string, mixed>}>
     */
    public static function legacyShapes(): iterable
    {
        yield 'fulltext index stored with lengths and orders' => [
            'fulltext', [], [], ['lengths' => [null, null], 'orders' => ['ASC', 'ASC']],
        ];
        yield 'key index stored with the legacy type' => [
            'key', [], ['ASC', 'ASC'], ['type' => 'index'],
        ];
        yield 'key index stored with lower-case orders' => [
            'key', [], ['ASC', 'DESC'], ['orders' => ['asc', 'desc']],
        ];
        yield 'key index stored with null prefixes' => [
            'key', [20, 0], ['ASC', 'ASC'], ['lengths' => [20, null]],
        ];
        yield 'key index stored without prefixes' => [
            'key', [0, 0], ['ASC', 'ASC'], ['lengths' => []],
        ];
    }

    /**
     * @param list<int> $lengths
     * @param list<string> $orders
     * @param array<string, mixed> $legacy
     */
    #[DataProvider('legacyShapes')]
    public function testAnUnchangedIndexWithLegacyMetadataIsSkipped(string $type, array $lengths, array $orders, array $legacy): void
    {
        $index = $this->overwriteAfterRewritingMetadata(
            fn (Table $table): Index => $this->index($table, $type, $lengths, $orders),
            fn (Table $table): Index => $this->index($table, $type, $lengths, $orders),
            $legacy,
        );

        $this->assertSame(
            Resource::STATUS_SKIPPED,
            $index->getStatus(),
            'Index metadata that differs only in its legacy spelling must match the source spec.',
        );
    }

    /**
     * @return iterable<string, array{array{string, list<int>, list<string>}, array{string, list<int>, list<string>}}>
     */
    public static function realChanges(): iterable
    {
        yield 'type' => [['key', [], ['ASC', 'ASC']], ['unique', [], ['ASC', 'ASC']]];
        yield 'order' => [['key', [], ['ASC', 'ASC']], ['key', [], ['ASC', 'DESC']]];
        yield 'prefix length' => [['key', [20, 0], ['ASC', 'ASC']], ['key', [30, 0], ['ASC', 'ASC']]];
        yield 'fulltext to key' => [['fulltext', [], []], ['key', [], ['ASC', 'ASC']]];
    }

    /**
     * @param array{string, list<int>, list<string>} $before
     * @param array{string, list<int>, list<string>} $after
     */
    #[DataProvider('realChanges')]
    public function testARealChangeBehindLegacyMetadataIsNotSkipped(array $before, array $after): void
    {
        $index = $this->overwriteAfterRewritingMetadata(
            fn (Table $table): Index => $this->index($table, ...$before),
            fn (Table $table): Index => $this->index($table, ...$after),
            $before[0] === 'key' ? ['type' => 'index'] : ['lengths' => [null, null], 'orders' => ['ASC', 'ASC']],
        );

        $this->assertNotSame(
            Resource::STATUS_SKIPPED,
            $index->getStatus(),
            'A semantic index difference must not be reported as already existing on the destination.',
        );
    }

    public function testAChangedColumnListIsNotSkipped(): void
    {
        $index = $this->overwriteAfterRewritingMetadata(
            fn (Table $table): Index => $this->index($table, 'key', [], ['ASC', 'ASC']),
            static fn (Table $table): Index => new Index(self::KEY, self::KEY, $table, 'key', ['slug', 'title'], [], ['ASC', 'ASC']),
            ['type' => 'index'],
        );

        $this->assertNotSame(Resource::STATUS_SKIPPED, $index->getStatus(), 'A reordered column list is a different index.');
    }

    /**
     * @param list<int> $lengths
     * @param list<string> $orders
     */
    private function index(Table $table, string $type, array $lengths, array $orders): Index
    {
        return new Index(self::KEY, self::KEY, $table, $type, ['title', 'slug'], $lengths, $orders);
    }

    /**
     * @param callable(Table): Index $before
     * @param callable(Table): Index $after
     * @param array<string, mixed> $legacy
     */
    private function overwriteAfterRewritingMetadata(callable $before, callable $after, array $legacy): Index
    {
        $database = $this->projectDatabase();
        [$first] = $this->transferSchema(
            fn (Table $table): array => [...$this->columns($table), $before($table)],
            $database,
            OnDuplicate::Fail,
            self::OLDER_UPDATED_AT,
        );
        $this->assertSame([], $this->errorMessages($first));

        $this->rewriteIndexMetadata($database, $legacy);

        [, $resources] = $this->transferSchema(
            fn (Table $table): array => [...$this->columns($table), $after($table)],
            $database,
            OnDuplicate::Overwrite,
            self::NEWER_UPDATED_AT,
        );

        $index = $resources[\array_key_last($resources)];
        $this->assertInstanceOf(Index::class, $index);

        return $index;
    }

    /**
     * @return list<Column>
     */
    private function columns(Table $table): array
    {
        return [new Text('title', $table, size: 100), new Text('slug', $table, size: 100)];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function rewriteIndexMetadata(UtopiaDatabase $database, array $fields): void
    {
        $indexes = $database->getAuthorization()->skip(
            static fn (): array => $database->find('indexes'),
        );
        $this->assertCount(1, $indexes, 'The index metadata row must be written before it is rewritten.');

        $database->getAuthorization()->skip(
            static fn (): UtopiaDocument => $database->updateDocument('indexes', $indexes[0]->getId(), new UtopiaDocument($fields)),
        );
    }
}
