<?php

namespace Tests\Query\Schema;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Schema\ClickHouse;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ForeignKey;
use Utopia\Query\Schema\MongoDB;
use Utopia\Query\Schema\MySQL;
use Utopia\Query\Schema\PostgreSQL;
use Utopia\Query\Schema\SQLite;
use Utopia\Query\Schema\Table;

/**
 * Exercises the per-dialect Column → Table and ForeignKey → Table forwarder
 * traits. These are thin one-liners that delegate to the parent table; the
 * goal here is to walk every public method on every {@see \Utopia\Query\Schema\Forwarder}
 * trait so the dialect surface area is fully gated and covered.
 */
class ForwarderTest extends TestCase
{
    public function testMySQLColumnForwarderExposesAllMySQLMethods(): void
    {
        $table = (new MySQL())->table('orders');
        $col = $table->integer('user_id');

        $this->assertInstanceOf(Column\MySQL::class, $col);

        $fk = $col->foreignKey('user_id');
        $this->assertInstanceOf(ForeignKey\MySQL::class, $fk);
        $this->assertSame($fk, $table->foreignKeys[0]);

        $alias = $col->addForeignKey('user_id');
        $this->assertInstanceOf(ForeignKey\MySQL::class, $alias);
        $this->assertCount(2, $table->foreignKeys);

        $this->assertSame($table, $col->dropForeignKey('fk_old'));
        $this->assertSame(['fk_old'], $table->dropForeignKeys);

        $this->assertSame($table, $col->partitionByRange('toYear(created_at)'));
        $this->assertSame($table, $col->partitionByList('region'));
        $this->assertSame($table, $col->partitionByHash('id', 4));
        $this->assertSame(4, $table->partitionCount);

        $this->assertSame($table, $col->fulltextIndex(['body'], 'ft_body'));
        $this->assertSame($table, $col->spatialIndex(['location'], 'sp_loc'));
    }

    public function testMySQLColumnDualPurposePrimaryAndCheck(): void
    {
        $table = (new MySQL())->table('order_items');
        $a = $table->integer('order_id');
        $b = $table->integer('product_id');

        // No-args primary stays on the column.
        $this->assertSame($a, $a->primary());
        $this->assertTrue($a->isPrimary);

        // Array form delegates to table.
        $this->assertSame($table, $b->primary(['order_id', 'product_id']));
        $this->assertSame(['order_id', 'product_id'], $table->compositePrimaryKey);

        // Single-arg check stays on the column.
        $this->assertSame($a, $a->check('`order_id` > 0'));
        $this->assertSame('`order_id` > 0', $a->checkExpression);

        // Two-arg check delegates to table.
        $this->assertSame($table, $b->check('product_positive', '`product_id` > 0'));
        $this->assertCount(1, $table->checks);
        $this->assertSame('product_positive', $table->checks[0]->name);
    }

    public function testMySQLForeignKeyForwarderExposesAllMethods(): void
    {
        $table = (new MySQL())->table('orders');
        $table->integer('user_id');
        $fk = $table->foreignKey('user_id');

        $second = $fk->foreignKey('order_id');
        $this->assertInstanceOf(ForeignKey\MySQL::class, $second);
        $this->assertCount(2, $table->foreignKeys);

        $third = $fk->addForeignKey('promo_id');
        $this->assertInstanceOf(ForeignKey\MySQL::class, $third);

        $this->assertSame($table, $fk->dropForeignKey('fk_old'));
        $this->assertSame($table, $fk->partitionByRange('toYear(created_at)'));
        $this->assertSame($table, $fk->partitionByList('region'));
        $this->assertSame($table, $fk->partitionByHash('id', 8));
        $this->assertSame(8, $table->partitionCount);

        $this->assertSame($table, $fk->fulltextIndex(['body']));
        $this->assertSame($table, $fk->spatialIndex(['location']));

        // Table-level forms only — no dual purpose on FK.
        $this->assertSame($table, $fk->primary(['a', 'b']));
        $this->assertSame(['a', 'b'], $table->compositePrimaryKey);

        $this->assertSame($table, $fk->check('age_min', '`age` >= 18'));
        $this->assertCount(1, $table->checks);
    }

    public function testPostgreSQLColumnForwarderIncludesVector(): void
    {
        $table = (new PostgreSQL())->table('items');
        $col = $table->integer('id');

        $this->assertInstanceOf(Column\PostgreSQL::class, $col);

        $vector = $col->vector('embedding', 1536);
        $this->assertInstanceOf(Column\PostgreSQL::class, $vector);
        $this->assertSame(1536, $vector->dimensions);

        $this->assertSame($table, $col->partitionByRange('created_at'));
        $this->assertSame($table, $col->partitionByList('region'));
        $this->assertSame($table, $col->partitionByHash('id', 2));
        $this->assertSame($table, $col->fulltextIndex(['body']));
        $this->assertSame($table, $col->spatialIndex(['location']));

        $fk = $col->foreignKey('order_id');
        $this->assertInstanceOf(ForeignKey\PostgreSQL::class, $fk);
        $this->assertSame($table, $col->dropForeignKey('fk_old'));
    }

