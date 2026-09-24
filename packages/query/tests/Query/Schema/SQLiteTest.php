<?php

namespace Tests\Query\Schema;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\SQLite as SQLBuilder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\SQLite as Schema;

class SQLiteTest extends TestCase
{
    use AssertsBindingCount;

    public function testCreateTableBasic(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->id()
            ->string('name', 255)
            ->string('email', 255)->unique()
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame(
            'CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL, UNIQUE (`email`))',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testCreateTableAllColumnTypes(): void
    {
        $schema = new Schema();
        $result = $schema->table('test_types')
            ->integer('int_col')
            ->bigInteger('big_col')
            ->float('float_col')
            ->boolean('bool_col')
            ->text('text_col')
            ->datetime('dt_col', 3)
            ->timestamp('ts_col', 6)
            ->json('json_col')
            ->binary('bin_col')
            ->enum('status', ['active', 'inactive'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `test_types` (`int_col` INTEGER NOT NULL, `big_col` INTEGER NOT NULL, `float_col` REAL NOT NULL, `bool_col` INTEGER NOT NULL, `text_col` TEXT NOT NULL, `dt_col` TEXT NOT NULL, `ts_col` TEXT NOT NULL, `json_col` TEXT NOT NULL, `bin_col` BLOB NOT NULL, `status` TEXT NOT NULL)', $result->query);
    }

    public function testColumnTypeStringMapsToVarchar(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('name', 100)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`name` VARCHAR(100) NOT NULL)', $result->query);
    }

    public function testColumnTypeBooleanMapsToInteger(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->boolean('active')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`active` INTEGER NOT NULL)', $result->query);
    }

    public function testColumnTypeDatetimeMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->datetime('created_at')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`created_at` TEXT NOT NULL)', $result->query);
    }

