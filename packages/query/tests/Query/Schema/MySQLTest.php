<?php

namespace Tests\Query\Schema;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MySQL as SQLBuilder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;
use Utopia\Query\Schema\Column;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\Feature\CreatePartition;
use Utopia\Query\Schema\Feature\DropPartition;
use Utopia\Query\Schema\Feature\ForeignKeys;
use Utopia\Query\Schema\Feature\Procedures;
use Utopia\Query\Schema\Feature\TableComments;
use Utopia\Query\Schema\Feature\Triggers;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\Index;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\MySQL as Schema;
use Utopia\Query\Schema\ParameterDirection;
use Utopia\Query\Schema\TriggerEvent;
use Utopia\Query\Schema\TriggerTiming;

class MySQLTest extends TestCase
{
    use AssertsBindingCount;
    // Feature interfaces

    public function testImplementsForeignKeys(): void
    {
        $this->assertInstanceOf(ForeignKeys::class, new Schema());
    }

    public function testImplementsProcedures(): void
    {
        $this->assertInstanceOf(Procedures::class, new Schema());
    }

    public function testImplementsTriggers(): void
    {
        $this->assertInstanceOf(Triggers::class, new Schema());
    }

    // CREATE TABLE

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
            'CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`), UNIQUE (`email`))',
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

        $this->assertSame('CREATE TABLE `test_types` (`int_col` INT NOT NULL, `big_col` BIGINT NOT NULL, `float_col` DOUBLE NOT NULL, `bool_col` TINYINT(1) NOT NULL, `text_col` TEXT NOT NULL, `dt_col` DATETIME(3) NOT NULL, `ts_col` TIMESTAMP(6) NOT NULL, `json_col` JSON NOT NULL, `bin_col` BLOB NOT NULL, `status` ENUM(\'active\',\'inactive\') NOT NULL)', $result->query);
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