    public function testSQLiteColumnForwarderHasOnlyForeignKeysAndDualPurpose(): void
    {
        $table = (new SQLite())->table('orders');
        $col = $table->integer('user_id');

        $this->assertInstanceOf(Column\SQLite::class, $col);

        $fk = $col->foreignKey('user_id');
        $this->assertInstanceOf(ForeignKey\SQLite::class, $fk);

        // Dual-purpose primary/check from the dialect Column class.
        $this->assertSame($col, $col->primary());
        $this->assertTrue($col->isPrimary);

        $second = $table->integer('a');
        $this->assertSame($table, $second->primary(['a', 'b']));
        $this->assertSame(['a', 'b'], $table->compositePrimaryKey);

        $this->assertSame($col, $col->check('`user_id` > 0'));
        $this->assertSame('`user_id` > 0', $col->checkExpression);

        $this->assertSame($table, $second->check('age_min', '`age` >= 18'));
        $this->assertCount(1, $table->checks);
    }

    public function testSQLiteHasNoAlterForeignKeyMethods(): void
    {
        $table = (new SQLite())->table('orders');
        $col = $table->integer('user_id');
        $fk = $table->foreignKey('user_id');

        $this->assertFalse(\method_exists($table, 'addForeignKey'));
        $this->assertFalse(\method_exists($table, 'dropForeignKey'));
        $this->assertFalse(\method_exists($col, 'addForeignKey'));
        $this->assertFalse(\method_exists($col, 'dropForeignKey'));
        $this->assertFalse(\method_exists($fk, 'addForeignKey'));
        $this->assertFalse(\method_exists($fk, 'dropForeignKey'));
    }

    public function testSQLiteForeignKeyForwarderTableLevelMethods(): void
    {
        $table = (new SQLite())->table('orders');
        $table->integer('user_id');
        $fk = $table->foreignKey('user_id');

        $this->assertSame($table, $fk->primary(['a', 'b']));
        $this->assertSame($table, $fk->check('age_min', '`age` >= 18'));
    }

    public function testClickHouseColumnForwarderEngineOrderBySettingsPartition(): void
    {
        $table = (new ClickHouse())->table('events');
        $col = $table->integer('id');

        $this->assertInstanceOf(Column\ClickHouse::class, $col);

        $vector = $col->vector('embedding');
        $this->assertInstanceOf(Column\ClickHouse::class, $vector);
        // ClickHouse vectors are Array(Float64) and carry no declared width.
        $this->assertNull($vector->dimensions);

        $this->assertSame($table, $col->engine(Engine::MergeTree));
        $this->assertSame(Engine::MergeTree, $table->engine);

        $this->assertSame($table, $col->orderBy(['id']));
        $this->assertSame(['id'], $table->orderBy);

        $this->assertSame($table, $col->settings(['index_granularity' => 8192]));
        $this->assertSame(['index_granularity' => '8192'], $table->settings);

        $this->assertSame($table, $col->partitionBy('toYYYYMM(created_at)'));
        $this->assertSame('toYYYYMM(created_at)', $table->partitionExpression);
    }

    public function testClickHouseColumnPrimaryDualPurpose(): void
    {
        $table = (new ClickHouse())->table('events');
        $col = $table->integer('id');

        // Column-level primary stays on the column.
        $this->assertSame($col, $col->primary());
        $this->assertTrue($col->isPrimary);

        // ClickHouse supports composite primary via the array form.
        $a = $table->integer('a');
        $this->assertSame($table, $a->primary(['a', 'b']));
        $this->assertSame(['a', 'b'], $table->compositePrimaryKey);
    }

    public function testMongoDBColumnForwarderExposesVectorOnly(): void
    {
        $table = (new MongoDB())->table('items');
        $col = $table->integer('id');

        $this->assertInstanceOf(Column\MongoDB::class, $col);

        $vector = $col->vector('embedding');
        $this->assertInstanceOf(Column\MongoDB::class, $vector);
        // MongoDB's array bsonType is unsized.
        $this->assertNull($vector->dimensions);
    }

    public function testTableEntryPointReturnsDialectSpecificType(): void
    {
        $this->assertInstanceOf(Table\MySQL::class, (new MySQL())->table('t'));
        $this->assertInstanceOf(Table\PostgreSQL::class, (new PostgreSQL())->table('t'));
        $this->assertInstanceOf(Table\SQLite::class, (new SQLite())->table('t'));
        $this->assertInstanceOf(Table\ClickHouse::class, (new ClickHouse())->table('t'));
        $this->assertInstanceOf(Table\MongoDB::class, (new MongoDB())->table('t'));
    }
}
