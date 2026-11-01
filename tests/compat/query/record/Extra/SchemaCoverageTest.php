<?php

declare(strict_types=1);

namespace Utopia\Query\Tests\Extra;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Schema\ClickHouse;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\MongoDB;
use Utopia\Query\Schema\MySQL;
use Utopia\Query\Schema\PostgreSQL;
use Utopia\Query\Schema\SQLite;

/**
 * Calls the schema API the ported PHP tests leave out, so every public
 * symbol has a compat case (see tests/compat/query/record/record.php).
 */
final class SchemaCoverageTest extends TestCase
{
    public function testCompileMySQL(): void
    {
        $schema = new MySQL();
        $table = $schema->table('users');
        $table->id();
        $table->string('name');
        $table->partitionByHash('`id`', 4);
        $this->assertNotSame('', $schema->compileCreate($table)->query);
        $this->assertNotSame('', $schema->compileCreate($table, true)->query);
        $this->assertNotSame('', $schema->compileCreatePartitioning($table));
        $alter = $schema->table('users');
        $alter->string('nickname');
        $this->assertNotSame('', $schema->compileAlter($alter)->query);
        $this->assertNotSame('', $schema->compileDrop('users', false)->query);
        $this->assertNotSame('', $schema->compileDrop('users', true)->query);
        $this->assertNotSame('', $schema->compileRename('users', 'people')->query);
        $this->assertNotSame('', $schema->compileTruncate('users')->query);
        $this->assertNotSame('', $schema->dropDatabase('app')->query);
    }

    public function testCompilePostgreSQL(): void
    {
        $schema = new PostgreSQL();
        $table = $schema->table('events');
        $table->id();
        $table->partitionByList('region');
        $this->assertNotSame('', $schema->compileCreate($table)->query);
        $this->assertNotSame('', $schema->compileCreatePartitioning($table));
        $alter = $schema->table('events');
        $alter->string('region');
        $this->assertNotSame('', $schema->compileAlter($alter)->query);
        $this->assertNotSame('', $schema->compileDrop('events', true)->query);
        $this->assertNotSame('', $schema->compileRename('events', 'old_events')->query);
        $this->assertNotSame('', $schema->compileTruncate('events')->query);
    }

    public function testCompileSQLite(): void
    {
        $schema = new SQLite();
        $table = $schema->table('notes');
        $table->id();
        $table->string('body');
        $this->assertNotSame('', $schema->compileCreate($table)->query);
        $this->assertNotSame('', $schema->compileCreate($table, true)->query);
        $alter = $schema->table('notes');
        $alter->string('title');
        $this->assertNotSame('', $schema->compileAlter($alter)->query);
        $this->assertNotSame('', $schema->compileDrop('notes', false)->query);
        $this->assertNotSame('', $schema->compileRename('notes', 'memos')->query);
        $this->assertNotSame('', $schema->compileTruncate('notes')->query);
    }

    public function testCompileClickHouse(): void
    {
        $schema = new ClickHouse();
        $table = $schema->table('hits');
        $table->id();
        $table->string('url');
        $this->assertNotSame('', $schema->compileCreate($table)->query);
        $alter = $schema->table('hits');
        $alter->string('referrer');
        $this->assertNotSame('', $schema->compileAlter($alter)->query);
        $this->assertNotSame('', $schema->compileDrop('hits', true)->query);
        $this->assertNotSame('', $schema->compileRename('hits', 'visits')->query);
        $this->assertNotSame('', $schema->compileTruncate('hits')->query);
        $this->assertNotSame('', $schema->createDatabase('analytics')->query);
        $this->assertNotSame('', $schema->dropDatabase('analytics')->query);
    }

    public function testCompileMongoDB(): void
    {
        $schema = new MongoDB();
        $table = $schema->table('users');
        $table->id();
        $table->string('name');
        $this->assertNotSame('', $schema->compileCreate($table)->query);
        $this->assertNotSame('', $schema->compileCreate($table, true)->query);
        $alter = $schema->table('users');
        $alter->string('email');
        $this->assertNotSame('', $schema->compileAlter($alter)->query);
        $this->assertNotSame('', $schema->compileDrop('users', false)->query);
        $this->assertNotSame('', $schema->compileRename('users', 'people')->query);
        $this->assertNotSame('', $schema->compileTruncate('users')->query);
    }

    public function testSchemaExecutor(): void
    {
        $schema = (new MySQL())->setExecutor(fn (Statement $statement) => 1);
        $this->assertSame(1, $schema->table('users')->drop()->execute());
    }

    public function testEngines(): void
    {
        // Only Memory, Log, TinyLog and StripeLog do without an ORDER BY.
        $unordered = 0;
        foreach (Engine::cases() as $engine) {
            $unordered += $engine->requiresOrderBy() ? 0 : 1;
        }
        $this->assertSame(4, $unordered);
    }

    public function testForeignKeyActions(): void
    {
        foreach (ForeignKeyAction::cases() as $action) {
            $this->assertNotSame('', $action->toSql());
        }
    }

    public function testCreateIfNotExists(): void
    {
        foreach ([new MySQL(), new PostgreSQL(), new SQLite(), new ClickHouse(), new MongoDB()] as $schema) {
            $table = $schema->table('items');
            $table->id();
            $this->assertNotSame('', $table->createIfNotExists()->query);
        }
    }

    public function testBaseColumn(): void
    {
        $table = (new MySQL())->table('items');
        $table->string('code')->modify();
        $table->string('slug')->uuid('ref');
        $this->assertNotSame('', $table->alter()->query);
        $mongo = (new MongoDB())->table('items');
        $mongo->string('code')->primary();
        $mongo->string('slug')->uuid('ref')->modify();
        $this->assertNotSame('', $mongo->create()->query);
    }

