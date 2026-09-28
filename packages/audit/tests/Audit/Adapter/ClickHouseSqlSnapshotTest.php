<?php

declare(strict_types=1);

namespace Utopia\Tests\Audit\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\ClickHouse\Format;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\ClickHouse\Engine as ClickHouseEngine;
use Utopia\Query\Schema\ClickHouse\IndexAlgorithm;
use Utopia\Query\Schema\ColumnType;

/**
 * Snapshots of the SQL that `Schema\ClickHouse` and `Builder\ClickHouse`
 * produce for the audit DDL/INSERT/DELETE/SELECT surfaces, so a query-library
 * upgrade cannot quietly change the emitted SQL.
 *
 * These rebuild the schema and builder calls rather than invoking the adapter,
 * so they deliberately do NOT cover the adapter's own use of the library: a
 * missing column or a wrong argument in `ClickHouse::setup()` would not show up
 * here. That is `ClickHouseTest`, which drives the real adapter - setup, log,
 * find, count and cleanup - against a live ClickHouse in CI. The two are
 * complementary: this file pins the library's output shape, that one pins the
 * adapter's behaviour.
 */
final class ClickHouseSqlSnapshotTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function auditTypeMap(): array
    {
        return [
            'id' => 'String',
            'actorId' => 'String',
            'actorType' => 'String',
            'actorInternalId' => 'String',
            'event' => 'String',
            'resource' => 'String',
            'userAgent' => 'String',
            'ip' => 'String',
            'time' => 'DateTime64(3)',
            'data' => 'String',
            'tenant' => 'UInt64',
        ];
    }

    private function newAuditBuilder(): ClickHouseBuilder
    {
        return new ClickHouseBuilder()
            ->useNamedBindings()
            ->withParamTypes($this->auditTypeMap());
    }

    public function testSetupCreateTableSnapshot(): void
    {
        $schema = new ClickHouseSchema();
        $table = $schema->table('default.audits');
        $table->string('id')->primary();
        $table->string('actorId')->nullable();
        $table->string('actorType')->lowCardinality();
        $table->string('actorInternalId')->nullable();
        $table->string('event')->lowCardinality();
        $table->string('resource')->nullable();
        $table->string('userAgent');
        $table->string('ip');
        // Optional low-cardinality dimension, as emitted for sdk / country /
        // the premium-geo and user-agent columns added in audit 2.7–2.9.
        $table->addColumn('sdk', ColumnType::String)->lowCardinality()->nullable();
        $table->datetime('time', precision: 3);
        $table->addColumn('data', ColumnType::String)->nullable();

        $table->index(
            columns: ['event'],
            name: 'idx_event',
            algorithm: IndexAlgorithm::BloomFilter,
            granularity: 1,
        );
        $table->index(
            columns: ['actorId', 'event'],
            name: 'idx_actorId_event',
            algorithm: IndexAlgorithm::BloomFilter,
            granularity: 1,
        );
        $table->index(
            columns: ['actorType'],
            name: '_key_actor_type',
            algorithm: IndexAlgorithm::BloomFilter,
            granularity: 1,
        );

        $table->engine(ClickHouseEngine::MergeTree);
        $table->orderBy(['time', 'id']);
        $table->partitionBy('toYYYYMM(time)');
        $table->settings(['index_granularity' => '8192']);

        $sql = $table->createIfNotExists()->query;

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `default`.`audits`', $sql);
        $this->assertStringContainsString('`id` String', $sql);
        $this->assertStringContainsString('`actorId` Nullable(String)', $sql);
        $this->assertStringContainsString('`actorType` LowCardinality(String)', $sql);
        $this->assertStringContainsString('`actorInternalId` Nullable(String)', $sql);
        $this->assertStringContainsString('`event` LowCardinality(String)', $sql);
        $this->assertStringContainsString('`sdk` LowCardinality(Nullable(String))', $sql);
        $this->assertStringContainsString('`time` DateTime64(3)', $sql);
        $this->assertStringNotContainsString('`location`', $sql);
        $this->assertStringNotContainsString('`userId`', $sql);
        $this->assertStringContainsString('INDEX `idx_event` `event` TYPE bloom_filter GRANULARITY 1', $sql);
        $this->assertStringContainsString('INDEX `idx_actorId_event` (`actorId`, `event`) TYPE bloom_filter GRANULARITY 1', $sql);
        $this->assertStringContainsString('INDEX `_key_actor_type` `actorType` TYPE bloom_filter GRANULARITY 1', $sql);
        $this->assertStringContainsString('ENGINE = MergeTree()', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(time)', $sql);
        $this->assertStringContainsString('ORDER BY (`time`, `id`)', $sql);
        $this->assertStringContainsString('SETTINGS index_granularity = 8192', $sql);
    }

    public function testBulkInsertJsonEachRowSnapshot(): void
    {
        $columns = ['id', 'time', 'actorId', 'actorType', 'event', 'data'];
        $rows = [
            [
                'id' => 'log-1',
                'time' => '2025-01-02 03:04:05.678',
                'actorId' => 'u1',
                'actorType' => 'users',
                'event' => 'users.create',
                'data' => '{"foo":"bar"}',
            ],
            [
                'id' => 'log-2',
                'time' => '2025-01-02 03:04:06.000',
                'actorId' => 'u2',
                'actorType' => 'users',
                'event' => 'users.delete',
                'data' => '{"foo":"baz"}',
            ],
        ];

        $statement = new ClickHouseBuilder()
            ->into('default.audits')
            ->bulkInsert(Format::JSONEachRow, $rows, $columns);

        $this->assertSame(
            'INSERT INTO `default`.`audits` (`id`, `time`, `actorId`, `actorType`, `event`, `data`) FORMAT JSONEachRow',
            $statement->query,
        );
        $this->assertSame('JSONEachRow', $statement->format);
        $this->assertSame($columns, $statement->columns);
        $this->assertSame(
            '{"id":"log-1","time":"2025-01-02 03:04:05.678","actorId":"u1","actorType":"users","event":"users.create","data":"{\"foo\":\"bar\"}"}' . "\n"
            . '{"id":"log-2","time":"2025-01-02 03:04:06.000","actorId":"u2","actorType":"users","event":"users.delete","data":"{\"foo\":\"baz\"}"}',
            $statement->body,
        );
    }

    public function testAsyncCleanupDeleteEmitsSettingsClause(): void
    {
        $sql = new ClickHouseBuilder()
            ->into('default.audits')
            ->whereRaw('`time` < {datetime:DateTime64(3)}')
            ->settings(['lightweight_deletes_sync' => '0'])
            ->delete()
            ->query;

        $this->assertSame(
            'DELETE FROM `default`.`audits` WHERE `time` < {datetime:DateTime64(3)} SETTINGS lightweight_deletes_sync=0',
            $sql,
        );
    }

    public function testSyncCleanupDeleteOmitsSettingsClause(): void
    {
        $sql = new ClickHouseBuilder()
            ->into('default.audits')
            ->whereRaw('`time` < {datetime:DateTime64(3)}')
            ->delete()
            ->query;

        $this->assertSame(
            'DELETE FROM `default`.`audits` WHERE `time` < {datetime:DateTime64(3)}',
            $sql,
        );
    }

    public function testFindEmitsTypedNamedBindings(): void
    {
        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`, `event`, `time`')
            ->filter([
                Query::equal('actorId', ['u1']),
                Query::between('time', '2025-01-01 00:00:00.000', '2025-12-31 00:00:00.000'),
            ])
            ->sortDesc('time')
            ->limit(25)
            ->build();

        $expectedSql = 'SELECT `id`, `event`, `time` FROM `default`.`audits` '
            . 'WHERE `actorId` IN ({param0:String}) '
            . 'AND `time` BETWEEN {param1:DateTime64(3)} AND {param2:DateTime64(3)} '
            . 'ORDER BY `time` DESC '
            . 'LIMIT {param3:Int64}';

        $this->assertSame($expectedSql, $statement->query);
        $this->assertSame(
            [
                'param0' => 'u1',
                'param1' => '2025-01-01 00:00:00.000',
                'param2' => '2025-12-31 00:00:00.000',
                'param3' => 25,
            ],
            $statement->namedBindings,
        );
    }

    public function testEqualMultiValueEmitsTypedIn(): void
    {
        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`, `event`, `time`')
            ->filter([
                Query::notEqual('event', ['users.delete', 'projects.delete']),
            ])
            ->limit(25)
            ->build();

        $expectedSql = 'SELECT `id`, `event`, `time` FROM `default`.`audits` '
            . 'WHERE `event` NOT IN ({param0:String}, {param1:String}) '
            . 'LIMIT {param2:Int64}';

        $this->assertSame($expectedSql, $statement->query);
        $this->assertSame(
            [
                'param0' => 'users.delete',
                'param1' => 'projects.delete',
                'param2' => 25,
            ],
            $statement->namedBindings,
        );
    }

    /**
     * `contains` is a substring match on ClickHouse — the builder emits
     * `position(col, ?) > 0`, which replaces the adapter's previous
     * hand-written `LIKE '%needle%'` (and needs no wildcard escaping).
     */
    public function testContainsEmitsPositionPredicate(): void
    {
        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`, `event`, `time`')
            ->filter([Query::containsString('event', ['dat'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `event`, `time` FROM `default`.`audits` '
            . 'WHERE position(`event`, {param0:String}) > 0',
            $statement->query,
        );
        $this->assertSame(['param0' => 'dat'], $statement->namedBindings);
    }

    public function testContainsMultiValueOrsPositionPredicates(): void
    {
        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`')
            ->filter([Query::containsString('event', ['dat', 'ins'])])
            ->build();

        $this->assertSame(
            'SELECT `id` FROM `default`.`audits` '
            . 'WHERE (position(`event`, {param0:String}) > 0 OR position(`event`, {param1:String}) > 0)',
            $statement->query,
        );
        $this->assertSame(['param0' => 'dat', 'param1' => 'ins'], $statement->namedBindings);
    }

    public function testNotContainsMultiValueAndsNegatedPositionPredicates(): void
    {
        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`')
            ->filter([Query::notContains('event', ['update', 'delete'])])
            ->build();

        $this->assertSame(
            'SELECT `id` FROM `default`.`audits` '
            . 'WHERE (position(`event`, {param0:String}) = 0 AND position(`event`, {param1:String}) = 0)',
            $statement->query,
        );
        $this->assertSame(['param0' => 'update', 'param1' => 'delete'], $statement->namedBindings);
    }

    public function testFindCursorRawFragmentMergesWithTypedBindings(): void
    {
        $cursorClause = '((`time` < {cursor_cmp_0:DateTime64(3)}) '
            . 'OR (`time` = {cursor_eq_1_0:DateTime64(3)} AND `id` < {cursor_cmp_1:String}))';

        $statement = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('`id`, `event`, `time`')
            ->filter([Query::equal('actorId', ['u1'])])
            ->whereRaw($cursorClause)
            ->sortDesc('time')
            ->sortDesc('id')
            ->limit(25)
            ->build();

        $expectedSql = 'SELECT `id`, `event`, `time` FROM `default`.`audits` '
            . 'WHERE `actorId` IN ({param0:String}) '
            . 'AND ' . $cursorClause . ' '
            . 'ORDER BY `time` DESC, `id` DESC '
            . 'LIMIT {param1:Int64}';

        $this->assertSame($expectedSql, $statement->query);
        $this->assertSame(
            [
                'param0' => 'u1',
                'param1' => 25,
            ],
            $statement->namedBindings,
        );
    }

    public function testCountWithMaxWrapsInnerSelect(): void
    {
        $inner = $this->newAuditBuilder()
            ->from('default.audits')
            ->selectRaw('1')
            ->filter([Query::equal('actorId', ['u1'])])
            ->limit(5000)
            ->build();

        $sql = 'SELECT COUNT(*) AS count FROM (' . $inner->query . ') sub FORMAT TabSeparated';

        $this->assertSame(
            'SELECT COUNT(*) AS count FROM ('
            . 'SELECT 1 FROM `default`.`audits` WHERE `actorId` IN ({param0:String}) LIMIT {param1:Int64}'
            . ') sub FORMAT TabSeparated',
            $sql,
        );
        $this->assertSame(
            ['param0' => 'u1', 'param1' => 5000],
            $inner->namedBindings,
        );
    }
}
