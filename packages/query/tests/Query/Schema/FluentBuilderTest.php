<?php

namespace Tests\Query\Schema;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\ClickHouse;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\Column\PostgreSQL as Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKey\PostgreSQL as ForeignKey;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\MongoDB;
use Utopia\Query\Schema\MySQL;
use Utopia\Query\Schema\PostgreSQL;
use Utopia\Query\Schema\SQLite;
use Utopia\Query\Schema\Table as BaseTable;
use Utopia\Query\Schema\Table\ClickHouse as ClickHouseTable;
use Utopia\Query\Schema\Table\MongoDB as MongoTable;
use Utopia\Query\Schema\Table\MySQL as MySQLTable;
use Utopia\Query\Schema\Table\PostgreSQL as Table;
use Utopia\Query\Schema\Table\SQLite as SQLiteTable;

/**
 * Behavioural tests for the fluent Schema builder. Covers:
 *  - Schema::table() entry point and Table terminals
 *  - Column → Table forwarders (every public Table method reachable from Column)
 *  - ForeignKey → Table forwarders
 *  - Argument-dispatching methods on Column: primary(), check(), enum()
 *  - Table::orderBy() (ClickHouse)
 *  - The detached-Table guard (requireSchema)
 *  - Long fluent chains across every dialect emit valid SQL
 */
class FluentBuilderTest extends TestCase
{
    use AssertsBindingCount;

    public function testTableEntryPointReturnsTableBoundToSchema(): void
    {
        $schema = new MySQL();
        $table = $schema->table('users');

        $this->assertInstanceOf(BaseTable::class, $table);
        $this->assertSame('users', $table->name);
    }

    public function testTableEntryPointReturnsFreshInstanceEachCall(): void
    {
        $schema = new MySQL();
        $a = $schema->table('a');
        $b = $schema->table('b');

        $this->assertNotSame($a, $b);
        $this->assertSame('a', $a->name);
        $this->assertSame('b', $b->name);
    }

    public function testColumnHoldsBackPointerToParentTable(): void
    {
        $schema = new MySQL();
        $bp = $schema->table('users');
        $col = $bp->string('name');

        $this->assertSame($bp, $col->table);
    }

    /**
     * The serial and column-alteration forwarders live on the per-dialect
     * Forwarder traits, which are shared by Column\X and ForeignKey\X. This
     * pins the ForeignKey half of that: a chain may continue through a
     * foreign key into a column factory and back out to a terminal call.
     */
    public function testChainContinuesThroughForeignKeyIntoColumnFactories(): void
    {
        foreach ([MySQL::class, PostgreSQL::class, SQLite::class] as $schemaClass) {
            $schema = new $schemaClass();

            $result = $schema->table('posts')
                ->integer('user_id')
                ->foreignKey('user_id')->references('id')->on('users')
                    ->onDelete(ForeignKeyAction::Cascade)
                ->serial('seq')
                ->create();

            $this->assertStringContainsString('seq', $result->query);
            $this->assertStringContainsString('FOREIGN KEY', $result->query);
        }
    }

    public function testForeignKeyExposesForwardersForSupportedDialects(): void
    {
        foreach ([
            \Utopia\Query\Schema\ForeignKey\MySQL::class,
            \Utopia\Query\Schema\ForeignKey\PostgreSQL::class,
            \Utopia\Query\Schema\ForeignKey\SQLite::class,
        ] as $class) {
            $methods = \get_class_methods($class);

            foreach (['serial', 'bigSerial', 'smallSerial', 'renameColumn', 'dropColumn'] as $method) {
                $this->assertContains($method, $methods, "{$class} must forward {$method}()");
            }
        }
    }

    public function testForeignKeyHoldsBackPointerToParentTable(): void
    {
        $schema = new MySQL();
        $bp = $schema->table('orders');
        $fk = $bp->foreignKey('user_id');

        $this->assertSame($bp, $fk->table);
    }

    public function testColumnAddingMethodsAppendToTable(): void
    {
        $bp = new Table();
        $bp->string('a')
            ->integer('b')
            ->boolean('c')
            ->datetime('d');

        $this->assertCount(4, $bp->columns);
        $this->assertSame(['a', 'b', 'c', 'd'], \array_column($bp->columns, 'name'));
    }

    public function testColumnForwardsToEveryTableColumnFactory(): void
    {
        $bp = new Table();
        $bp->string('s')
            ->text('t')
            ->mediumText('mt')
            ->longText('lt')
            ->integer('i')
            ->bigInteger('bi')
            ->serial('sr')
            ->bigSerial('bsr')
            ->smallSerial('ssr')
            ->float('f')
            ->boolean('b')
            ->datetime('dt')
            ->timestamp('ts')
            ->json('j')
            ->binary('bin')
            ->point('p')
            ->linestring('ls')
            ->polygon('pg')
            ->vector('v', 8)
            ->id('id_col');

        $names = \array_column($bp->columns, 'name');
        $this->assertSame(
            ['s', 't', 'mt', 'lt', 'i', 'bi', 'sr', 'bsr', 'ssr', 'f', 'b', 'dt', 'ts', 'j', 'bin', 'p', 'ls', 'pg', 'v', 'id_col'],
            $names,
        );

        $types = \array_column($bp->columns, 'type');
        $this->assertSame([
            ColumnType::String,
            ColumnType::Text,
            ColumnType::MediumText,
            ColumnType::LongText,
            ColumnType::Integer,
            ColumnType::BigInteger,
            ColumnType::Serial,
            ColumnType::BigSerial,
            ColumnType::SmallSerial,
            ColumnType::Float,
            ColumnType::Boolean,
            ColumnType::Datetime,
            ColumnType::Timestamp,
            ColumnType::Json,
            ColumnType::Binary,
            ColumnType::Point,
            ColumnType::Linestring,
            ColumnType::Polygon,
            ColumnType::Vector,
            ColumnType::BigInteger,
        ], $types);
    }