        $this->assertSame('CREATE TABLE `posts` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `bio` TEXT NULL, `active` TINYINT(1) NOT NULL DEFAULT 1, `score` INT NOT NULL DEFAULT 0, `status` VARCHAR(255) NOT NULL DEFAULT \'draft\', PRIMARY KEY (`id`))', $result->query);
    }

    public function testCreateTableWithUnsigned(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->integer('age')->unsigned()
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`age` INT UNSIGNED NOT NULL)', $result->query);
    }

    public function testCreateTableWithTimestamps(): void
    {
        $schema = new Schema();
        $result = $schema->table('posts')
            ->id()
            ->timestamps()
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `posts` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NOT NULL, PRIMARY KEY (`id`))', $result->query);
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

        $this->assertSame('CREATE TABLE `posts` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY (`id`), FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL)', $result->query);
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

        $this->assertSame('CREATE TABLE `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`), INDEX `idx_name_email` (`name`, `email`), UNIQUE INDEX `uniq_email` (`email`))', $result->query);
    }

    public function testCreateTableWithSpatialTypes(): void
    {
        $schema = new Schema();
        $result = $schema->table('locations')
            ->id()
            ->point('coords', 4326)
            ->linestring('path')
            ->polygon('area')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `locations` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `coords` POINT SRID 4326 NOT NULL, `path` LINESTRING SRID 4326 NOT NULL, `area` POLYGON SRID 4326 NOT NULL, PRIMARY KEY (`id`))', $result->query);
    }

    public function testCreateTableWithComment(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('name')->comment('User display name')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`name` VARCHAR(255) NOT NULL COMMENT \'User display name\')', $result->query);
    }
    // ALTER TABLE

    public function testAlterAddColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addColumn('avatar_url', ColumnType::String, 255)->nullable()->after('email')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` ADD COLUMN `avatar_url` VARCHAR(255) NULL AFTER `email`',
            $result->query
        );
    }

    public function testAlterModifyColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->modifyColumn('name', ColumnType::String, 500)
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `name` VARCHAR(500) NOT NULL',
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

    public function testAlterAddIndex(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addIndex('idx_name', ['name'])
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` ADD INDEX `idx_name` (`name`)',
            $result->query
        );
    }

    public function testAlterDropIndex(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->dropIndex('idx_old')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` DROP INDEX `idx_old`',
            $result->query
        );
    }

    public function testAlterAddForeignKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addForeignKey('dept_id')
                ->references('id')->on('departments')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `users` ADD FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`)', $result->query);
    }

    public function testAlterDropForeignKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->dropForeignKey('fk_old')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` DROP FOREIGN KEY `fk_old`',
            $result->query
        );
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
    // DROP TABLE

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
    // RENAME TABLE

    public function testRenameTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->rename('members');
        $this->assertBindingCount($result);

        $this->assertSame('RENAME TABLE `users` TO `members`', $result->query);
    }
    // TRUNCATE TABLE

    public function testTruncateTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->truncate();
        $this->assertBindingCount($result);

        $this->assertSame('TRUNCATE TABLE `users`', $result->query);
    }
    // CREATE / DROP INDEX (standalone)

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

    public function testCreateFulltextIndex(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('posts', 'idx_body_ft', ['body'], type: 'fulltext');

        $this->assertSame('CREATE FULLTEXT INDEX `idx_body_ft` ON `posts` (`body`)', $result->query);
    }

    public function testCreateSpatialIndex(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('locations', 'idx_geo', ['coords'], type: 'spatial');

        $this->assertSame('CREATE SPATIAL INDEX `idx_geo` ON `locations` (`coords`)', $result->query);
    }

    public function testDropIndex(): void
    {
        $schema = new Schema();
        $result = $schema->dropIndex('users', 'idx_email');

        $this->assertSame('DROP INDEX `idx_email` ON `users`', $result->query);
    }
    // CREATE / DROP VIEW

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

    public function testCreateOrReplaceView(): void
    {
        $schema = new Schema();
        $builder = (new SQLBuilder())->from('users')->filter([Query::equal('active', [true])]);
        $result = $schema->createOrReplaceView('active_users', $builder);

        $this->assertSame(
            'CREATE OR REPLACE VIEW `active_users` AS SELECT * FROM `users` WHERE `active` IN (?)',
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
    // FOREIGN KEY (standalone)

    public function testAddForeignKeyStandalone(): void
    {
        $schema = new Schema();
        $result = $schema->addForeignKey(
            'orders',
            'fk_user',
            'user_id',
            'users',
            'id',
            onDelete: ForeignKeyAction::Cascade,
            onUpdate: ForeignKeyAction::SetNull
        );

        $this->assertSame(
            'ALTER TABLE `orders` ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL',
            $result->query
        );
    }

    public function testAddForeignKeyNoActions(): void
    {
        $schema = new Schema();
        $result = $schema->addForeignKey('orders', 'fk_user', 'user_id', 'users', 'id');

        $this->assertSame(
            'ALTER TABLE `orders` ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)',
            $result->query
        );
    }

    public function testDropForeignKeyStandalone(): void
    {
        $schema = new Schema();
        $result = $schema->dropForeignKey('orders', 'fk_user');

        $this->assertSame(
            'ALTER TABLE `orders` DROP FOREIGN KEY `fk_user`',
            $result->query
        );
    }
    // STORED PROCEDURE

    public function testCreateProcedure(): void
    {
        $schema = new Schema();
        $result = $schema->createProcedure(
            'update_stats',
            params: [[ParameterDirection::In, 'user_id', 'INT'], [ParameterDirection::Out, 'total', 'INT']],
            body: 'SELECT COUNT(*) INTO total FROM orders WHERE orders.user_id = user_id;'
        );

        $this->assertSame(
            'CREATE PROCEDURE `update_stats`(IN `user_id` INT, OUT `total` INT) BEGIN SELECT COUNT(*) INTO total FROM orders WHERE orders.user_id = user_id; END',
            $result->query
        );
    }

    public function testDropProcedure(): void
    {
        $schema = new Schema();
        $result = $schema->dropProcedure('update_stats');

        $this->assertSame('DROP PROCEDURE `update_stats`', $result->query);
    }
    // TRIGGER

    public function testCreateTrigger(): void
    {
        $schema = new Schema();
        $result = $schema->createTrigger(
            'trg_updated_at',
            'users',
            timing: TriggerTiming::Before,
            event: TriggerEvent::Update,
            body: 'SET NEW.updated_at = NOW(3);'
        );

        $this->assertSame(
            'CREATE TRIGGER `trg_updated_at` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN SET NEW.updated_at = NOW(3); END',
            $result->query
        );
    }

    public function testDropTrigger(): void
    {
        $schema = new Schema();
        $result = $schema->dropTrigger('trg_updated_at');

        $this->assertSame('DROP TRIGGER `trg_updated_at`', $result->query);
    }

    // Schema edge cases

    public function testCreateTableWithMultiplePrimaryKeys(): void
    {
        $schema = new Schema();
        $result = $schema->table('order_items')
            ->integer('order_id')->primary()
            ->integer('product_id')->primary()
            ->integer('quantity')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `order_items` (`order_id` INT NOT NULL, `product_id` INT NOT NULL, `quantity` INT NOT NULL, PRIMARY KEY (`order_id`, `product_id`))', $result->query);
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

        $this->assertSame('CREATE TABLE `order_items` (`order_id` INT NOT NULL, `product_id` INT NOT NULL, `quantity` INT NOT NULL, PRIMARY KEY (`order_id`, `product_id`))', $result->query);
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

        $this->assertSame('CREATE TABLE `t` (`score` DOUBLE NOT NULL DEFAULT 0.5)', $result->query);
    }

    public function testDropIfExists(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')->dropIfExists();

        $this->assertSame('DROP TABLE IF EXISTS `users`', $result->query);
    }

    public function testCreateOrReplaceViewFromBuilder(): void
    {
        $schema = new Schema();
        $builder = (new SQLBuilder())->from('users');
        $result = $schema->createOrReplaceView('all_users', $builder);

        $this->assertStringStartsWith('CREATE OR REPLACE VIEW', $result->query);
    }

    public function testAlterMultipleColumnsAndIndexes(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addColumn('first_name', ColumnType::String, 100)
            ->addColumn('last_name', ColumnType::String, 100)
            ->dropColumn('name')
            ->addIndex('idx_names', ['first_name', 'last_name'])
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(100) NOT NULL, ADD COLUMN `last_name` VARCHAR(100) NOT NULL, DROP COLUMN `name`, ADD INDEX `idx_names` (`first_name`, `last_name`)', $result->query);
    }

    public function testCreateTableForeignKeyWithAllActions(): void
    {
        $schema = new Schema();
        $result = $schema->table('comments')
            ->id()
            ->foreignKey('post_id')
                ->references('id')->on('posts')
                ->onDelete(ForeignKeyAction::Cascade)->onUpdate(ForeignKeyAction::Restrict)
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `comments` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY (`id`), FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT)', $result->query);
    }

    public function testAddForeignKeyStandaloneNoActions(): void
    {
        $schema = new Schema();
        $result = $schema->addForeignKey('orders', 'fk_user', 'user_id', 'users', 'id');

        $this->assertStringNotContainsString('ON DELETE', $result->query);
        $this->assertStringNotContainsString('ON UPDATE', $result->query);
    }

    public function testDropTriggerByName(): void
    {
        $schema = new Schema();
        $result = $schema->dropTrigger('trg_old');

        $this->assertSame('DROP TRIGGER `trg_old`', $result->query);
    }

    public function testCreateTableTimestampWithoutPrecision(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->timestamp('ts_col')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`ts_col` TIMESTAMP NOT NULL)', $result->query);
        $this->assertStringNotContainsString('TIMESTAMP(', $result->query);
    }

    public function testCreateTableDatetimeWithoutPrecision(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->datetime('dt_col')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`dt_col` DATETIME NOT NULL)', $result->query);
        $this->assertStringNotContainsString('DATETIME(', $result->query);
    }

    public function testCreateCompositeIndex(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('users', 'idx_multi', ['first_name', 'last_name']);

        $this->assertSame('CREATE INDEX `idx_multi` ON `users` (`first_name`, `last_name`)', $result->query);
    }

    public function testAlterAddAndDropForeignKey(): void
    {
        $schema = new Schema();
        $result = $schema->table('orders')
            ->addForeignKey('user_id')->references('id')->on('users')
            ->dropForeignKey('fk_old_user')
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `orders` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`), DROP FOREIGN KEY `fk_old_user`', $result->query);
    }

    public function testTableAutoGeneratedIndexName(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('first')
            ->string('last')
            ->index(['first', 'last'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`first` VARCHAR(255) NOT NULL, `last` VARCHAR(255) NOT NULL, INDEX `idx_first_last` (`first`, `last`))', $result->query);
    }

    public function testTableAutoGeneratedUniqueIndexName(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->string('email')
            ->uniqueIndex(['email'])
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`email` VARCHAR(255) NOT NULL, UNIQUE INDEX `uniq_email` (`email`))', $result->query);
    }

    public function testExactCreateTableWithColumnsAndIndexes(): void
    {
        $schema = new Schema();
        $result = $schema->table('products')
            ->id()
            ->string('name', 100)
            ->integer('price')
            ->boolean('active')->default(true)
            ->index(['name'])
            ->uniqueIndex(['price'])
            ->create();

        $this->assertSame(
            'CREATE TABLE `products` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `name` VARCHAR(100) NOT NULL, `price` INT NOT NULL, `active` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`), INDEX `idx_name` (`name`), UNIQUE INDEX `uniq_price` (`price`))',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAlterTableAddAndDropColumns(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addColumn('phone', ColumnType::String, 20)->nullable()
            ->dropColumn('legacy_field')
            ->alter();

        $this->assertSame(
            'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL, DROP COLUMN `legacy_field`',
            $result->query
        );
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
            'CREATE TABLE `orders` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `customer_id` INT NOT NULL, PRIMARY KEY (`id`), FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactDropTable(): void
    {
        $schema = new Schema();
        $result = $schema->table('sessions')->drop();

        $this->assertSame('DROP TABLE `sessions`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testImplementsTableComments(): void
    {
        $this->assertInstanceOf(TableComments::class, new Schema());
    }

    public function testImplementsCreatePartition(): void
    {
        $this->assertInstanceOf(CreatePartition::class, new Schema());
    }

    public function testImplementsDropPartition(): void
    {
        $this->assertInstanceOf(DropPartition::class, new Schema());
    }

    public function testCreateDatabase(): void
    {
        $schema = new Schema();
        $result = $schema->createDatabase('myapp');
        $this->assertBindingCount($result);

        $this->assertSame(
            'CREATE DATABASE `myapp` /*!40100 DEFAULT CHARACTER SET utf8mb4 */',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testChangeColumn(): void
    {
        $schema = new Schema();
        $result = $schema->changeColumn('users', 'name', 'full_name', 'VARCHAR(500)');
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` CHANGE COLUMN `name` `full_name` VARCHAR(500)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testModifyColumn(): void
    {
        $schema = new Schema();
        $result = $schema->modifyColumn('users', 'email', 'TEXT');
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` MODIFY `email` TEXT',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testCommentOnTable(): void
    {
        $schema = new Schema();
        $result = $schema->commentOnTable('users', 'Main user table');
        $this->assertBindingCount($result);

        $this->assertSame(
            "ALTER TABLE `users` COMMENT = 'Main user table'",
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testCommentOnTableEscapesSingleQuotes(): void
    {
        $schema = new Schema();
        $result = $schema->commentOnTable('users', "User's table");
        $this->assertBindingCount($result);

        $this->assertSame(
            "ALTER TABLE `users` COMMENT = 'User''s table'",
            $result->query
        );
    }

    public function testCreatePartition(): void
    {
        $schema = new Schema();
        $result = $schema->createPartition('events', 'p2024', "VALUES LESS THAN ('2025-01-01')");
        $this->assertBindingCount($result);

        $this->assertSame(
            "ALTER TABLE `events` ADD PARTITION (PARTITION `p2024` VALUES LESS THAN ('2025-01-01'))",
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testDropPartition(): void
    {
        $schema = new Schema();
        $result = $schema->dropPartition('events', 'p2023');
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `events` DROP PARTITION `p2023`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
    }

    public function testCreateIfNotExists(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->id()
            ->string('name')
            ->createIfNotExists();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE IF NOT EXISTS `users` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`))', $result->query);
    }

    public function testCreateTableWithRawColumnDefs(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->id()
            ->rawColumn('`custom_col` VARCHAR(255) NOT NULL DEFAULT ""')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `custom_col` VARCHAR(255) NOT NULL DEFAULT "", PRIMARY KEY (`id`))', $result->query);
    }

    public function testCreateTableWithRawIndexDefs(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->id()
            ->string('name')
            ->rawIndex('INDEX `idx_custom` (`name`(10))')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `name` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`), INDEX `idx_custom` (`name`(10)))', $result->query);
    }

    public function testCreateTableWithPartitionByRange(): void
    {
        $schema = new Schema();
        $result = $schema->table('events')
            ->id()
            ->datetime('created_at')
            ->partitionByRange('YEAR(created_at)')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `events` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) PARTITION BY RANGE(YEAR(created_at))', $result->query);
    }

    public function testCreateTableWithPartitionByList(): void
    {
        $schema = new Schema();
        $result = $schema->table('events')
            ->id()
            ->string('region')
            ->partitionByList('region')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `events` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, `region` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`)) PARTITION BY LIST(region)', $result->query);
    }

    public function testCreateTableWithPartitionByHash(): void
    {
        $schema = new Schema();
        $result = $schema->table('events')
            ->id()
            ->partitionByHash('id')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `events` (`id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, PRIMARY KEY (`id`)) PARTITION BY HASH(id)', $result->query);
    }

    public function testAlterWithForeignKeyOnDeleteAndUpdate(): void
    {
        $schema = new Schema();
        $result = $schema->table('orders')
            ->addForeignKey('user_id')
                ->references('id')->on('users')
                ->onDelete(ForeignKeyAction::Cascade)
                ->onUpdate(ForeignKeyAction::SetNull)
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `orders` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE SET NULL', $result->query);
    }

    public function testCreateIndexWithMethod(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex('users', 'idx_email', ['email'], method: 'btree');
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_email` ON `users` USING BTREE (`email`)', $result->query);
    }

    public function testCompileIndexColumnsWithCollation(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex(
            'users',
            'idx_name',
            ['name'],
            collations: ['name' => 'utf8mb4_bin']
        );
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_name` ON `users` (`name` COLLATE utf8mb4_bin)', $result->query);
    }

    public function testCompileIndexColumnsWithLength(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex(
            'users',
            'idx_name',
            ['name'],
            lengths: ['name' => 10]
        );
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_name` ON `users` (`name`(10))', $result->query);
    }

    public function testCompileIndexColumnsWithOrder(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex(
            'users',
            'idx_name',
            ['name'],
            orders: ['name' => 'desc']
        );
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_name` ON `users` (`name` DESC)', $result->query);
    }

    public function testCompileIndexColumnsWithOperatorClass(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex(
            'docs',
            'idx_content',
            ['content'],
            operatorClass: 'gin_trgm_ops'
        );
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_content` ON `docs` (`content` gin_trgm_ops)', $result->query);
    }

    public function testCompileIndexColumnsWithRawColumns(): void
    {
        $schema = new Schema();
        $result = $schema->createIndex(
            'docs',
            'idx_mixed',
            ['id'],
            rawColumns: ['CAST(data AS CHAR(100))']
        );
        $this->assertBindingCount($result);

        $this->assertSame('CREATE INDEX `idx_mixed` ON `docs` (`id`, CAST(data AS CHAR(100)))', $result->query);
    }

    public function testRenameIndexSql(): void
    {
        $schema = new Schema();
        $result = $schema->renameIndex('users', 'idx_old', 'idx_new');
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `users` RENAME INDEX `idx_old` TO `idx_new`',
            $result->query
        );
    }

    public function testDropDatabase(): void
    {
        $schema = new Schema();
        $result = $schema->dropDatabase('mydb');
        $this->assertBindingCount($result);

        $this->assertSame('DROP DATABASE `mydb`', $result->query);
    }

    public function testAnalyzeTable(): void
    {
        $schema = new Schema();
        $result = $schema->analyzeTable('users');
        $this->assertBindingCount($result);

        $this->assertSame('ANALYZE TABLE `users`', $result->query);
    }

    public function testTableJsonColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->json('metadata')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`metadata` JSON NOT NULL)', $result->query);
    }

    public function testTableBinaryColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->binary('data')
            ->create();
        $this->assertBindingCount($result);

        $this->assertSame('CREATE TABLE `t` (`data` BLOB NOT NULL)', $result->query);
    }

    public function testColumnCollationIsEmitted(): void
    {
        $result = (new Schema())->table('t')
            ->string('name')->collation('utf8mb4_unicode_ci')
            ->create();

        $this->assertSame(
            'CREATE TABLE `t` (`name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL)',
            $result->query
        );
    }

    public function testColumnPrecision(): void
    {
        $bp = (new Schema())->table('t');
        $col = new Column($bp, 'amount', ColumnType::Float, precision: 10);

        $this->assertSame(10, $col->precision);
        $this->assertNull($col->length);
    }

    public function testTableAddIndexWithEnumType(): void
    {
        $schema = new Schema();
        $result = $schema->table('users')
            ->addIndex('idx_name', ['name'], IndexType::Unique)
            ->alter();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `users` ADD UNIQUE INDEX `idx_name` (`name`)', $result->query);
    }

    public function testIndexValidationInvalidMethod(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid index method');

        new Index('idx', ['col'], method: 'DROP TABLE;');
    }

    public function testIndexValidationInvalidOperatorClass(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid operator class');

        new Index('idx', ['col'], operatorClass: 'DROP;');
    }

    public function testIndexValidationInvalidCollation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid collation');

        new Index('idx', ['col'], collations: ['col' => 'DROP;']);
    }

    public function testEnumBackslashEscaping(): void
    {
        $schema = new Schema();
        $result = $schema->table('items')
            // Input: `a\` and `b'c`. Expect backslash doubled and quote doubled.
            ->enum('status', ['a\\', "b'c"])
            ->create();

        // Expect literal sequence: ENUM('a\\','b''c')  (a + two backslashes)
        $this->assertSame("CREATE TABLE `items` (`status` ENUM('a\\\\','b''c') NOT NULL)", $result->query);
    }

    public function testDefaultValueBackslashEscaping(): void
    {
        $schema = new Schema();
        $result = $schema->table('items')
            // Input: a\' OR 1=1 -- . Expect backslash doubled, quote doubled.
            ->string('name')->default("a\\' OR 1=1 --")
            ->create();

        $this->assertSame("CREATE TABLE `items` (`name` VARCHAR(255) NOT NULL DEFAULT 'a\\\\'' OR 1=1 --')", $result->query);
    }

    public function testCommentBackslashEscaping(): void
    {
        $schema = new Schema();
        $result = $schema->table('items')
            ->string('name')->comment('trailing\\')
            ->create();

        $this->assertSame("CREATE TABLE `items` (`name` VARCHAR(255) NOT NULL COMMENT 'trailing\\\\')", $result->query);
    }

    public function testTableCommentBackslashEscaping(): void
    {
        $schema = new Schema();
        $result = $schema->commentOnTable('items', 'trailing\\');

        $this->assertSame("ALTER TABLE `items` COMMENT = 'trailing\\\\'", $result->query);
    }

    public function testSerialColumnMapsToIntWithAutoIncrement(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->serial('id')->primary()
            ->create();

        $this->assertSame('CREATE TABLE `t` (`id` INT AUTO_INCREMENT NOT NULL, PRIMARY KEY (`id`))', $result->query);
    }

    public function testBigSerialColumnMapsToBigIntWithAutoIncrement(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->bigSerial('id')->primary()
            ->create();

        $this->assertSame('CREATE TABLE `t` (`id` BIGINT AUTO_INCREMENT NOT NULL, PRIMARY KEY (`id`))', $result->query);
    }

    public function testColumnDoesNotExposeUserType(): void
    {
        $column = (new Schema())->table('t')->string('mood');

        $this->assertNotContains('userType', \get_class_methods($column));
    }

    public function testTinyIntegerColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->tinyInteger('flag')
            ->tinyInteger('depth')->unsigned()
            ->create();

        $this->assertSame(
            'CREATE TABLE `t` (`flag` TINYINT NOT NULL, `depth` TINYINT UNSIGNED NOT NULL)',
            $result->query,
        );
    }

    public function testSmallIntegerColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->smallInteger('year')
            ->smallInteger('count')->unsigned()
            ->create();

        $this->assertSame(
            'CREATE TABLE `t` (`year` SMALLINT NOT NULL, `count` SMALLINT UNSIGNED NOT NULL)',
            $result->query,
        );
    }

    public function testDecimalColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('orders')
            ->decimal('amount', precision: 18, scale: 3)
            ->decimal('rate', precision: 5, scale: 4)->nullable()
            ->create();

        $this->assertSame(
            'CREATE TABLE `orders` (`amount` DECIMAL(18, 3) NOT NULL, `rate` DECIMAL(5, 4) NULL)',
            $result->query,
        );
    }

    public function testDecimalRejectsNegativeScale(): void
    {
        $this->expectException(ValidationException::class);

        $schema = new Schema();
        $schema->table('t')->decimal('bad', precision: 10, scale: -1);
    }

    public function testDecimalRejectsScaleGreaterThanPrecision(): void
    {
        $this->expectException(ValidationException::class);

        $schema = new Schema();
        $schema->table('t')->decimal('bad', precision: 5, scale: 6);
    }

    public function testUuidColumn(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->uuid('id')->primary()
            ->create();

        $this->assertSame(
            'CREATE TABLE `t` (`id` CHAR(36) NOT NULL, PRIMARY KEY (`id`))',
            $result->query,
        );
    }

    public function testUuidColumnWithDefaultRaw(): void
    {
        $schema = new Schema();
        $result = $schema->table('t')
            ->uuid('id')->defaultRaw('UUID()')->primary()
            ->create();

        $this->assertSame(
            'CREATE TABLE `t` (`id` CHAR(36) NOT NULL DEFAULT UUID(), PRIMARY KEY (`id`))',
            $result->query,
        );
    }
}