    public function testClickHouseColumns(): void
    {
        $table = (new ClickHouse())->table('hits');
        $id = $table->integer('id');
        $tags = $table->string('tags')->asArray(ColumnType::String);
        $this->assertTrue($tags->isArray());
        $this->assertFalse($tags->isTuple());
        $code = $table->string('code')->asFixedString(3);
        $this->assertTrue($code->isFixedString());
        $this->assertFalse($code->isArray());
        $pair = $table->string('pair')->asTuple([ColumnType::String, ColumnType::Integer]);
        $this->assertTrue($pair->isTuple());
        $this->assertFalse($pair->isFixedString());
        $table->primary(['id', 'name_key']);
        $this->assertNotSame('', $table->create()->query);
        $alter = (new ClickHouse())->table('hits');
        $alter->string('a')->renameColumn('old', 'new');
        $this->assertNotSame('', $alter->alter()->query);
    }

    public function testMySQLColumns(): void
    {
        $table = (new MySQL())->table('places');
        $table->integer('id')->autoIncrement();
        $table->point('location')->srid(3857);
        $table->string('a')->serial('seq');
        $table->string('b')->bigSerial('big_seq');
        $table->string('c')->smallSerial('small_seq');
        $table->smallSerial('tiny');
        $table->primary(['id', 'name_key']);
        $table->spatialIndex(['location']);
        $table->spatialIndex(['location'], 'sp_location');
        $this->assertNotSame('', $table->create()->query);
        $alter = (new MySQL())->table('places');
        $alter->string('d')->renameColumn('old', 'new');
        $this->assertNotSame('', $alter->alter()->query);
    }

    public function testMySQLPartitions(): void
    {
        $table = (new MySQL())->table('orders');
        $table->id();
        $table->partitionByRange('`id`');
        $this->assertNotSame('', $table->create()->query);
        $table = (new MySQL())->table('orders');
        $table->id();
        $table->partitionByList('`id`');
        $this->assertNotSame('', $table->create()->query);
        $table = (new MySQL())->table('orders');
        $table->id();
        $table->partitionByHash('`id`');
        $this->assertNotSame('', $table->create()->query);
    }

    public function testMySQLForeignKeyForwarding(): void
    {
        $table = (new MySQL())->table('orders');
        $table->id();
        $table->foreignKey('user_id')->references('id')->on('users')->smallSerial('small_seq');
        $table->foreignKey('team_id')->references('id')->on('teams')->bigSerial('big_seq');
        $this->assertNotSame('', $table->create()->query);
        $alter = (new MySQL())->table('orders');
        $alter->foreignKey('user_id')->renameColumn('old', 'new');
        $alter->foreignKey('user_id')->dropColumn('legacy');
        $this->assertNotSame('', $alter->alter()->query);
    }

    public function testPostgreSQLColumns(): void
    {
        $table = (new PostgreSQL())->table('places');
        $table->integer('id');
        $table->point('location')->srid(3857);
        $table->vector('embedding', 3)->dimensions(8);
        $table->integer('n')->autoIncrement();
        $table->foreignKey('user_id')->references('id')->on('users')->onDelete(ForeignKeyAction::Cascade);
        $table->primary(['id', 'name_key']);
        $this->assertNotSame('', $table->create()->query);
    }

    public function testPostgreSQLPartitionByList(): void
    {
        $table = (new PostgreSQL())->table('events');
        $table->id();
        $table->partitionByList('region');
        $this->assertNotSame('', $table->create()->query);
    }

    public function testSQLiteColumns(): void
    {
        $table = (new SQLite())->table('shapes');
        $table->integer('id')->autoIncrement();
        $table->integer('width')->comment('in pixels');
        $table->integer('height');
        $table->integer('area')->generatedAs('`width` * `height`')->stored();
        $table->integer('perimeter')->generatedAs('2 * (`width` + `height`)')->virtual();
        $table->string('a')->serial('seq');
        $table->string('b')->bigSerial('big_seq');
        $table->string('c')->smallSerial('small_seq');
        $table->bigSerial('big');
        $table->smallSerial('small');
        $table->check('positive_width', '`width` > 0');
        $table->primary(['id', 'name_key']);
        $this->assertNotSame('', $table->create()->query);
        $alter = (new SQLite())->table('shapes');
        $alter->string('d')->renameColumn('old', 'new');
        $this->assertNotSame('', $alter->alter()->query);
    }

    public function testSQLiteForeignKeyForwarding(): void
    {
        $table = (new SQLite())->table('orders');
        $table->id();
        $table->foreignKey('user_id')->references('id')->on('users')
            ->smallSerial('small_seq');
        $table->foreignKey('team_id')->references('id')->on('teams')
            ->bigSerial('big_seq');
        $table->foreignKey('owner_id')->references('id')->on('users')
            ->foreignKey('group_id')->references('id')->on('groups');
        $this->assertNotSame('', $table->create()->query);
        $alter = (new SQLite())->table('orders');
        $alter->foreignKey('user_id')->renameColumn('old', 'new');
        $alter->foreignKey('user_id')->dropColumn('legacy');
        $this->assertNotSame('', $alter->alter()->query);
    }

    public function testMongoDBColumns(): void
    {
        $table = (new MongoDB())->table('docs');
        $table->id();
        $table->vector('embedding');
        $table->bigSerial('big');
        $table->smallSerial('small');
        $table->string('a')->serial('seq');
        $table->string('b')->bigSerial('big_seq');
        $table->string('c')->smallSerial('small_seq');
        $this->assertNotSame('', $table->create()->query);
    }
}
