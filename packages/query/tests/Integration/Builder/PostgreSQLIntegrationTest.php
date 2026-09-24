<?php

namespace Tests\Integration\Builder;

use PDOException;
use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\PostgreSQL as Builder;
use Utopia\Query\Builder\VectorMetric;
use Utopia\Query\Query;

class PostgreSQLIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->trackPostgresTable('users');
        $this->trackPostgresTable('orders');

        $this->postgresStatement('DROP TABLE IF EXISTS "orders" CASCADE');
        $this->postgresStatement('DROP TABLE IF EXISTS "users" CASCADE');

        $this->postgresStatement('
            CREATE TABLE "users" (
                "id" SERIAL PRIMARY KEY,
                "name" VARCHAR(255) NOT NULL,
                "email" VARCHAR(255) NOT NULL UNIQUE,
                "age" INT NOT NULL DEFAULT 0,
                "city" VARCHAR(100) DEFAULT NULL,
                "active" BOOLEAN NOT NULL DEFAULT TRUE,
                "created_at" TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        $this->postgresStatement('
            CREATE TABLE "orders" (
                "id" SERIAL PRIMARY KEY,
                "user_id" INT NOT NULL REFERENCES "users"("id"),
                "product" VARCHAR(255) NOT NULL,
                "amount" DECIMAL(10,2) NOT NULL,
                "status" VARCHAR(50) NOT NULL DEFAULT \'pending\',
                "created_at" TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        $this->postgresStatement("
            INSERT INTO \"users\" (\"name\", \"email\", \"age\", \"city\", \"active\") VALUES
            ('Alice', 'alice@example.com', 30, 'New York', TRUE),
            ('Bob', 'bob@example.com', 25, 'London', TRUE),
            ('Charlie', 'charlie@example.com', 35, 'New York', FALSE),
            ('Diana', 'diana@example.com', 28, 'Paris', TRUE),
            ('Eve', 'eve@example.com', 22, 'London', TRUE)
        ");

        $this->postgresStatement("
            INSERT INTO \"orders\" (\"user_id\", \"product\", \"amount\", \"status\") VALUES
            (1, 'Widget', 29.99, 'completed'),
            (1, 'Gadget', 49.99, 'completed'),
            (2, 'Widget', 29.99, 'pending'),
            (3, 'Gizmo', 99.99, 'completed'),
            (4, 'Widget', 29.99, 'cancelled'),
            (4, 'Gadget', 49.99, 'pending'),
            (5, 'Gizmo', 99.99, 'completed')
        ");

        $this->setUpEmbeddings();
    }

    private function setUpEmbeddings(): void
    {
        try {
            $this->postgresStatement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (PDOException $e) {
            $this->markTestSkipped('pgvector extension is not available: ' . $e->getMessage());
        }

        $this->trackPostgresTable('embeddings');
        $this->postgresStatement('DROP TABLE IF EXISTS "embeddings" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "embeddings" (
                "id" SERIAL PRIMARY KEY,
                "label" TEXT NOT NULL,
                "vec" vector(3) NOT NULL
            )
        ');
        $this->postgresStatement("
            INSERT INTO \"embeddings\" (\"label\", \"vec\") VALUES
            ('mixed', '[0.5,0.5,0.01]'),
            ('x-axis', '[1,0.01,0.01]'),
            ('y-axis', '[0.01,1,0.01]'),
            ('z-axis', '[0.01,0.01,1]'),
            ('far', '[10,10,10]')
        ");
    }

    public function testSelectWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('city', ['New York'])])
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testSelectWithOrderByLimitOffset(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->sortAsc('name')
            ->limit(2)
            ->offset(1)
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Bob', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testSelectWithJoin(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->select(['u.name', 'o.product', 'o.amount'])
            ->join('orders', 'u.id', 'o.user_id', '=', 'o')
            ->filter([Query::equal('o.status', ['completed'])])
            ->sortAsc('u.name')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(4, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Widget', $rows[0]['product']);
    }

    public function testSelectWithLeftJoin(): void
    {
        $result = (new Builder())
            ->from('users', 'u')
            ->select(['u.name', 'o.product'])
            ->leftJoin('orders', 'u.id', 'o.user_id', '=', 'o')
            ->filter([Query::equal('o.status', ['cancelled'])])
            ->sortAsc('u.name')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Diana', $rows[0]['name']);
    }

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Frank', 'email' => 'frank@example.com', 'age' => 40, 'city' => 'Berlin', 'active' => true])
            ->insert();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'city'])
            ->filter([Query::equal('email', ['frank@example.com'])])
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(1, $rows);
        $this->assertSame('Frank', $rows[0]['name']);
        $this->assertSame('Berlin', $rows[0]['city']);
    }

    public function testInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Grace', 'email' => 'grace@example.com', 'age' => 33, 'city' => 'Tokyo', 'active' => true])
            ->set(['name' => 'Hank', 'email' => 'hank@example.com', 'age' => 45, 'city' => 'Tokyo', 'active' => false])
            ->insert();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['Tokyo'])])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(2, $rows);
        $this->assertSame('Grace', $rows[0]['name']);
        $this->assertSame('Hank', $rows[1]['name']);
    }

    public function testInsertWithReturning(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Ivy', 'email' => 'ivy@example.com', 'age' => 27, 'city' => 'Madrid', 'active' => true])
            ->returning(['id', 'name', 'email'])
            ->insert();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Ivy', $rows[0]['name']);
        $this->assertSame('ivy@example.com', $rows[0]['email']);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertGreaterThan(0, (int) $rows[0]['id']); // @phpstan-ignore cast.int
    }

    public function testUpdateWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['city' => 'San Francisco'])
            ->filter([Query::equal('name', ['Alice'])])
            ->update();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->select(['city'])
            ->filter([Query::equal('name', ['Alice'])])
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(1, $rows);
        $this->assertSame('San Francisco', $rows[0]['city']);
    }

    public function testUpdateWithReturning(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['age' => 31])
            ->filter([Query::equal('name', ['Alice'])])
            ->returning(['name', 'age'])
            ->update();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(31, (int) $rows[0]['age']); // @phpstan-ignore cast.int
    }

    public function testDeleteWithWhere(): void
    {
        $this->postgresStatement("DELETE FROM \"orders\" WHERE \"user_id\" = 5");

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Eve'])])
            ->delete();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Eve'])])
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(0, $rows);
    }

    public function testDeleteWithReturning(): void
    {
        $this->postgresStatement("DELETE FROM \"orders\" WHERE \"user_id\" = 3");

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Charlie'])])
            ->returning(['id', 'name'])
            ->delete();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
    }

    public function testSelectWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->count('*', 'order_count')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('order_count', 1)])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame(2, (int) $rows[0]['order_count']); // @phpstan-ignore cast.int
        $this->assertSame(4, (int) $rows[1]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame(2, (int) $rows[1]['order_count']); // @phpstan-ignore cast.int
    }

    public function testSelectWithUnion(): void
    {
        $query1 = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['New York'])]);

        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['London'])])
            ->union($query1)
            ->build();

        $rows = $this->executeOnPostgres($result);

        $names = array_column($rows, 'name');
        sort($names);

        $this->assertCount(4, $rows);
        $this->assertSame(['Alice', 'Bob', 'Charlie', 'Eve'], $names);
    }

    public function testUpsertOnConflictDoUpdate(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice Updated', 'email' => 'alice@example.com', 'age' => 31, 'city' => 'Boston', 'active' => true])
            ->onConflict(['email'], ['name', 'age', 'city'])
            ->upsert();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'age', 'city'])
            ->filter([Query::equal('email', ['alice@example.com'])])
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice Updated', $rows[0]['name']);
        $this->assertSame(31, (int) $rows[0]['age']); // @phpstan-ignore cast.int
        $this->assertSame('Boston', $rows[0]['city']);
    }

    public function testInsertOrIgnoreOnConflictDoNothing(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice Duplicate', 'email' => 'alice@example.com', 'age' => 99, 'city' => 'Nowhere', 'active' => false])
            ->insertOrIgnore();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'age'])
            ->filter([Query::equal('email', ['alice@example.com'])])
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(30, (int) $rows[0]['age']); // @phpstan-ignore cast.int
    }

    public function testSelectWithCte(): void
    {
        $cteQuery = (new Builder())
            ->from('users')
            ->select(['id', 'name', 'city'])
            ->filter([Query::equal('active', [true])]);

        $result = (new Builder())
            ->with('active_users', $cteQuery)
            ->from('active_users')
            ->select(['name', 'city'])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(4, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertSame('Diana', $rows[2]['name']);
        $this->assertSame('Eve', $rows[3]['name']);
    }

    public function testSelectWithWindowFunction(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id', 'product', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->sortAsc('user_id')
            ->sortDesc('amount')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertGreaterThan(0, count($rows));
        $this->assertArrayHasKey('rn', $rows[0]);

        $user1Rows = array_filter($rows, fn ($r) => (int) $r['user_id'] === 1); // @phpstan-ignore cast.int
        $user1Rows = array_values($user1Rows);
        $this->assertSame(1, (int) $user1Rows[0]['rn']); // @phpstan-ignore cast.int
        $this->assertSame(2, (int) $user1Rows[1]['rn']); // @phpstan-ignore cast.int
    }

    public function testSelectWithDistinct(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['product'])
            ->distinct()
            ->sortAsc('product')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(3, $rows);
        $products = array_column($rows, 'product');
        $this->assertSame(['Gadget', 'Gizmo', 'Widget'], $products);
    }

    public function testSelectWithSubqueryInWhere(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filterWhereIn('id', $subquery)
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $names = array_column($rows, 'name');

        $this->assertCount(3, $rows);
        $this->assertSame(['Alice', 'Charlie', 'Eve'], $names);
    }

    public function testSelectForUpdate(): void
    {
        $pdo = $this->connectPostgres();
        $pdo->beginTransaction();

        try {
            $result = (new Builder())
                ->from('users')
                ->select(['id', 'name'])
                ->filter([Query::equal('name', ['Alice'])])
                ->forUpdate()
                ->build();

            $rows = $this->executeOnPostgres($result);

            $this->assertCount(1, $rows);
            $this->assertSame('Alice', $rows[0]['name']);
        } finally {
            $pdo->rollBack();
        }
    }

    public function testVectorSearchL2Distance(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->select(['label'])
            ->orderByVectorDistance('vec', [0.95, 0.02, 0.02], VectorMetric::Euclidean)
            ->limit(1)
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('x-axis', $rows[0]['label']);
    }

    public function testVectorSearchCosineDistance(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->select(['label'])
            ->orderByVectorDistance('vec', [1.0, 0.5, 0.01], VectorMetric::Cosine)
            ->limit(2)
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(2, $rows);
        $this->assertSame('mixed', $rows[0]['label']);
        $this->assertSame('x-axis', $rows[1]['label']);
    }

    public function testVectorSearchInnerProduct(): void
    {
        $result = (new Builder())
            ->from('embeddings')
            ->select(['label'])
            ->orderByVectorDistance('vec', [1.0, 1.0, 1.0], VectorMetric::Dot)
            ->limit(1)
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('far', $rows[0]['label']);
    }

    public function testMergeUpdateExistingAndInsertNew(): void
    {
        $this->trackPostgresTable('user_updates');
        $this->postgresStatement('DROP TABLE IF EXISTS "user_updates" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "user_updates" (
                "id" INT PRIMARY KEY,
                "name" VARCHAR(255) NOT NULL,
                "city" VARCHAR(100) NOT NULL
            )
        ');
        $this->postgresStatement("
            INSERT INTO \"user_updates\" (\"id\", \"name\", \"city\") VALUES
            (1, 'Alice', 'Seattle'),
            (2, 'Bob', 'Dublin'),
            (99, 'Zack', 'Oslo')
        ");

        $source = (new Builder())
            ->from('user_updates')
            ->select(['id', 'name', 'city']);

        $merge = (new Builder())
            ->mergeInto('users')
            ->using($source, 'src')
            ->on('"users"."id" = "src"."id"')
            ->whenMatched('UPDATE SET "city" = "src"."city"')
            ->whenNotMatched('INSERT ("name", "email", "age", "city", "active") VALUES ("src"."name", "src"."name" || \'+merged@example.com\', 0, "src"."city", TRUE)')
            ->executeMerge();

        $this->executeOnPostgres($merge);

        $aliceCheck = (new Builder())
            ->from('users')
            ->select(['city'])
            ->filter([Query::equal('name', ['Alice'])])
            ->build();
        $aliceRows = $this->executeOnPostgres($aliceCheck);
        $this->assertCount(1, $aliceRows);
        $this->assertSame('Seattle', $aliceRows[0]['city']);

        $bobCheck = (new Builder())
            ->from('users')
            ->select(['city'])
            ->filter([Query::equal('name', ['Bob'])])
            ->build();
        $bobRows = $this->executeOnPostgres($bobCheck);
        $this->assertCount(1, $bobRows);
        $this->assertSame('Dublin', $bobRows[0]['city']);

        $zackCheck = (new Builder())
            ->from('users')
            ->select(['name', 'city'])
            ->filter([Query::equal('name', ['Zack'])])
            ->build();
        $zackRows = $this->executeOnPostgres($zackCheck);
        $this->assertCount(1, $zackRows);
        $this->assertSame('Zack', $zackRows[0]['name']);
        $this->assertSame('Oslo', $zackRows[0]['city']);
    }

    public function testAggregateFilter(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->selectAggregateFilter('SUM("amount")', '"status" = ?', 'completed_total', ['completed'])
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('279.96', (string) $rows[0]['completed_total']); // @phpstan-ignore cast.string
    }

    public function testDistinctOn(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['user_id', 'product', 'amount'])
            ->distinctOn(['user_id'])
            ->sortAsc('user_id')
            ->sortDesc('amount')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(5, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame('Gadget', $rows[0]['product']);
        $this->assertSame(2, (int) $rows[1]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame('Widget', $rows[1]['product']);
        $this->assertSame(3, (int) $rows[2]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame('Gizmo', $rows[2]['product']);
        $this->assertSame(4, (int) $rows[3]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame('Gadget', $rows[3]['product']);
        $this->assertSame(5, (int) $rows[4]['user_id']); // @phpstan-ignore cast.int
        $this->assertSame('Gizmo', $rows[4]['product']);
    }

    public function testPercentileDisc(): void
    {
        $result = (new Builder())
            ->from('users')
            ->percentileDisc(0.5, 'age', 'median_age')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame(28, (int) $rows[0]['median_age']); // @phpstan-ignore cast.int
    }

    public function testModeAggregate(): void
    {
        $result = (new Builder())
            ->from('users')
            ->mode('city', 'top_city')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('London', $rows[0]['top_city']);
    }

    public function testLateralJoin(): void
    {
        $topOrder = (new Builder())
            ->from('orders')
            ->select(['product', 'amount'])
            ->whereColumn('user_id', '=', 'u.id')
            ->sortDesc('amount')
            ->limit(1);

        $result = (new Builder())
            ->from('users', 'u')
            ->select(['u.name', 'top_order.product', 'top_order.amount'])
            ->joinLateral($topOrder, 'top_order')
            ->sortAsc('u.name')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(5, $rows);
        $byName = [];
        foreach ($rows as $row) {
            /** @var string $name */
            $name = $row['name'];
            $byName[$name] = $row;
        }

        $this->assertSame('Gadget', $byName['Alice']['product']);
        $this->assertSame('49.99', (string) $byName['Alice']['amount']); // @phpstan-ignore cast.string
        $this->assertSame('Widget', $byName['Bob']['product']);
        $this->assertSame('Gizmo', $byName['Charlie']['product']);
        $this->assertSame('Gadget', $byName['Diana']['product']);
        $this->assertSame('Gizmo', $byName['Eve']['product']);
    }

    public function testRecursiveCte(): void
    {
        $seed = (new Builder())
            ->fromNone()
            ->selectRaw('1');

        $step = (new Builder())
            ->from('t')
            ->selectRaw('"n" + 1')
            ->filter([Query::lessThan('n', 5)]);

        $result = (new Builder())
            ->withRecursiveSeedStep('t', $seed, $step, ['n'])
            ->from('t')
            ->select(['n'])
            ->sortAsc('n')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $values = array_map(static fn (array $row): int => (int) $row['n'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([1, 2, 3, 4, 5], $values);
    }

    public function testJsonbFilterContains(): void
    {
        $this->trackPostgresTable('profiles');
        $this->postgresStatement('DROP TABLE IF EXISTS "profiles" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "profiles" (
                "id" SERIAL PRIMARY KEY,
                "data" JSONB NOT NULL
            )
        ');
        $this->postgresStatement('
            INSERT INTO "profiles" ("data") VALUES
            (\'{"role":"admin","tags":["php","go"]}\'::jsonb),
            (\'{"role":"user","tags":["php"]}\'::jsonb),
            (\'{"role":"user","tags":["rust"]}\'::jsonb)
        ');

        $result = (new Builder())
            ->from('profiles')
            ->select(['id'])
            ->filterJsonContains('data', ['role' => 'admin'])
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['id']); // @phpstan-ignore cast.int
    }

    public function testJsonbFilterPath(): void
    {
        $this->trackPostgresTable('profiles');
        $this->postgresStatement('DROP TABLE IF EXISTS "profiles" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "profiles" (
                "id" SERIAL PRIMARY KEY,
                "data" JSONB NOT NULL
            )
        ');
        $this->postgresStatement('
            INSERT INTO "profiles" ("data") VALUES
            (\'{"role":"admin"}\'::jsonb),
            (\'{"role":"user"}\'::jsonb),
            (\'{"role":"user"}\'::jsonb)
        ');

        $result = (new Builder())
            ->from('profiles')
            ->select(['id'])
            ->filterJsonPath('data', 'role', '=', 'admin')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['id']); // @phpstan-ignore cast.int
    }

    public function testJsonbSetPath(): void
    {
        $this->trackPostgresTable('profiles');
        $this->postgresStatement('DROP TABLE IF EXISTS "profiles" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "profiles" (
                "id" SERIAL PRIMARY KEY,
                "data" JSONB NOT NULL
            )
        ');
        $this->postgresStatement('
            INSERT INTO "profiles" ("id", "data") VALUES
            (1, \'{"name":"OldValue","level":1}\'::jsonb)
        ');

        $result = (new Builder())
            ->from('profiles')
            ->setJsonPath('data', '$.name', 'NewValue')
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->executeOnPostgres($result);

        $check = (new Builder())
            ->from('profiles')
            ->select(['id'])
            ->filterJsonPath('data', 'name', '=', 'NewValue')
            ->build();

        $rows = $this->executeOnPostgres($check);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['id']); // @phpstan-ignore cast.int
    }

    public function testFullOuterJoin(): void
    {
        $this->trackPostgresTable('left_side');
        $this->trackPostgresTable('right_side');
        $this->postgresStatement('DROP TABLE IF EXISTS "left_side" CASCADE');
        $this->postgresStatement('DROP TABLE IF EXISTS "right_side" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "left_side" (
                "id" INT PRIMARY KEY,
                "label" TEXT NOT NULL
            )
        ');
        $this->postgresStatement('
            CREATE TABLE "right_side" (
                "id" INT PRIMARY KEY,
                "label" TEXT NOT NULL
            )
        ');
        $this->postgresStatement("
            INSERT INTO \"left_side\" (\"id\", \"label\") VALUES (1, 'a'), (2, 'b')
        ");
        $this->postgresStatement("
            INSERT INTO \"right_side\" (\"id\", \"label\") VALUES (2, 'b'), (3, 'c')
        ");

        $result = (new Builder())
            ->from('left_side', 'l')
            ->selectRaw('"l"."id" AS "left_id"')
            ->selectRaw('"r"."id" AS "right_id"')
            ->fullOuterJoin('right_side', 'l.id', 'r.id', '=', 'r')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(3, $rows);

        $leftIds = array_map(
            static fn (array $r): ?int => $r['left_id'] === null ? null : (int) $r['left_id'], // @phpstan-ignore cast.int
            $rows,
        );
        $rightIds = array_map(
            static fn (array $r): ?int => $r['right_id'] === null ? null : (int) $r['right_id'], // @phpstan-ignore cast.int
            $rows,
        );

        $this->assertContains(1, $leftIds);
        $this->assertContains(2, $leftIds);
        $this->assertContains(null, $leftIds);

        $this->assertContains(2, $rightIds);
        $this->assertContains(3, $rightIds);
        $this->assertContains(null, $rightIds);
    }

    public function testTableSampleBernoulli(): void
    {
        $this->trackPostgresTable('samples');
        $this->postgresStatement('DROP TABLE IF EXISTS "samples" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "samples" (
                "id" SERIAL PRIMARY KEY,
                "value" INT NOT NULL
            )
        ');
        $this->postgresStatement('
            INSERT INTO "samples" ("value")
            SELECT generate_series(1, 100)
        ');

        $result = (new Builder())
            ->from('samples')
            ->select(['id'])
            ->tablesample(10, 'BERNOULLI')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $count = count($rows);
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertLessThanOrEqual(30, $count);
    }

    public function testFullTextSearch(): void
    {
        $this->trackPostgresTable('docs');
        $this->postgresStatement('DROP TABLE IF EXISTS "docs" CASCADE');
        $this->postgresStatement('
            CREATE TABLE "docs" (
                "id" SERIAL PRIMARY KEY,
                "body" TEXT NOT NULL
            )
        ');
        $this->postgresStatement("
            CREATE INDEX \"docs_body_fts_idx\" ON \"docs\"
            USING GIN (to_tsvector('english', \"body\"))
        ");
        $this->postgresStatement("
            INSERT INTO \"docs\" (\"body\") VALUES
            ('The quick brown fox jumps over the lazy dog'),
            ('PostgreSQL full text search is powerful'),
            ('Completely unrelated content about cooking')
        ");

        $result = (new Builder())
            ->from('docs')
            ->select(['id'])
            ->filterSearch('body', 'postgresql search')
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['id']); // @phpstan-ignore cast.int
    }

    public function testForUpdateOfSpecificTable(): void
    {
        $pdo = $this->connectPostgres();
        $pdo->beginTransaction();

        try {
            $result = (new Builder())
                ->from('users')
                ->select(['id', 'name'])
                ->filter([Query::equal('city', ['New York'])])
                ->forUpdateOf('users')
                ->sortAsc('id')
                ->build();

            $rows = $this->executeOnPostgres($result);

            $this->assertStringContainsString('FOR UPDATE OF "users"', $result->query);
            $this->assertCount(2, $rows);
            $this->assertSame('Alice', $rows[0]['name']);
            $this->assertSame('Charlie', $rows[1]['name']);
        } finally {
            $pdo->rollBack();
        }
    }

    public function testCountWhenCompleted(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('"status" = ?', 'completed_count', 'completed')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['completed_count']); // @phpstan-ignore cast.int
    }

    public function testSumWhenCompleted(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->sumWhen('amount', '"status" = ?', 'completed_total', 'completed')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertCount(1, $rows);
        $this->assertSame('279.96', (string) $rows[0]['completed_total']); // @phpstan-ignore cast.string
    }

    public function testGroupByRollup(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['status'])
            ->count('*', 'total')
            ->groupBy(['status'])
            ->withRollup()
            ->sortAsc('status')
            ->build();

        $rows = $this->executeOnPostgres($result);

        $this->assertStringContainsString('GROUP BY ROLLUP', $result->query);
        $this->assertCount(4, $rows);

        $grandTotal = null;
        foreach ($rows as $row) {
            if ($row['status'] === null) {
                $grandTotal = (int) $row['total']; // @phpstan-ignore cast.int
            }
        }
        $this->assertSame(7, $grandTotal);
    }
}