    public function testColumnForwardsTimestamps(): void
    {
        $bp = new Table();
        $bp->string('name')->timestamps(6);

        $this->assertCount(3, $bp->columns);
        $this->assertSame('created_at', $bp->columns[1]->name);
        $this->assertSame('updated_at', $bp->columns[2]->name);
        $this->assertSame(6, $bp->columns[1]->precision);
    }

    public function testColumnForwardsAddColumnAndModifyColumn(): void
    {
        $bp = new Table();
        $bp->string('name')
            ->addColumn('phone', ColumnType::String, 30)
            ->modifyColumn('email', ColumnType::String, 200);

        $this->assertCount(3, $bp->columns);
        $this->assertSame('phone', $bp->columns[1]->name);
        $this->assertFalse($bp->columns[1]->isModify);
        $this->assertSame('email', $bp->columns[2]->name);
        $this->assertTrue($bp->columns[2]->isModify);
    }

    public function testColumnForwardsRenameColumnAndDropColumn(): void
    {
        $bp = new Table();
        $bp->string('name')
            ->renameColumn('old', 'new')
            ->dropColumn('legacy');

        $this->assertSame([['from' => 'old', 'to' => 'new']], \array_map(
            fn ($r) => ['from' => $r->from, 'to' => $r->to],
            $bp->renameColumns,
        ));
        $this->assertSame(['legacy'], $bp->dropColumns);
    }

    public function testColumnForwardsIndexFamily(): void
    {
        // Each call must hit a fresh Column: index methods return Table, so
        // chaining a second method off a Column would route through Table.
        $bp = new Table();
        $col = fn (): Column => $bp->string('name');

        $col()->index(['name']);
        $col()->uniqueIndex(['name'], 'uq_name');
        $col()->fulltextIndex(['name'], 'ft_name');
        $col()->spatialIndex(['name'], 'sp_name');
        $col()->addIndex('custom', ['name'], IndexType::Unique);

        $this->assertCount(5, $bp->indexes);
        $this->assertSame('idx_name', $bp->indexes[0]->name);
        $this->assertSame(IndexType::Index, $bp->indexes[0]->type);
        $this->assertSame(IndexType::Unique, $bp->indexes[1]->type);
        $this->assertSame(IndexType::Fulltext, $bp->indexes[2]->type);
        $this->assertSame(IndexType::Spatial, $bp->indexes[3]->type);
        $this->assertSame('custom', $bp->indexes[4]->name);
        $this->assertSame(IndexType::Unique, $bp->indexes[4]->type);
    }

    public function testColumnForwardsDropIndex(): void
    {
        $bp = new Table();
        $bp->string('name')->dropIndex('idx_old');

        $this->assertSame(['idx_old'], $bp->dropIndexes);
    }

    public function testColumnForwardsForeignKeyFactories(): void
    {
        $bp = new Table();
        $fk = $bp->string('name')->foreignKey('user_id');

        $this->assertInstanceOf(ForeignKey::class, $fk);
        $this->assertSame('user_id', $fk->column);
        $this->assertCount(1, $bp->foreignKeys);
    }

    public function testColumnForwardsAddAndDropForeignKey(): void
    {
        $bp = new Table();
        $fk = $bp->string('name')->addForeignKey('parent_id');
        $bp->string('other')->dropForeignKey('fk_old');

        $this->assertSame('parent_id', $fk->column);
        $this->assertCount(1, $bp->foreignKeys);
        $this->assertSame(['fk_old'], $bp->dropForeignKeys);
    }

    public function testColumnForwardsRawColumnAndRawIndex(): void
    {
        $bp = new Table();
        $bp->string('name')
            ->rawColumn('`extra` JSON NOT NULL')
            ->rawIndex('FULLTEXT INDEX `ft` (`name`)');

        $this->assertSame(['`extra` JSON NOT NULL'], $bp->rawColumnDefs);
        $this->assertSame(['FULLTEXT INDEX `ft` (`name`)'], $bp->rawIndexDefs);
    }

    public function testColumnForwardsPartitionFamily(): void
    {
        $bp = new Table();
        $bp->integer('id')->partitionByRange('`id`');
        $this->assertNotNull($bp->partitionType);

        $bp2 = new Table();
        $bp2->integer('id')->partitionByList('`id`');
        $this->assertNotNull($bp2->partitionType);

        $bp3 = new Table();
        $bp3->integer('id')->partitionByHash('`id`', 4);
        $this->assertSame(4, $bp3->partitionCount);
    }

