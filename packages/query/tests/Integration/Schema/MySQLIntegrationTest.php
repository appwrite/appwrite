<?php

namespace Tests\Integration\Schema;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\MySQL;

class MySQLIntegrationTest extends IntegrationTestCase
{
    private MySQL $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new MySQL();
    }

    public function testCreateTableWithBasicColumns(): void
    {
        $table = 'test_basic_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->integer('age')
            ->string('name', 100)
            ->boolean('active')
            ->create();

        $this->mysqlStatement($result->query);

        $columns = $this->fetchMysqlColumns($table);
        $columnNames = array_column($columns, 'COLUMN_NAME');

        $this->assertContains('age', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('active', $columnNames);

        $nameCol = $this->findColumn($columns, 'name');
        $this->assertSame('varchar', $nameCol['DATA_TYPE']);
        $this->assertSame('100', (string) $nameCol['CHARACTER_MAXIMUM_LENGTH']); // @phpstan-ignore cast.string
    }

    public function testColumnCollationIsAppliedByTheServer(): void
    {
        $table = 'test_collation_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->string('name', 100)->collation('utf8mb4_bin')
            ->create();

        $this->mysqlStatement($result->query);

        $nameCol = $this->findColumn($this->fetchMysqlColumns($table), 'name');
        $this->assertSame('utf8mb4_bin', $nameCol['COLLATION_NAME']);
    }

    public function testCreateTableWithPrimaryKeyAndUnique(): void
    {
        $table = 'test_pk_uniq_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('email', 255)->unique()
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ?"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $idRow = $this->findColumn($rows, 'id');
        $this->assertSame('PRI', $idRow['COLUMN_KEY']);

        $emailRow = $this->findColumn($rows, 'email');
        $this->assertSame('UNI', $emailRow['COLUMN_KEY']);
    }

    public function testCreateTableWithAutoIncrement(): void
    {
        $table = 'test_autoinc_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->id()
            ->string('label', 50)
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT EXTRA FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND COLUMN_NAME = 'id'"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertStringContainsString('auto_increment', (string) $row['EXTRA']); // @phpstan-ignore cast.string
    }

    public function testAlterTableAddColumn(): void
    {
        $table = 'test_alter_add_' . uniqid();
        $this->trackMysqlTable($table);

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->create();
        $this->mysqlStatement($create->query);

        $alter = $this->schema->table($table)
            ->addColumn('description', ColumnType::Text)
            ->alter();
        $this->mysqlStatement($alter->query);

        $columns = $this->fetchMysqlColumns($table);
        $columnNames = array_column($columns, 'COLUMN_NAME');

        $this->assertContains('description', $columnNames);
    }

    public function testAlterTableDropColumn(): void
    {
        $table = 'test_alter_drop_' . uniqid();
        $this->trackMysqlTable($table);

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('temp', 100)
            ->create();
        $this->mysqlStatement($create->query);

        $alter = $this->schema->table($table)
            ->dropColumn('temp')
            ->alter();
        $this->mysqlStatement($alter->query);

        $columns = $this->fetchMysqlColumns($table);
        $columnNames = array_column($columns, 'COLUMN_NAME');

        $this->assertNotContains('temp', $columnNames);
    }

    public function testAlterTableAddIndex(): void
    {
        $table = 'test_alter_idx_' . uniqid();
        $this->trackMysqlTable($table);

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('email', 255)
            ->create();
        $this->mysqlStatement($create->query);

        $alter = $this->schema->table($table)
            ->addIndex('idx_email', ['email'])
            ->alter();
        $this->mysqlStatement($alter->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND INDEX_NAME = 'idx_email'"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        \assert(\is_array($row));
        $this->assertSame('idx_email', $row['INDEX_NAME']);
    }

    public function testDropTable(): void
    {
        $table = 'test_drop_' . uniqid();

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->create();
        $this->mysqlStatement($create->query);

        $drop = $this->schema->table($table)->drop();
        $this->mysqlStatement($drop->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as cnt FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ?"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertSame('0', (string) $row['cnt']); // @phpstan-ignore cast.string
    }

    public function testCreateTableWithForeignKey(): void
    {
        $parentTable = 'test_fk_parent_' . uniqid();
        $childTable = 'test_fk_child_' . uniqid();
        $this->trackMysqlTable($childTable);
        $this->trackMysqlTable($parentTable);

        $createParent = $this->schema->table($parentTable)
            ->id()
            ->create();
        $this->mysqlStatement($createParent->query);

        $createChild = $this->schema->table($childTable)
            ->id()
            ->bigInteger('parent_id')->unsigned()
            ->foreignKey('parent_id')
                ->references('id')
                ->on($parentTable)
                ->onDelete(ForeignKeyAction::Cascade)
            ->create();
        $this->mysqlStatement($createChild->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL"
        );
        \assert($stmt !== false);
        $stmt->execute([$childTable]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        \assert(\is_array($row));
        $this->assertSame($parentTable, $row['REFERENCED_TABLE_NAME']);
    }

    public function testCreateTableWithNullableAndDefault(): void
    {
        $table = 'test_null_def_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('nickname', 100)->nullable()->default('anonymous')
            ->integer('score')->default(0)
            ->create();

        $this->mysqlStatement($result->query);

        $columns = $this->fetchMysqlColumns($table);

        $nicknameCol = $this->findColumn($columns, 'nickname');
        $this->assertSame('YES', $nicknameCol['IS_NULLABLE']);
        $this->assertSame('anonymous', $nicknameCol['COLUMN_DEFAULT']);

        $scoreCol = $this->findColumn($columns, 'score');
        $this->assertSame('0', (string) $scoreCol['COLUMN_DEFAULT']); // @phpstan-ignore cast.string
    }

    public function testCreateTableWithCheckConstraint(): void
    {
        $table = 'test_check_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->id()
            ->integer('age')
            ->check('age_range', '`age` >= 0 AND `age` < 150')
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $insert = $pdo->prepare("INSERT INTO `{$table}` (`age`) VALUES (30)");
        \assert($insert !== false);
        $insert->execute();

        $violation = $pdo->prepare("INSERT INTO `{$table}` (`age`) VALUES (-5)");
        \assert($violation !== false);
        try {
            $violation->execute();
            $this->fail('Expected CHECK constraint violation');
        } catch (\PDOException $e) {
            $this->assertIsArray($e->errorInfo);
            $this->assertSame(3819, $e->errorInfo[1]);
        }
    }

    public function testCreateTableWithGeneratedColumn(): void
    {
        $table = 'test_generated_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->id()
            ->integer('width')
            ->integer('height')
            ->integer('area')->generatedAs('`width` * `height`')->stored()
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT EXTRA FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND COLUMN_NAME = 'area'"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertStringContainsString('STORED GENERATED', (string) $row['EXTRA']); // @phpstan-ignore cast.string

        $insert = $pdo->prepare("INSERT INTO `{$table}` (`width`, `height`) VALUES (3, 4)");
        \assert($insert !== false);
        $insert->execute();

        $select = $pdo->prepare("SELECT `area` FROM `{$table}`");
        \assert($select !== false);
        $select->execute();
        $area = $select->fetchColumn();
        $this->assertSame(12, (int) $area);
    }

    public function testCreateTableWithPartitioning(): void
    {
        $table = 'test_partition_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->integer('id')->primary()
            ->partitionByHash('`id`')
            ->create();

        $this->assertStringContainsString('PARTITION BY HASH(`id`)', $result->query);
        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM information_schema.PARTITIONS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertGreaterThanOrEqual(1, (int) $row['cnt']); // @phpstan-ignore cast.int
    }

    public function testCreateTableWithCompositeIndex(): void
    {
        $table = 'test_composite_idx_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->id()
            ->string('first_name', 100)
            ->string('last_name', 100)
            ->addIndex('idx_name', ['last_name', 'first_name'])
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND INDEX_NAME = 'idx_name' "
            . "ORDER BY SEQ_IN_INDEX"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame('last_name', $rows[0]['COLUMN_NAME']);
        $this->assertSame('first_name', $rows[1]['COLUMN_NAME']);
    }

    public function testCreateTableWithFullTextIndex(): void
    {
        $table = 'test_fulltext_' . uniqid();
        $this->trackMysqlTable($table);

        $result = $this->schema->table($table)
            ->id()
            ->string('title', 200)
            ->text('body')
            ->fulltextIndex(['title', 'body'], 'ft_title_body')
            ->create();

        $this->mysqlStatement($result->query);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT INDEX_TYPE FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ? AND INDEX_NAME = 'ft_title_body' LIMIT 1"
        );
        \assert($stmt !== false);
        $stmt->execute([$table]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertSame('FULLTEXT', (string) $row['INDEX_TYPE']); // @phpstan-ignore cast.string
    }

    public function testTruncateTable(): void
    {
        $table = 'test_truncate_' . uniqid();
        $this->trackMysqlTable($table);

        $create = $this->schema->table($table)
            ->integer('id')->primary()
            ->string('name', 50)
            ->create();
        $this->mysqlStatement($create->query);

        $pdo = $this->connectMysql();
        $insertStmt = $pdo->prepare("INSERT INTO `{$table}` (`id`, `name`) VALUES (1, 'a'), (2, 'b')");
        \assert($insertStmt !== false);
        $insertStmt->execute();

        $truncate = $this->schema->table($table)->truncate();
        $this->mysqlStatement($truncate->query);

        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `{$table}`");
        \assert($stmt !== false);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        $this->assertSame('0', (string) $row['cnt']); // @phpstan-ignore cast.string
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchMysqlColumns(string $table): array
    {
        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            "SELECT * FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function findColumn(array $columns, string $name): array
    {
        foreach ($columns as $col) {
            if ($col['COLUMN_NAME'] === $name) {
                return $col;
            }
        }

        $this->fail("Column '{$name}' not found");
    }
}
