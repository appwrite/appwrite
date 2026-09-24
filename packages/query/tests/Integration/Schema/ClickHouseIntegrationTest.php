<?php

namespace Tests\Integration\Schema;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Schema\ClickHouse;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ColumnType;

class ClickHouseIntegrationTest extends IntegrationTestCase
{
    private ClickHouse $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new ClickHouse();
    }

    public function testCreateTableWithMergeTreeEngine(): void
    {
        $table = 'test_mergetree_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('name', 100)
            ->integer('value')
            ->create();

        $this->clickhouseStatement($result->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT name, type FROM system.columns WHERE database = 'query_test' AND table = '{$table}' ORDER BY position"
        );

        $columnNames = array_column($rows, 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('value', $columnNames);

        $tables = $ch->execute(
            "SELECT engine FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertSame('MergeTree', $tables[0]['engine']);
    }

    public function testCreateTableWithNullableColumns(): void
    {
        $table = 'test_nullable_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('optional_name', 100)->nullable()
            ->integer('optional_count')->nullable()
            ->create();

        $this->clickhouseStatement($result->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT name, type FROM system.columns WHERE database = 'query_test' AND table = '{$table}'"
        );

        $typeMap = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $type = $row['type'];
            \assert(\is_string($name) && \is_string($type));
            $typeMap[$name] = $type;
        }

        $this->assertStringContainsString('Nullable', $typeMap['optional_name']);
        $this->assertStringContainsString('Nullable', $typeMap['optional_count']);
        $this->assertStringNotContainsString('Nullable', $typeMap['id']);
    }

    public function testAlterTableAddColumn(): void
    {
        $table = 'test_alter_add_' . uniqid();
        $this->trackClickhouseTable($table);

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->create();
        $this->clickhouseStatement($create->query);

        $alter = $this->schema->table($table)
            ->addColumn('description', ColumnType::String, 200)
            ->alter();
        $this->clickhouseStatement($alter->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT name FROM system.columns WHERE database = 'query_test' AND table = '{$table}'"
        );

        $columnNames = array_column($rows, 'name');
        $this->assertContains('description', $columnNames);
    }

    public function testDropTable(): void
    {
        $table = 'test_drop_' . uniqid();

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->create();
        $this->clickhouseStatement($create->query);

        $drop = $this->schema->table($table)->drop();
        $this->clickhouseStatement($drop->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT count() as cnt FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );

        $this->assertSame('0', (string) $rows[0]['cnt']); // @phpstan-ignore cast.string
    }

    public function testCreateTableWithDateTimePrecision(): void
    {
        $table = 'test_dt64_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->datetime('created_at', 3)
            ->datetime('updated_at', 6)
            ->create();

        $this->clickhouseStatement($result->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT name, type FROM system.columns WHERE database = 'query_test' AND table = '{$table}'"
        );

        $typeMap = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            $type = $row['type'];
            \assert(\is_string($name) && \is_string($type));
            $typeMap[$name] = $type;
        }

        $this->assertSame('DateTime64(3)', $typeMap['created_at']);
        $this->assertSame('DateTime64(6)', $typeMap['updated_at']);
    }

    public function testCreateReplacingMergeTree(): void
    {
        $table = 'test_replacing_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->unsigned()->primary()
            ->string('name')
            ->integer('version')->unsigned()
            ->engine(Engine::ReplacingMergeTree, 'version')
            ->create();

        $this->clickhouseStatement($result->query);

        $this->clickhouseStatement(
            'INSERT INTO `' . $table . "` (`id`, `name`, `version`) VALUES (1, 'v1', 1)"
        );
        $this->clickhouseStatement(
            'INSERT INTO `' . $table . "` (`id`, `name`, `version`) VALUES (1, 'v2', 2)"
        );

        $ch = $this->connectClickhouse();

        $engineRows = $ch->execute(
            "SELECT engine FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertSame('ReplacingMergeTree', $engineRows[0]['engine']);

        $rows = $ch->execute('SELECT `name` FROM `' . $table . '` FINAL WHERE `id` = 1');
        $this->assertCount(1, $rows);
        $this->assertSame('v2', $rows[0]['name']);
    }

    public function testCreateSummingMergeTree(): void
    {
        $table = 'test_summing_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('key')->unsigned()->primary()
            ->bigInteger('total')->unsigned()
            ->engine(Engine::SummingMergeTree, 'total')
            ->create();

        $this->clickhouseStatement($result->query);

        $this->clickhouseStatement(
            'INSERT INTO `' . $table . '` (`key`, `total`) VALUES (1, 10), (1, 20), (2, 5)'
        );

        $ch = $this->connectClickhouse();

        $engineRows = $ch->execute(
            "SELECT engine FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertSame('SummingMergeTree', $engineRows[0]['engine']);

        // OPTIMIZE to force a merge so summing takes effect.
        $this->clickhouseStatement('OPTIMIZE TABLE `' . $table . '` FINAL');

        $rows = $ch->execute(
            'SELECT `key`, `total` FROM `' . $table . '` ORDER BY `key`'
        );
        $this->assertCount(2, $rows);
        $this->assertSame(30, (int) $rows[0]['total']); // @phpstan-ignore cast.int
        $this->assertSame(5, (int) $rows[1]['total']); // @phpstan-ignore cast.int
    }

    public function testCreateAggregatingMergeTree(): void
    {
        // Schema builder lacks AggregatingMergeTree support — use raw DDL.
        $table = 'test_aggregating_' . uniqid();
        $this->trackClickhouseTable($table);

        $this->clickhouseStatement('
            CREATE TABLE `' . $table . '` (
                `key` UInt32,
                `max_value` AggregateFunction(max, UInt32)
            ) ENGINE = AggregatingMergeTree()
            ORDER BY `key`
        ');

        $this->clickhouseStatement(
            'INSERT INTO `' . $table . '` (`key`, `max_value`) '
            . 'SELECT `key`, maxState(toUInt32(`value`)) FROM ('
            . '  SELECT toUInt32(1) AS `key`, toUInt32(10) AS `value` UNION ALL '
            . '  SELECT toUInt32(1) AS `key`, toUInt32(50) AS `value` UNION ALL '
            . '  SELECT toUInt32(2) AS `key`, toUInt32(5) AS `value`'
            . ') GROUP BY `key`'
        );

        $ch = $this->connectClickhouse();

        $engineRows = $ch->execute(
            "SELECT engine FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertSame('AggregatingMergeTree', $engineRows[0]['engine']);

        $rows = $ch->execute(
            'SELECT `key`, maxMerge(`max_value`) AS `m` FROM `' . $table
            . '` GROUP BY `key` ORDER BY `key`'
        );
        $this->assertCount(2, $rows);
        $this->assertSame(50, (int) $rows[0]['m']); // @phpstan-ignore cast.int
        $this->assertSame(5, (int) $rows[1]['m']); // @phpstan-ignore cast.int
    }

    public function testCreateTableWithTTL(): void
    {
        // Schema builder does not emit TTL clauses — use raw DDL and confirm
        // the TTL expression lands on the table.
        $table = 'test_ttl_' . uniqid();
        $this->trackClickhouseTable($table);

        $this->clickhouseStatement('
            CREATE TABLE `' . $table . '` (
                `id` UInt32,
                `ts` DateTime
            ) ENGINE = MergeTree()
            ORDER BY `id`
            TTL `ts` + INTERVAL 1 DAY
        ');

        $ch = $this->connectClickhouse();

        $rows = $ch->execute(
            "SELECT engine_full FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );

        $this->assertCount(1, $rows);
        $engineFull = $rows[0]['engine_full'];
        \assert(\is_string($engineFull));
        $this->assertStringContainsString('TTL', $engineFull);
        $this->assertStringContainsString('toIntervalDay(1)', $engineFull);
    }

    public function testCreateTableWithPartitionBy(): void
    {
        // Schema builder's Table supports partitionByHash as a raw
        // expression pass-through — use it to emit PARTITION BY toYYYYMM(ts).
        $table = 'test_partition_' . uniqid();
        $this->trackClickhouseTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->datetime('ts')
            ->partitionBy('toYYYYMM(`ts`)')
            ->create();

        $this->assertStringContainsString('PARTITION BY toYYYYMM(`ts`)', $result->query);

        $this->clickhouseStatement($result->query);

        $this->clickhouseStatement(
            'INSERT INTO `' . $table . "` (`id`, `ts`) VALUES "
            . "(1, '2024-01-05 00:00:00'), "
            . "(2, '2024-02-10 00:00:00'), "
            . "(3, '2024-01-20 00:00:00')"
        );

        $ch = $this->connectClickhouse();

        $rows = $ch->execute(
            "SELECT partition_key FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertCount(1, $rows);
        $partitionKey = $rows[0]['partition_key'];
        \assert(\is_string($partitionKey));
        $this->assertStringContainsString('toYYYYMM', $partitionKey);

        $partitionRows = $ch->execute(
            "SELECT DISTINCT partition FROM system.parts "
            . "WHERE database = 'query_test' AND table = '{$table}' AND active"
        );
        // Two partitions: 202401 (two rows) and 202402 (one row).
        $this->assertSame(2, \count($partitionRows));
    }
}