    public function testColumnForwardsEngineAndOrderByAndTtl(): void
    {
        $bp = (new ClickHouse())->table('events');
        $bp->integer('id')
            ->engine(Engine::MergeTree)
            ->orderBy(['id'])
            ->partitionBy('toYYYYMM(id)');

        $this->assertSame(Engine::MergeTree, $bp->engine);
        $this->assertSame(['id'], $bp->orderBy);
        $this->assertSame('toYYYYMM(id)', $bp->partitionExpression);
    }

    public function testColumnPrimaryNoArgsMarksColumn(): void
    {
        $bp = new Table();
        $col = $bp->integer('id')->primary();

        $this->assertInstanceOf(Column::class, $col);
        $this->assertTrue($col->isPrimary);
        $this->assertSame([], $bp->compositePrimaryKey);
    }

    public function testColumnPrimaryWithArrayDelegatesToTableCompositeKey(): void
    {
        $bp = new Table();
        $result = $bp->integer('a')->integer('b')->primary(['a', 'b']);

        $this->assertSame($bp, $result);
        $this->assertSame(['a', 'b'], $bp->compositePrimaryKey);
        $this->assertFalse($bp->columns[0]->isPrimary);
        $this->assertFalse($bp->columns[1]->isPrimary);
    }

    public function testColumnCheckOneArgIsColumnLevel(): void
    {
        $bp = new Table();
        $col = $bp->integer('age')->check('`age` >= 0');

        $this->assertInstanceOf(Column::class, $col);
        $this->assertSame('`age` >= 0', $col->checkExpression);
        $this->assertCount(0, $bp->checks);
    }

    public function testColumnCheckTwoArgsDelegatesToTableLevel(): void
    {
        $bp = new Table();
        $result = $bp->integer('age')->check('age_min', '`age` >= 18');

        $this->assertSame($bp, $result);
        $this->assertCount(1, $bp->checks);
        $this->assertSame('age_min', $bp->checks[0]->name);
        $this->assertSame('`age` >= 18', $bp->checks[0]->expression);
        $this->assertNull($bp->columns[0]->checkExpression);
    }

    public function testColumnEnumWithArraySetsValuesOnSelf(): void
    {
        $bp = new Table();
        $col = $bp->enum('status', ['draft']);
        $col->enum(['draft', 'published', 'archived']);

        $this->assertSame(['draft', 'published', 'archived'], $col->enumValues);
        $this->assertCount(1, $bp->columns);
    }

    public function testColumnEnumWithNameAndValuesAddsNewColumn(): void
    {
        $bp = new Table();
        $a = $bp->string('first');
        $b = $a->enum('status', ['draft', 'live']);

        $this->assertCount(2, $bp->columns);
        $this->assertSame('status', $b->name);
        $this->assertSame(ColumnType::Enum, $b->type);
        $this->assertSame(['draft', 'live'], $b->enumValues);
    }

    public function testForeignKeyForwardsBackToTableForChaining(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('orders')
            ->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')->onDelete(ForeignKeyAction::Cascade)
            ->string('total')
            ->create();

        $this->assertBindingCount($stmt);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', $stmt->query);
        $this->assertStringContainsString('`total` VARCHAR(255)', $stmt->query);
    }

    public function testForeignKeyForwardsTerminals(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('orders')
            ->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->create();

        $this->assertBindingCount($stmt);
        $this->assertStringContainsString('CREATE TABLE `orders`', $stmt->query);
    }

    public function testForeignKeyExposesTableMethodsLikeIndex(): void
    {
        $bp = new Table();
        $bp->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->index(['user_id']);

        $this->assertCount(1, $bp->foreignKeys);
        $this->assertCount(1, $bp->indexes);
    }

    public function testTableTerminalCreate(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')->id()->create();

        $this->assertBindingCount($stmt);
        $this->assertStringContainsString('CREATE TABLE `users`', $stmt->query);
        $this->assertStringNotContainsString('IF NOT EXISTS', $stmt->query);
    }

    public function testTableTerminalCreateIfNotExists(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')->id()->createIfNotExists();

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `users`', $stmt->query);
    }

    public function testTableTerminalAlter(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->addColumn('phone', ColumnType::String, 20)->nullable()
            ->alter();

        $this->assertSame('ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL', $stmt->query);
    }

    public function testTableTerminalDropAndDropIfExists(): void
    {
        $schema = new MySQL();
        $this->assertSame('DROP TABLE `users`', $schema->table('users')->drop()->query);
        $this->assertSame('DROP TABLE IF EXISTS `users`', $schema->table('users')->dropIfExists()->query);
    }

    public function testTableTerminalRename(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('old')->rename('new');

        $this->assertSame('RENAME TABLE `old` TO `new`', $stmt->query);
    }

    public function testTableTerminalTruncate(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')->truncate();

        $this->assertSame('TRUNCATE TABLE `users`', $stmt->query);
    }

    public function testDetachedTableThrowsOnCreate(): void
    {
        $bp = new Table();
        $bp->string('name');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot compile a Table without a Schema');
        $bp->create();
    }

    public function testDetachedTableThrowsOnAlter(): void
    {
        $bp = new Table();
        $bp->dropColumn('x');

        $this->expectException(ValidationException::class);
        $bp->alter();
    }

