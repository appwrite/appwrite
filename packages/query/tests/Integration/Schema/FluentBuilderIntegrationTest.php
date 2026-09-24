<?php

namespace Tests\Integration\Schema;

use PDO;
use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Schema\ClickHouse;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\MongoDB;
use Utopia\Query\Schema\MySQL;
use Utopia\Query\Schema\PostgreSQL;
use Utopia\Query\Schema\SQLite;

/**
 * End-to-end coverage for behaviours introduced by the fluent builder refactor
 * that the per-dialect integration files do not exercise:
 *
 *  - Column::primary([...]) composite key dispatch (every dialect)
 *  - Column::check(name, expr) two-arg dispatch (chained off a Column)
 *  - Column::enum(name, [...]) two-arg dispatch (creates a sibling enum column)
 *  - ForeignKey forwarder mid-chain (e.g. `->foreignKey()->...->string()->create()`)
 *  - Table terminals: createIfNotExists, dropIfExists, rename, truncate
 *  - Table::orderBy() (ClickHouse explicit ORDER BY)
 *
 * Each test runs a real CREATE TABLE / INSERT / SELECT round-trip against the
 * docker-compose test stack to confirm the emitted SQL is accepted by the
 * target engine and behaves as expected.
 */
class FluentBuilderIntegrationTest extends IntegrationTestCase
{
    public function testMysqlColumnPrimaryArrayDispatchCreatesCompositeKey(): void
    {
        $table = 'fluent_pk_' . uniqid();
        $this->trackMysqlTable($table);
        $schema = new MySQL();

        $stmt = $schema->table($table)
            ->integer('order_id')
            ->integer('product_id')
            ->primary(['order_id', 'product_id'])
            ->create();

        $this->mysqlStatement($stmt->query);

        $row = $this->fetchOneMysql(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) AS cols "
            . "FROM information_schema.KEY_COLUMN_USAGE "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$table}' "
            . "AND CONSTRAINT_NAME = 'PRIMARY'"
        );

        $this->assertSame('order_id,product_id', (string) $row['cols']); // @phpstan-ignore cast.string
    }

    public function testPostgresColumnPrimaryArrayDispatchCreatesCompositeKey(): void
    {
        $table = 'fluent_pk_' . uniqid();
        $this->trackPostgresTable($table);
        $schema = new PostgreSQL();

        $stmt = $schema->table($table)
            ->integer('tenant_id')
            ->integer('event_id')
            ->primary(['tenant_id', 'event_id'])
            ->create();

        $this->postgresStatement($stmt->query);

        $rows = $this->fetchColumnPostgres(
            "SELECT a.attname FROM pg_index i "
            . "JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) "
            . "WHERE i.indrelid = '\"{$table}\"'::regclass AND i.indisprimary "
            . 'ORDER BY array_position(i.indkey, a.attnum)'
        );

        $this->assertSame(['tenant_id', 'event_id'], $rows);
    }

    public function testSqliteColumnPrimaryArrayDispatchCreatesCompositeKey(): void
    {
        $table = 'fluent_pk_' . uniqid();
        $schema = new SQLite();

        $stmt = $schema->table($table)
            ->integer('a')
            ->integer('b')
            ->primary(['a', 'b'])
            ->create();

        $this->sqliteStatement($stmt->query);

        $rows = $this->fetchAllSqlite("PRAGMA table_info('{$table}')");

        $pkCols = [];
        foreach ($rows as $r) {
            if ((int) $r['pk'] > 0) { // @phpstan-ignore cast.int
                $pkCols[(int) $r['pk']] = $r['name']; // @phpstan-ignore cast.int
            }
        }
        \ksort($pkCols);

        $this->assertSame(['a', 'b'], \array_values($pkCols));
    }

    public function testClickhouseColumnPrimaryArrayDispatchPopulatesOrderBy(): void
    {
        $table = 'fluent_pk_' . uniqid();
        $this->trackClickhouseTable($table);
        $schema = new ClickHouse();

        $stmt = $schema->table($table)
            ->integer('a')->unsigned()
            ->integer('b')->unsigned()
            ->primary(['a', 'b'])
            ->create();

        $this->clickhouseStatement($stmt->query);

        $ch = $this->connectClickhouse();
        $rows = $ch->execute(
            "SELECT sorting_key FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );

        $this->assertSame('a, b', $rows[0]['sorting_key']);
    }

    public function testMysqlColumnCheckTwoArgDispatchCreatesTableLevelCheck(): void
    {
        $table = 'fluent_check_' . uniqid();
        $this->trackMysqlTable($table);
        $schema = new MySQL();

        $stmt = $schema->table($table)
            ->id()
            ->integer('age')
            ->check('age_range', '`age` >= 0 AND `age` < 150')
            ->create();

        $this->mysqlStatement($stmt->query);

        $pdo = $this->connectMysql();
        $insertOk = $pdo->prepare("INSERT INTO `{$table}` (`age`) VALUES (42)");
        \assert($insertOk !== false);
        $insertOk->execute();

        $rejected = false;
        try {
            $stmt = $pdo->prepare("INSERT INTO `{$table}` (`age`) VALUES (200)");
            \assert($stmt !== false);
            $stmt->execute();
        } catch (\PDOException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'Two-arg check forwarder should produce table-level CHECK constraint');
    }

    public function testPostgresColumnCheckTwoArgDispatchCreatesTableLevelCheck(): void
    {
        $table = 'fluent_check_' . uniqid();
        $this->trackPostgresTable($table);
        $schema = new PostgreSQL();

        $stmt = $schema->table($table)
            ->id()
            ->integer('age')
            ->check('age_min', '"age" >= 18')
            ->create();

        $this->postgresStatement($stmt->query);

        $pdo = $this->connectPostgres();
        $ok = $pdo->prepare("INSERT INTO \"{$table}\" (\"age\") VALUES (25)");
        \assert($ok !== false);
        $ok->execute();

        $rejected = false;
        try {
            $bad = $pdo->prepare("INSERT INTO \"{$table}\" (\"age\") VALUES (5)");
            \assert($bad !== false);
            $bad->execute();
        } catch (\PDOException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    public function testSqliteColumnCheckTwoArgDispatchCreatesTableLevelCheck(): void
    {
        $table = 'fluent_check_' . uniqid();
        $schema = new SQLite();

        $stmt = $schema->table($table)
            ->id()
            ->integer('score')
            ->check('score_range', '"score" BETWEEN 0 AND 100')
            ->create();

        $this->sqliteStatement($stmt->query);

        $pdo = $this->connectSqlite();
        $ok = $pdo->prepare("INSERT INTO `{$table}` (`score`) VALUES (50)");
        \assert($ok !== false);
        $ok->execute();

        $rejected = false;
        try {
            $bad = $pdo->prepare("INSERT INTO `{$table}` (`score`) VALUES (200)");
            \assert($bad !== false);
            $bad->execute();
        } catch (\PDOException) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    public function testMysqlColumnEnumTwoArgDispatchAddsSiblingEnumColumn(): void
    {
        $table = 'fluent_enum_' . uniqid();
        $this->trackMysqlTable($table);
        $schema = new MySQL();

        $stmt = $schema->table($table)
            ->id()
            ->string('label', 50)
            ->enum('status', ['draft', 'live'])
            ->create();

        $this->mysqlStatement($stmt->query);

        $row = $this->fetchOneMysql(
            "SELECT COLUMN_TYPE AS ct FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'status'"
        );

        $this->assertSame("enum('draft','live')", (string) $row['ct']); // @phpstan-ignore cast.string
    }

    public function testMysqlForeignKeyForwarderMidChainContinuesColumnsAfterFK(): void
    {
        $parent = 'fluent_fk_parent_' . uniqid();
        $child = 'fluent_fk_child_' . uniqid();
        $this->trackMysqlTable($child);
        $this->trackMysqlTable($parent);
        $schema = new MySQL();

        $this->mysqlStatement($schema->table($parent)->id()->string('name', 50)->create()->query);

        $stmt = $schema->table($child)
            ->id()
            ->bigInteger('parent_id')->unsigned()
            ->foreignKey('parent_id')->references('id')->on($parent)->onDelete(ForeignKeyAction::Cascade)
            ->string('extra_col', 50)
            ->boolean('flag')
            ->create();

        $this->mysqlStatement($stmt->query);

        $cols = $this->fetchColumnMysql(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$child}' "
            . 'ORDER BY ORDINAL_POSITION'
        );
        $this->assertSame(['id', 'parent_id', 'extra_col', 'flag'], $cols);

        $fkRow = $this->fetchOneMysql(
            "SELECT DELETE_RULE AS dr FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = 'query_test' AND TABLE_NAME = '{$child}'"
        );
        $this->assertSame('CASCADE', (string) $fkRow['dr']); // @phpstan-ignore cast.string
    }

    public function testPostgresForeignKeyForwarderMidChainContinuesColumnsAfterFK(): void
    {
        $parent = 'fluent_fk_parent_' . uniqid();
        $child = 'fluent_fk_child_' . uniqid();
        $this->trackPostgresTable($child);
        $this->trackPostgresTable($parent);
        $schema = new PostgreSQL();

        $this->postgresStatement($schema->table($parent)->id()->string('name', 50)->create()->query);

        $stmt = $schema->table($child)
            ->id()
            ->integer('parent_id')
            ->foreignKey('parent_id')->references('id')->on($parent)->onDelete(ForeignKeyAction::Cascade)
            ->string('extra_col', 50)
            ->boolean('flag')
            ->create();

        $this->postgresStatement($stmt->query);

        $cols = $this->fetchColumnPostgres(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_catalog = 'query_test' AND table_schema = 'public' AND table_name = '{$child}' "
            . 'ORDER BY ordinal_position'
        );
        $this->assertSame(['id', 'parent_id', 'extra_col', 'flag'], $cols);
    }

    public function testMysqlCreateIfNotExistsIsIdempotent(): void
    {
        $table = 'fluent_ine_' . uniqid();
        $this->trackMysqlTable($table);
        $schema = new MySQL();

        $stmt = $schema->table($table)->id()->string('name')->createIfNotExists();
        $this->mysqlStatement($stmt->query);
        $this->mysqlStatement($stmt->query); // idempotent — must not throw

        $row = $this->fetchOneMysql(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$table}'"
        );
        $this->assertSame('1', (string) $row['cnt']); // @phpstan-ignore cast.string
    }

    public function testMysqlDropIfExistsTolerantOfMissingTable(): void
    {
        $missing = 'fluent_missing_' . uniqid();
        $schema = new MySQL();

        $stmt = $schema->table($missing)->dropIfExists();
        $this->mysqlStatement($stmt->query); // must not throw on missing table

        $this->addToAssertionCount(1);
    }

    public function testMysqlRenameRoundTrip(): void
    {
        $from = 'fluent_rn_a_' . uniqid();
        $to = 'fluent_rn_b_' . uniqid();
        $this->trackMysqlTable($from);
        $this->trackMysqlTable($to);
        $schema = new MySQL();

        $this->mysqlStatement($schema->table($from)->id()->create()->query);
        $this->mysqlStatement($schema->table($from)->rename($to)->query);

        $row = $this->fetchOneMysql(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$to}'"
        );
        $this->assertSame('1', (string) $row['cnt']); // @phpstan-ignore cast.string
    }

    public function testPostgresRenameRoundTrip(): void
    {
        $from = 'fluent_rn_a_' . uniqid();
        $to = 'fluent_rn_b_' . uniqid();
        $this->trackPostgresTable($from);
        $this->trackPostgresTable($to);
        $schema = new PostgreSQL();

        $this->postgresStatement($schema->table($from)->id()->create()->query);
        $this->postgresStatement($schema->table($from)->rename($to)->query);

        $row = $this->fetchOnePostgres(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables "
            . "WHERE table_catalog = 'query_test' AND table_schema = 'public' AND table_name = '{$to}'"
        );
        $this->assertSame('1', (string) $row['cnt']); // @phpstan-ignore cast.string
    }

    public function testSqliteRenameRoundTrip(): void
    {
        $from = 'fluent_rn_a_' . uniqid();
        $to = 'fluent_rn_b_' . uniqid();
        $schema = new SQLite();

        $this->sqliteStatement($schema->table($from)->id()->create()->query);
        $this->sqliteStatement($schema->table($from)->rename($to)->query);

        $row = $this->fetchOneSqlite(
            "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type = 'table' AND name = '{$to}'"
        );
        $this->assertSame(1, (int) $row['cnt']); // @phpstan-ignore cast.int
    }

    public function testMysqlTruncateRemovesRowsKeepsTable(): void
    {
        $table = 'fluent_tr_' . uniqid();
        $this->trackMysqlTable($table);
        $schema = new MySQL();

        $this->mysqlStatement($schema->table($table)->id()->string('name')->create()->query);
        $pdo = $this->connectMysql();
        $insert = $pdo->prepare("INSERT INTO `{$table}` (`name`) VALUES ('a'), ('b'), ('c')");
        \assert($insert !== false);
        $insert->execute();

        $this->mysqlStatement($schema->table($table)->truncate()->query);

        $row = $this->fetchOneMysql("SELECT COUNT(*) AS cnt FROM `{$table}`");
        $this->assertSame('0', (string) $row['cnt']); // @phpstan-ignore cast.string

        $existsRow = $this->fetchOneMysql(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = 'query_test' AND TABLE_NAME = '{$table}'"
        );
        $this->assertSame('1', (string) $existsRow['cnt']); // @phpstan-ignore cast.string
    }

    public function testSqliteTruncateRemovesRowsKeepsTable(): void
    {
        $table = 'fluent_tr_' . uniqid();
        $schema = new SQLite();

        $this->sqliteStatement($schema->table($table)->id()->string('name')->create()->query);
        $pdo = $this->connectSqlite();
        $insert = $pdo->prepare("INSERT INTO `{$table}` (`name`) VALUES ('a'), ('b')");
        \assert($insert !== false);
        $insert->execute();

        $this->sqliteStatement($schema->table($table)->truncate()->query);

        $row = $this->fetchOneSqlite("SELECT COUNT(*) AS cnt FROM `{$table}`");
        $this->assertSame(0, (int) $row['cnt']); // @phpstan-ignore cast.int
    }

    public function testClickhouseOrderByExplicitlyControlsSortingKey(): void
    {
        $table = 'fluent_orderby_' . uniqid();
        $this->trackClickhouseTable($table);
        $schema = new ClickHouse();

        $stmt = $schema->table($table)
            ->string('tenantId')
            ->string('eventId')
            ->datetime('createdAt')
            ->primary(['tenantId', 'eventId'])
            ->engine(Engine::MergeTree)
            ->orderBy(['tenantId', 'createdAt'])
            ->create();

        $this->clickhouseStatement($stmt->query);

        $ch = $this->connectClickhouse();
        $row = $ch->execute(
            "SELECT sorting_key FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );

        $this->assertSame('tenantId, createdAt', $row[0]['sorting_key']);
    }

    public function testClickhouseOrderByFallsBackToPrimaryWhenUnset(): void
    {
        $table = 'fluent_orderby_pk_' . uniqid();
        $this->trackClickhouseTable($table);
        $schema = new ClickHouse();

        $stmt = $schema->table($table)
            ->integer('id')->unsigned()->primary()
            ->string('value')
            ->engine(Engine::MergeTree)
            ->create();

        $this->clickhouseStatement($stmt->query);

        $ch = $this->connectClickhouse();
        $row = $ch->execute(
            "SELECT sorting_key FROM system.tables WHERE database = 'query_test' AND name = '{$table}'"
        );

        $this->assertSame('id', $row[0]['sorting_key']);
    }

    public function testClickhouseEndToEndExampleFromReadme(): void
    {
        $table = 'events_readme_' . uniqid();
        $this->trackClickhouseTable($table);
        $schema = new ClickHouse();

        $stmt = $schema->table($table)
            ->string('tenantId')
            ->string('eventId')
            ->string('payload')->nullable()
            ->datetime('createdAt')
            ->primary(['tenantId', 'eventId'])
            ->engine(Engine::MergeTree)
            ->orderBy(['tenantId', 'createdAt'])
            ->ttl('createdAt + INTERVAL 90 DAY')
            ->create();

        $this->clickhouseStatement($stmt->query);

        $ch = $this->connectClickhouse();

        $engineRow = $ch->execute(
            "SELECT engine, sorting_key, engine_full FROM system.tables "
            . "WHERE database = 'query_test' AND name = '{$table}'"
        );
        $this->assertSame('MergeTree', $engineRow[0]['engine']);
        $this->assertSame('tenantId, createdAt', $engineRow[0]['sorting_key']);
        $engineFull = $engineRow[0]['engine_full'];
        \assert(\is_string($engineFull));
        $this->assertStringContainsString('TTL', $engineFull);

        // Round-trip with a far-future timestamp so the 90-day TTL on
        // createdAt cannot collect rows during background merges before
        // SELECT runs.
        $future = (new \DateTimeImmutable('+1 year'))->format('Y-m-d H:i:s');
        $this->clickhouseStatement(
            "INSERT INTO `{$table}` (tenantId, eventId, payload, createdAt) VALUES "
            . "('t1', 'e1', NULL, '{$future}'), "
            . "('t1', 'e2', 'p2', '{$future}')"
        );
        $rows = $ch->execute("SELECT eventId, payload FROM `{$table}` ORDER BY eventId");
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['payload']);
        $this->assertSame('p2', $rows[1]['payload']);
    }

    public function testMongoFluentTerminalsAndForwardersRoundTrip(): void
    {
        $collection = 'fluent_mongo_' . uniqid();
        $this->trackMongoCollection($collection);

        $this->connectMongoDB();
        $mongo = $this->mongoClient;
        \assert($mongo !== null);

        $schema = new MongoDB();

        $createPlan = $schema->table($collection)
            ->string('name', 100)
            ->integer('age')->nullable()
            ->boolean('active')->default(true)
            ->createIfNotExists();
        $mongo->command($createPlan->query);

        $this->assertContains($collection, $mongo->listCollectionNames());

        $alterPlan = $schema->table($collection)
            ->string('phone', 20)->nullable()->comment('contact phone')
            ->alter();
        $mongo->command($alterPlan->query);

        $mongo->insertOne($collection, ['name' => 'a', 'active' => true]);
        $mongo->insertOne($collection, ['name' => 'b', 'active' => true]);
        $mongo->command($schema->table($collection)->truncate()->query);
        $this->assertContains($collection, $mongo->listCollectionNames());

        $renamed = $collection . '_renamed';
        $this->trackMongoCollection($renamed);
        $mongo->command($schema->table($collection)->rename($renamed)->query);
        $this->assertContains($renamed, $mongo->listCollectionNames());
        $this->assertNotContains($collection, $mongo->listCollectionNames());

        $mongo->command($schema->table($renamed)->drop()->query);
        $this->assertNotContains($renamed, $mongo->listCollectionNames());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOneMysql(string $sql): array
    {
        $stmt = $this->connectMysql()->query($sql);
        \assert($stmt !== false);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        return $row;
    }

    /**
     * @return list<mixed>
     */
    private function fetchColumnMysql(string $sql): array
    {
        $stmt = $this->connectMysql()->query($sql);
        \assert($stmt !== false);

        /** @var list<mixed> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOnePostgres(string $sql): array
    {
        $stmt = $this->connectPostgres()->query($sql);
        \assert($stmt !== false);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        return $row;
    }

    /**
     * @return list<mixed>
     */
    private function fetchColumnPostgres(string $sql): array
    {
        $stmt = $this->connectPostgres()->query($sql);
        \assert($stmt !== false);

        /** @var list<mixed> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOneSqlite(string $sql): array
    {
        $stmt = $this->connectSqlite()->query($sql);
        \assert($stmt !== false);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllSqlite(string $sql): array
    {
        $stmt = $this->connectSqlite()->query($sql);
        \assert($stmt !== false);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