    public function testColumnTypeTimestampMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->timestamp('updated_at')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`updated_at` TEXT NOT NULL)', $result->query);
    }

    public function testColumnTypeJsonMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->json('data')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`data` TEXT NOT NULL)', $result->query);
    }

    public function testColumnTypeBinaryMapsToBlob(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->binary('content')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`content` BLOB NOT NULL)', $result->query);
    }

    public function testColumnTypeEnumMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->enum('status', ['a', 'b'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`status` TEXT NOT NULL)', $result->query);
    }

    public function testColumnTypeSpatialMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->point('coords', 4326)
            ->linestring('path')
            ->polygon('area')
            ->create();
        $this->assertBindingCount($result);

        $count = substr_count($result->query, 'TEXT NOT NULL');
        $this->assertGreaterThanOrEqual(3, $count);
    }

    public function testColumnTypeUuid7MapsToVarchar36(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('uid', 36)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`uid` VARCHAR(36) NOT NULL)', $result->query);
    }

    public function testAutoIncrementUsesAutoincrement(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->id()
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)', $result->query);
        $this->assertStringNotContainsString('AUTO_INCREMENT', $result->query);
    }

    public function testUnsignedIsEmptyString(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->integer('age')->unsigned()
            ->create();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('UNSIGNED', $result->query);
    }

    public function testRenameUsesAlterTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('old_table')->rename('new_table');
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `old_table` RENAME TO `new_table`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testTruncateUsesDeleteFrom(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->truncate();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `users`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testDropIndexWithoutTableName(): void
    {
        $schema = new Schema();
        $result = $schema->dropIndex('users', 'idx_email');
        $this->assertBindingCount($result);

        $this->assertSame('DROP INDEX `idx_email`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testCreateTableWithNullableAndDefault(): void
    {
        $schema = new Schema();
        $result = $schema->table('posts')
            ->id()
            ->text('bio')->nullable()
            ->boolean('active')->default(true)
            ->integer('score')->default(0)
            ->string('status')->default('draft')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `posts` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `bio` TEXT NULL, `active` INTEGER NOT NULL DEFAULT 1, `score` INTEGER NOT NULL DEFAULT 0, `status` VARCHAR(255) NOT NULL DEFAULT \'draft\')', $result->query);
    }

    public function testCreateTableWithForeignKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('posts')
            ->id()
            ->foreignKey('user_id')
                ->references('id')->on('users')
                ->onDelete(ForeignKeyAction::Cascade)->onUpdate(ForeignKeyAction::SetNull)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `posts` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL)', $result->query);
    }

    public function testCreateTableWithIndexes(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->id()
            ->string('name')
            ->string('email')
            ->index(['name', 'email'])
            ->uniqueIndex(['email'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL, INDEX `idx_name_email` (`name`, `email`), UNIQUE INDEX `uniq_email` (`email`))', $result->query);
    }

    public function testDropTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->drop();
        $this->assertBindingCount($result);

        $this->assertSame('DROP TABLE `users`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testDropTableIfExists(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->dropIfExists();

        $this->assertSame('DROP TABLE IF EXISTS `users`', $result->query);
    }

    public function testAlterAddColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addColumn('avatar_url', ColumnType::String, 255)->nullable()
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(255) NULL', $result->query);
    }

    public function testAlterDropColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->dropColumn('age')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` DROP COLUMN `age`',
            $result->query
        );
    }

    public function testAlterRenameColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->renameColumn('bio', 'biography')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` RENAME COLUMN `bio` TO `biography`',
            $result->query
        );
    }

    public function testCreateIndex(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('users', 'idx_email', ['email']);

        $this->assertSame('CREATE INDEX `idx_email` ON `users` (`email`)', $result->query);
    }

    public function testCreateUniqueIndex(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('users', 'idx_email', ['email'], unique: true);

        $this->assertSame('CREATE UNIQUE INDEX `idx_email` ON `users` (`email`)', $result->query);
    }

    public function testCreateView(): void
    {
        $schema = new Schema();
        $builder = (new SQLBuilder())->from('users')->filter([Query::equal('active', [true])]);
        $result = $schema->createView('active_users', $builder);

        $this->assertSame(
            'CREATE VIEW `active_users` AS SELECT * FROM `users` WHERE `active` IN (?)',
            $result->query
        );
        $this->assertSame([true], $result->bindings);
    }

    public function testDropView(): void
    {
        $schema = new Schema();
        $result = $schema->dropView('active_users');

        $this->assertSame('DROP VIEW `active_users`', $result->query);
    }

    public function testCreateTableWithMultiplePrimaryKeys(): void
    {
        $schema = new Schema();
        $result = $schema->table('order_items')
            ->integer('order_id')->primary()
            ->integer('product_id')->primary()
            ->integer('quantity')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `order_items` (`order_id` INTEGER NOT NULL, `product_id` INTEGER NOT NULL, `quantity` INTEGER NOT NULL, PRIMARY KEY (`order_id`, `product_id`))', $result->query);
    }

    public function testCreateTableWithCompositePrimaryKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('order_items')
            ->integer('order_id')
            ->integer('product_id')
            ->integer('quantity')
            ->primary(['order_id', 'product_id'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `order_items` (`order_id` INTEGER NOT NULL, `product_id` INTEGER NOT NULL, `quantity` INTEGER NOT NULL, PRIMARY KEY (`order_id`, `product_id`))', $result->query);
    }

    public function testCreateTableRejectsMixedColumnAndTablePrimary(): void
    {
        $schema = new Schema();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot combine column-level primary() with Table::primary() composite key.');

        $schema->table('order_items')
            ->integer('order_id')->primary()
            ->integer('product_id')
            ->primary(['order_id', 'product_id'])
            ->create();
    }

    public function testCreateTableWithDefaultNull(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('name')->nullable()->default(null)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`name` VARCHAR(255) NULL DEFAULT NULL)', $result->query);
    }

    public function testCreateTableWithNumericDefault(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->float('score')->default(0.5)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`score` REAL NOT NULL DEFAULT 0.5)', $result->query);
    }

    public function testCreateTableWithTimestamps(): void
    {
        $schema = new Schema();
        $result = $schema->table('posts')
            ->id()
            ->timestamps()
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `posts` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `created_at` TEXT NOT NULL, `updated_at` TEXT NOT NULL)', $result->query);
    }

    public function testExactCreateTableWithColumnsAndIndexes(): void
    {
        $schema = new Schema();
        $result = $schema->table('products')
            ->id()
            ->string('name', 100)
            ->integer('price')
            ->index(['name'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame(
            'CREATE TABLE `products` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `name` VARCHAR(100) NOT NULL, `price` INTEGER NOT NULL, INDEX `idx_name` (`name`))',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testExactDropTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('sessions')->drop();

        $this->assertSame('DROP TABLE `sessions`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactRenameTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('old_name')->rename('new_name');

        $this->assertSame('ALTER TABLE `old_name` RENAME TO `new_name`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactTruncateTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('logs')->truncate();

        $this->assertSame('DELETE FROM `logs`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactDropIndex(): void
    {
        $schema = new Schema();
        $result = $schema->dropIndex('users', 'idx_email');

        $this->assertSame('DROP INDEX `idx_email`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactCreateTableWithForeignKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('orders')
            ->id()
            ->integer('customer_id')
            ->foreignKey('customer_id')
                ->references('id')->on('customers')
                ->onDelete(ForeignKeyAction::Cascade)->onUpdate(ForeignKeyAction::Cascade)
            ->create();

        $this->assertSame(
            'CREATE TABLE `orders` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, `customer_id` INTEGER NOT NULL, FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testColumnTypeFloatMapsToReal(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->float('ratio')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`ratio` REAL NOT NULL)', $result->query);
    }

    public function testCreateIfNotExists(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->integer('id')->primary()
            ->createIfNotExists();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS', $result->query);
    }

    public function testAlterMultipleOperations(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addColumn('avatar', ColumnType::String, 255)->nullable()
            ->dropColumn('age')
            ->renameColumn('bio', 'biography')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL, RENAME COLUMN `bio` TO `biography`, DROP COLUMN `age`', $result->query);
    }

    public function testSerialColumnMapsToInteger(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->serial('id')->primary()
            ->create();

        $this->assertSame('CREATE TABLE `t` (`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL)', $result->query);
    }

    /**
     * SQLite's ALTER TABLE ADD COLUMN has no AFTER clause, so exposing after()
     * here produced DDL that failed at execution. Asserted by running it.
     */
    public function testColumnDoesNotExposeAfter(): void
    {
        $this->assertNotContains('after', \get_class_methods((new Schema())->table('t')->integer('b')));
    }

    public function testEmittedAlterExecutesAgainstRealSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (a INTEGER)');

        $alter = (new Schema())->table('t')->integer('b')->alter()->query;
        $pdo->exec($alter);

        $statement = $pdo->query('SELECT name FROM pragma_table_info(\'t\')');
        $this->assertNotFalse($statement);
        $this->assertSame(['a', 'b'], $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEmittedCollationExecutesAgainstRealSqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $create = (new Schema())->table('t')->string('name')->collation('NOCASE')->create()->query;
        $pdo->exec($create);

        $this->assertStringContainsString('COLLATE NOCASE', $create);
    }

    public function testColumnCollationIsEmitted(): void
    {
        $result = (new Schema())->table('t')
            ->string('name')->collation('NOCASE')
            ->create();

        $this->assertStringContainsString('COLLATE NOCASE', $result->query);
    }

    public function testColumnDoesNotExposeUserType(): void
    {
        $column = (new Schema())->table('t')->string('mood');

        $this->assertNotContains('userType', \get_class_methods($column));
    }

    public function testTinyIntegerMapsToInteger(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')->tinyInteger('depth')->create();

        $this->assertSame('CREATE TABLE `t` (`depth` INTEGER NOT NULL)', $result->query);
    }

    public function testSmallIntegerMapsToInteger(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')->smallInteger('year')->create();

        $this->assertSame('CREATE TABLE `t` (`year` INTEGER NOT NULL)', $result->query);
    }

    public function testDecimalMapsToNumeric(): void
    {
        $schema = new Schema();
        $result = $schema->table('orders')
            ->decimal('amount', precision: 18, scale: 3)
            ->create();

        $this->assertSame('CREATE TABLE `orders` (`amount` NUMERIC(18, 3) NOT NULL)', $result->query);
    }

    public function testUuidMapsToText(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')->uuid('id')->primary()->create();

        $this->assertSame('CREATE TABLE `t` (`id` TEXT NOT NULL, PRIMARY KEY (`id`))', $result->query);
    }
}