    public function testDetachedTableThrowsOnDrop(): void
    {
        $bp = new Table();

        $this->expectException(ValidationException::class);
        $bp->drop();
    }

    public function testDetachedTableThrowsOnTruncate(): void
    {
        $bp = new Table();

        $this->expectException(ValidationException::class);
        $bp->truncate();
    }

    public function testDetachedTableThrowsOnRename(): void
    {
        $bp = new Table();

        $this->expectException(ValidationException::class);
        $bp->rename('to');
    }

    public function testFluentChainEmitsCorrectMySqlCreate(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->id()
            ->string('name', 100)
            ->string('email', 255)->unique()
            ->boolean('active')->default(true)
            ->json('metadata')->nullable()
            ->timestamps()
            ->index(['name'])
            ->create();

        $this->assertBindingCount($stmt);
        $this->assertSame(
            'CREATE TABLE `users` ('
            . '`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, '
            . '`email` VARCHAR(255) NOT NULL, '
            . '`active` TINYINT(1) NOT NULL DEFAULT 1, '
            . '`metadata` JSON NULL, '
            . '`created_at` DATETIME(3) NOT NULL, '
            . '`updated_at` DATETIME(3) NOT NULL, '
            . 'PRIMARY KEY (`id`), '
            . 'UNIQUE (`email`), '
            . 'INDEX `idx_name` (`name`)'
            . ')',
            $stmt->query,
        );
    }

    public function testFluentChainEmitsCorrectPostgreSqlCreate(): void
    {
        $schema = new PostgreSQL();
        $stmt = $schema->table('orders')
            ->bigSerial('id')->primary()
            ->integer('user_id')
            ->json('metadata')->nullable()
            ->foreignKey('user_id')->references('id')->on('users')
            ->create();

        $this->assertBindingCount($stmt);
        $this->assertSame(
            'CREATE TABLE "orders" ('
            . '"id" BIGSERIAL NOT NULL, '
            . '"user_id" INTEGER NOT NULL, '
            . '"metadata" JSONB NULL, '
            . 'PRIMARY KEY ("id"), '
            . 'FOREIGN KEY ("user_id") REFERENCES "users" ("id")'
            . ')',
            $stmt->query,
        );
    }

    public function testFluentChainEmitsCorrectSqliteCreate(): void
    {
        $schema = new SQLite();
        $stmt = $schema->table('events')
            ->integer('id')->primary()
            ->string('payload')
            ->datetime('ts')
            ->index(['ts'])
            ->create();

        $this->assertBindingCount($stmt);
        $this->assertStringContainsString('CREATE TABLE `events`', $stmt->query);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $stmt->query);
        $this->assertStringContainsString('INDEX `idx_ts` (`ts`)', $stmt->query);
    }

    public function testFluentChainEmitsCorrectMongoDbCreate(): void
    {
        $schema = new MongoDB();
        $stmt = $schema->table('users')
            ->string('name')
            ->string('email')->nullable()
            ->integer('age')
            ->create();

        $payload = \json_decode($stmt->query, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($payload));
        $this->assertSame('createCollection', $payload['command']);
        $this->assertSame('users', $payload['collection']);
    }

    public function testClickHouseOrderByOverridesPrimaryKey(): void
    {
        $schema = new ClickHouse();
        $stmt = $schema->table('events')
            ->integer('id')->primary()
            ->datetime('ts')
            ->orderBy(['ts', 'id'])
            ->engine(Engine::MergeTree)
            ->create();

        $this->assertStringContainsString('ORDER BY (`ts`, `id`)', $stmt->query);
    }

    public function testClickHouseOrderByFallsBackToPrimaryKey(): void
    {
        $schema = new ClickHouse();
        $stmt = $schema->table('events')
            ->integer('id')->primary()
            ->datetime('ts')
            ->engine(Engine::MergeTree)
            ->create();

        $this->assertStringContainsString('ORDER BY (`id`)', $stmt->query);
    }

    public function testClickHouseOrderByFallsBackToTupleWhenNoPrimaryKey(): void
    {
        $schema = new ClickHouse();
        $stmt = $schema->table('events')
            ->integer('id')
            ->engine(Engine::MergeTree)
            ->create();

        $this->assertStringContainsString('ORDER BY tuple()', $stmt->query);
    }

    public function testClickHouseEndToEndExampleFromReadme(): void
    {
        $schema = new ClickHouse();
        $stmt = $schema->table('events')
            ->string('tenantId')
            ->string('eventId')
            ->string('payload')->nullable()
            ->datetime('createdAt')
            ->primary(['tenantId', 'eventId'])
            ->engine(Engine::MergeTree)
            ->orderBy(['tenantId', 'createdAt'])
            ->ttl('createdAt + INTERVAL 90 DAY')
            ->create();

        $this->assertSame(
            'CREATE TABLE `events` ('
            . '`tenantId` String, '
            . '`eventId` String, '
            . '`payload` Nullable(String), '
            . '`createdAt` DateTime'
            . ') ENGINE = MergeTree() ORDER BY (`tenantId`, `createdAt`) TTL createdAt + INTERVAL 90 DAY',
            $stmt->query,
        );
    }

    public function testTableOrderByRejectsInvalidIdentifier(): void
    {
        $bp = (new ClickHouse())->table('events');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid column name in ORDER BY');
        $bp->orderBy(['bad name;']);
    }

    public function testColumnPrimaryRejectsSingleColumnArrayViaTableGuard(): void
    {
        $bp = new Table();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least two columns');
        $bp->integer('id')->primary(['id']);
    }

    public function testTablePrimaryArrayWithColumnLevelPrimaryThrowsOnCompile(): void
    {
        $schema = new MySQL();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot combine column-level primary() with Table::primary()');

        $schema->table('orders')
            ->integer('a')->primary()
            ->integer('b')
            ->primary(['a', 'b'])
            ->create();
    }

    public function testColumnAfterIsHonouredOnAlter(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->addColumn('phone', ColumnType::String, 20)->after('email')
            ->alter();

        $this->assertSame(
            'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NOT NULL AFTER `email`',
            $stmt->query,
        );
    }

    public function testColumnGeneratedExpressionStored(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('boxes')
            ->integer('width')
            ->integer('height')
            ->integer('area')->generatedAs('`width` * `height`')->stored()
            ->create();

        $this->assertStringContainsString(
            "`area` INT GENERATED ALWAYS AS (`width` * `height`) STORED NOT NULL",
            $stmt->query,
        );
    }

    public function testColumnUnique(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->string('email')->unique()
            ->create();

        $this->assertStringContainsString('UNIQUE (`email`)', $stmt->query);
    }

    public function testColumnNullableAndDefault(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->string('nickname')->nullable()->default('anon')
            ->integer('score')->default(0)
            ->boolean('active')->default(true)
            ->create();

        $this->assertStringContainsString("`nickname` VARCHAR(255) NULL DEFAULT 'anon'", $stmt->query);
        $this->assertStringContainsString('`score` INT NOT NULL DEFAULT 0', $stmt->query);
        $this->assertStringContainsString('`active` TINYINT(1) NOT NULL DEFAULT 1', $stmt->query);
    }

    public function testTableCheckPropagatesThroughForeignKeyForwarder(): void
    {
        $bp = new Table();
        $bp->id()
            ->integer('parent_id')
            ->foreignKey('parent_id')->references('id')->on('parents')
            ->check('positive_id', '`parent_id` > 0');

        $this->assertCount(1, $bp->checks);
        $this->assertSame('positive_id', $bp->checks[0]->name);
    }

    public function testColumnEnumDispatchPreservesExistingValues(): void
    {
        $bp = new Table();
        $col = $bp->enum('status', ['a']);
        $col->enum(['x', 'y']);

        $this->assertSame(['x', 'y'], $col->enumValues);
    }

    public function testAddIndexAcceptsIndexTypeEnum(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->addIndex('ix_email', ['email'], IndexType::Unique)
            ->alter();

        $this->assertSame(
            'ALTER TABLE `users` ADD UNIQUE INDEX `ix_email` (`email`)',
            $stmt->query,
        );
    }

    public function testAddColumnAndModifyColumnAcceptColumnTypeEnum(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('users')
            ->addColumn('age', ColumnType::Integer)
            ->modifyColumn('name', ColumnType::String, 500)
            ->alter();

        $this->assertSame(
            'ALTER TABLE `users` ADD COLUMN `age` INT NOT NULL, '
            . 'MODIFY COLUMN `name` VARCHAR(500) NOT NULL',
            $stmt->query,
        );
    }

    public function testTableNameIsRetainedThroughCompile(): void
    {
        $schema = new MySQL();
        $stmt = $schema->table('with_underscores_123')->id()->create();

        $this->assertStringContainsString('CREATE TABLE `with_underscores_123`', $stmt->query);
    }

    public function testColumnReturnsItselfForChainingFluentMethods(): void
    {
        $bp = new Table();
        $col = $bp->string('name');

        $this->assertSame($col, $col->nullable());
        $this->assertSame($col, $col->default('x'));
        $this->assertSame($col, $col->unique());
        $this->assertSame($col, $col->primary());
        $this->assertSame($col, $col->autoIncrement());
        $this->assertSame($col, $col->collation('C'));
    }

    public function testMySQLColumnReturnsItselfForDialectScopedFluentMethods(): void
    {
        $col = (new MySQLTable())->string('name');

        $this->assertSame($col, $col->comment('hi'));
        $this->assertSame($col, $col->after('id'));
        $this->assertSame($col, $col->collation('utf8mb4_bin'));
    }

    /**
     * srid, dimensions and autoIncrement are intrinsic column properties set by
     * the factories, so the factories keep working on every dialect while the
     * modifiers exist only where the value is emitted.
     */
    public function testFactoriesStillSetIntrinsicsWhereModifiersAreAbsent(): void
    {
        // SQLite has no srid() modifier, but point() still records the SRID.
        $sqlite = (new SQLiteTable())->point('g', 3857);
        $this->assertSame(3857, $sqlite->srid);
        $this->assertNotContains('srid', \get_class_methods($sqlite));

        // ClickHouse has no autoIncrement() modifier, but id() still flags it.
        $clickhouse = (new ClickHouseTable())->id();
        $this->assertTrue($clickhouse->isAutoIncrement);
        $this->assertNotContains('autoIncrement', \get_class_methods($clickhouse));

        // MongoDB has no autoIncrement() modifier, but serial() still flags it.
        $mongo = (new MongoTable())->serial('s');
        $this->assertTrue($mongo->isAutoIncrement);
        $this->assertNotContains('autoIncrement', \get_class_methods($mongo));
    }

    public function testIntrinsicModifiersAreScopedToDialectsThatEmitThem(): void
    {
        $mysql = \get_class_methods((new MySQLTable())->integer('a'));
        $pg = \get_class_methods((new Table())->integer('a'));
        $sqlite = \get_class_methods((new SQLiteTable())->integer('a'));
        $clickhouse = \get_class_methods((new ClickHouseTable())->integer('a'));

        $this->assertContains('srid', $mysql);
        $this->assertContains('srid', $pg);
        $this->assertNotContains('srid', $sqlite);

        $this->assertContains('dimensions', $pg);
        $this->assertNotContains('dimensions', $mysql);
        $this->assertNotContains('dimensions', $clickhouse);

        $this->assertContains('autoIncrement', $sqlite);
        $this->assertNotContains('autoIncrement', $clickhouse);
    }

    public function testColumnModifiersAreScopedToDialectsThatEmitThem(): void
    {
        $mysql = \get_class_methods((new MySQLTable())->string('s'));
        $pg = \get_class_methods((new Table())->string('s'));

        // PostgreSQL cannot order columns, and cannot inline a comment in
        // CREATE TABLE -- commentOnColumn() is the supported path there.
        $this->assertContains('after', $mysql);
        $this->assertContains('comment', $mysql);
        $this->assertNotContains('after', $pg);
        $this->assertNotContains('comment', $pg);
    }

    public function testForeignKeyReturnsItselfForChainingFluentMethods(): void
    {
        $bp = new Table();
        $fk = $bp->foreignKey('user_id');

        $this->assertSame($fk, $fk->references('id'));
        $this->assertSame($fk, $fk->on('users'));
        $this->assertSame($fk, $fk->onDelete(ForeignKeyAction::Cascade));
        $this->assertSame($fk, $fk->onUpdate(ForeignKeyAction::SetNull));
    }

    public function testMultipleTablesFromSameSchemaCompileIndependently(): void
    {
        $schema = new MySQL();
        $a = $schema->table('a')->id();
        $b = $schema->table('b')->id();

        $this->assertNotSame($a->table, $b->table);
        $this->assertSame('a', $a->table->name);
        $this->assertSame('b', $b->table->name);
        $this->assertStringContainsString('CREATE TABLE `a`', $a->create()->query);
        $this->assertStringContainsString('CREATE TABLE `b`', $b->create()->query);
    }

    public function testEngineWithRequiredArgsValidates(): void
    {
        $bp = (new ClickHouse())->table('events');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('CollapsingMergeTree requires a sign column');
        $bp->engine(Engine::CollapsingMergeTree);
    }

    public function testReplicatedMergeTreeRequiresArgs(): void
    {
        $bp = (new ClickHouse())->table('events');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ReplicatedMergeTree requires zookeeper_path and replica_name');
        $bp->engine(Engine::ReplicatedMergeTree, '/path');
    }

    public function testColumnForwardsTruncateAndRenameTerminals(): void
    {
        $schema = new MySQL();
        $truncate = $schema->table('users')->id()->truncate();
        $rename = $schema->table('old')->id()->rename('new');

        $this->assertSame('TRUNCATE TABLE `users`', $truncate->query);
        $this->assertSame('RENAME TABLE `old` TO `new`', $rename->query);
    }

    public function testColumnForwardsDropAndDropIfExistsTerminals(): void
    {
        $schema = new MySQL();
        $drop = $schema->table('users')->string('name')->drop();
        $dropIfExists = $schema->table('users')->string('name')->dropIfExists();

        $this->assertSame('DROP TABLE `users`', $drop->query);
        $this->assertSame('DROP TABLE IF EXISTS `users`', $dropIfExists->query);
    }

    public function testForeignKeyForwardsTruncateAndRenameTerminals(): void
    {
        $schema = new MySQL();
        $truncate = $schema->table('orders')->foreignKey('user_id')->references('id')->on('users')->truncate();
        $rename = $schema->table('orders')->foreignKey('user_id')->references('id')->on('users')->rename('orders_v2');

        $this->assertSame('TRUNCATE TABLE `orders`', $truncate->query);
        $this->assertSame('RENAME TABLE `orders` TO `orders_v2`', $rename->query);
    }

    public function testAddForeignKeyIsAliasOfForeignKey(): void
    {
        $bp = new Table();
        $a = $bp->addForeignKey('user_id');
        $b = $bp->foreignKey('parent_id');

        $this->assertCount(2, $bp->foreignKeys);
        $this->assertSame($a, $bp->foreignKeys[0]);
        $this->assertSame($b, $bp->foreignKeys[1]);
    }

    public function testAddForeignKeySharesRegistryWithForeignKey(): void
    {
        // Calling both methods for the same column registers the FK twice; the
        // alias does not deduplicate. Pinning this so callers don't accidentally
        // double-register.
        $bp = new Table();
        $bp->foreignKey('user_id');
        $bp->addForeignKey('user_id');

        $this->assertCount(2, $bp->foreignKeys);
    }

    public function testTableEnumRejectsEmptyValueList(): void
    {
        $bp = new Table();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('enum() requires at least one allowed value');
        $bp->enum('status', []);
    }

    public function testColumnEnumArrayRejectsEmptyValueList(): void
    {
        $bp = new Table();
        $col = $bp->enum('status', ['draft']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('enum() requires at least one allowed value');
        $col->enum([]);
    }

    public function testColumnEnumStringDispatchRejectsMissingValueList(): void
    {
        $bp = new Table();
        $col = $bp->string('label');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('enum() requires at least one allowed value');
        $col->enum('status');
    }

    public function testDeepChainOfHeterogeneousMethodsCompiles(): void
    {
        $schema = new MySQL();
        $table = $schema->table('mixed');
        $table->id()
            ->string('a')->nullable()
            ->integer('b')->default(7)
            ->datetime('c', 6)
            ->boolean('d')
            ->json('e');
        $table->index(['a', 'b'])
            ->uniqueIndex(['a'])
            ->fulltextIndex(['e'], 'ft_e');
        $table->foreignKey('b')->references('id')->on('parents')->onDelete(ForeignKeyAction::Cascade);
        $table->check('b_positive', '`b` >= 0');
        $table->rawColumn('`raw_col` TEXT');
        $stmt = $table->create();

        $this->assertBindingCount($stmt);
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $stmt->query);
        $this->assertStringContainsString('INDEX `idx_a_b` (`a`, `b`)', $stmt->query);
        $this->assertStringContainsString('UNIQUE INDEX `uniq_a` (`a`)', $stmt->query);
        $this->assertStringContainsString('FULLTEXT INDEX `ft_e` (`e`)', $stmt->query);
        $this->assertStringContainsString('FOREIGN KEY (`b`) REFERENCES `parents` (`id`) ON DELETE CASCADE', $stmt->query);
        $this->assertStringContainsString('CONSTRAINT `b_positive` CHECK (`b` >= 0)', $stmt->query);
        $this->assertStringContainsString('`raw_col` TEXT', $stmt->query);
    }

    public function testForeignKeyForwardsToEveryTableColumnFactory(): void
    {
        // Each call must hit a fresh ForeignKey: column factories return Column,
        // so chaining a second factory off a FK would route through Column instead.
        $bp = new Table();
        $bp->integer('user_id');
        $fk = fn (): ForeignKey => $bp->foreignKey('user_id');

        $fk()->id('id_col');
        $fk()->string('s');
        $fk()->text('t');
        $fk()->mediumText('mt');
        $fk()->longText('lt');
        $fk()->integer('i');
        $fk()->bigInteger('bi');
        $fk()->serial('sr');
        $fk()->bigSerial('bsr');
        $fk()->smallSerial('ssr');
        $fk()->float('f');
        $fk()->boolean('b');
        $fk()->datetime('dt');
        $fk()->timestamp('ts');
        $fk()->json('j');
        $fk()->binary('bin');
        $enumCol = $fk()->enum('status', ['draft', 'live']);
        $fk()->point('p');
        $fk()->linestring('ls');
        $fk()->polygon('pg');
        $fk()->vector('v', 8);

        $names = \array_column($bp->columns, 'name');
        $this->assertSame(
            ['user_id', 'id_col', 's', 't', 'mt', 'lt', 'i', 'bi', 'sr', 'bsr', 'ssr', 'f', 'b', 'dt', 'ts', 'j', 'bin', 'status', 'p', 'ls', 'pg', 'v'],
            $names,
        );

        $this->assertSame(ColumnType::Enum, $enumCol->type);
        $this->assertSame(['draft', 'live'], $enumCol->enumValues);
    }

    public function testForeignKeyForwardsTimestamps(): void
    {
        $bp = new Table();
        $bp->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->timestamps(6);

        $this->assertCount(4, $bp->columns);
        $this->assertSame('created_at', $bp->columns[2]->name);
        $this->assertSame('updated_at', $bp->columns[3]->name);
        $this->assertSame(6, $bp->columns[2]->precision);
    }

    public function testForeignKeyForwardsAddColumnAndModifyColumn(): void
    {
        $bp = new Table();
        $bp->integer('user_id');
        $bp->foreignKey('user_id')->addColumn('phone', ColumnType::String, 30);
        $bp->foreignKey('user_id')->modifyColumn('email', ColumnType::String, 200);

        $this->assertCount(3, $bp->columns);
        $this->assertSame('phone', $bp->columns[1]->name);
        $this->assertFalse($bp->columns[1]->isModify);
        $this->assertSame('email', $bp->columns[2]->name);
        $this->assertTrue($bp->columns[2]->isModify);
    }

    public function testForeignKeyForwardsRenameColumnAndDropColumn(): void
    {
        $bp = new Table();
        $bp->integer('user_id');
        $bp->foreignKey('user_id')->renameColumn('old', 'new');
        $bp->foreignKey('user_id')->dropColumn('legacy');

        $this->assertSame([['from' => 'old', 'to' => 'new']], \array_map(
            fn ($r) => ['from' => $r->from, 'to' => $r->to],
            $bp->renameColumns,
        ));
        $this->assertSame(['legacy'], $bp->dropColumns);
    }

    public function testForeignKeyForwardsIndexFamily(): void
    {
        $bp = new Table();
        $bp->integer('user_id');
        $fk = fn (): ForeignKey => $bp->foreignKey('user_id');

        $fk()->index(['user_id']);
        $fk()->uniqueIndex(['user_id'], 'uq_user');
        $fk()->fulltextIndex(['user_id'], 'ft_user');
        $fk()->spatialIndex(['user_id'], 'sp_user');
        $fk()->addIndex('custom_user', ['user_id'], IndexType::Unique);

        $this->assertCount(5, $bp->indexes);
        $this->assertSame(IndexType::Index, $bp->indexes[0]->type);
        $this->assertSame(IndexType::Unique, $bp->indexes[1]->type);
        $this->assertSame(IndexType::Fulltext, $bp->indexes[2]->type);
        $this->assertSame(IndexType::Spatial, $bp->indexes[3]->type);
        $this->assertSame('custom_user', $bp->indexes[4]->name);
    }

    public function testForeignKeyForwardsDropIndex(): void
    {
        $bp = new Table();
        $bp->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->dropIndex('idx_old');

        $this->assertSame(['idx_old'], $bp->dropIndexes);
    }

    public function testForeignKeyForwardsForeignKeyFactories(): void
    {
        $bp = new Table();
        $first = $bp->id()
            ->integer('user_id')
            ->integer('parent_id')
            ->foreignKey('user_id')->references('id')->on('users');

        $second = $first->foreignKey('parent_id');
        $third = $first->addForeignKey('user_id');

        $this->assertInstanceOf(ForeignKey::class, $second);
        $this->assertInstanceOf(ForeignKey::class, $third);
        $this->assertCount(3, $bp->foreignKeys);
    }

    public function testForeignKeyForwardsDropForeignKey(): void
    {
        $bp = new Table();
        $bp->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->dropForeignKey('fk_old');

        $this->assertSame(['fk_old'], $bp->dropForeignKeys);
    }

    public function testForeignKeyForwardsPrimaryComposite(): void
    {
        $bp = new Table();
        $result = $bp->integer('a')
            ->integer('b')
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->primary(['a', 'b']);

        $this->assertSame($bp, $result);
        $this->assertSame(['a', 'b'], $bp->compositePrimaryKey);
    }

    public function testForeignKeyForwardsCheck(): void
    {
        $bp = new Table();
        $result = $bp->integer('age')
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->check('age_min', '`age` >= 18');

        $this->assertSame($bp, $result);
        $this->assertCount(1, $bp->checks);
        $this->assertSame('age_min', $bp->checks[0]->name);
        $this->assertSame('`age` >= 18', $bp->checks[0]->expression);
    }

    public function testForeignKeyForwardsRawColumnAndRawIndex(): void
    {
        $bp = new Table();
        $bp->integer('user_id');
        $bp->foreignKey('user_id')->rawColumn('`extra` JSON NOT NULL');
        $bp->foreignKey('user_id')->rawIndex('FULLTEXT INDEX `ft` (`extra`)');

        $this->assertSame(['`extra` JSON NOT NULL'], $bp->rawColumnDefs);
        $this->assertSame(['FULLTEXT INDEX `ft` (`extra`)'], $bp->rawIndexDefs);
    }

    public function testForeignKeyForwardsPartitionFamily(): void
    {
        $a = new Table();
        $a->integer('id')->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->partitionByRange('`id`');
        $this->assertNotNull($a->partitionType);

        $b = new Table();
        $b->integer('id')->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->partitionByList('`id`');
        $this->assertNotNull($b->partitionType);

        $c = new Table();
        $c->integer('id')->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->partitionByHash('`id`', 4);
        $this->assertSame(4, $c->partitionCount);
    }

    public function testForeignKeyForwardsAllTerminals(): void
    {
        $schema = new MySQL();

        $create = $schema->table('orders')
            ->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->create();
        $this->assertStringContainsString('CREATE TABLE `orders`', $create->query);
        $this->assertStringNotContainsString('IF NOT EXISTS', $create->query);

        $createIfNotExists = $schema->table('orders')
            ->id()
            ->integer('user_id')
            ->foreignKey('user_id')->references('id')->on('users')
            ->createIfNotExists();
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `orders`', $createIfNotExists->query);

        $alter = $schema->table('orders')
            ->integer('user_id')
            ->addForeignKey('user_id')->references('id')->on('users')
            ->alter();
        $this->assertStringContainsString('ALTER TABLE `orders`', $alter->query);

        $drop = $schema->table('orders')
            ->foreignKey('user_id')->references('id')->on('users')
            ->drop();
        $this->assertSame('DROP TABLE `orders`', $drop->query);

        $dropIfExists = $schema->table('orders')
            ->foreignKey('user_id')->references('id')->on('users')
            ->dropIfExists();
        $this->assertSame('DROP TABLE IF EXISTS `orders`', $dropIfExists->query);
    }
}
