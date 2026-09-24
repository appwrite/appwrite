<?php

namespace Tests\Integration\Builder;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\MySQL as Builder;
use Utopia\Query\Query;

class MySQLIntegrationTest extends IntegrationTestCase
{
    private Builder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new Builder();
        $pdo = $this->connectMysql();

        $this->trackMysqlTable('users');
        $this->trackMysqlTable('orders');

        $this->mysqlStatement('DROP TABLE IF EXISTS `orders`');
        $this->mysqlStatement('DROP TABLE IF EXISTS `users`');

        $this->mysqlStatement('
            CREATE TABLE `users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(150) NOT NULL UNIQUE,
                `age` INT NOT NULL DEFAULT 0,
                `city` VARCHAR(100) NOT NULL DEFAULT \'\',
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB
        ');

        $this->mysqlStatement('
            CREATE TABLE `orders` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `product` VARCHAR(100) NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
            ) ENGINE=InnoDB
        ');

        $stmt = $pdo->prepare('
            INSERT INTO `users` (`name`, `email`, `age`, `city`, `active`) VALUES
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?),
            (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            'Alice', 'alice@example.com', 30, 'New York', 1,
            'Bob', 'bob@example.com', 25, 'London', 1,
            'Charlie', 'charlie@example.com', 35, 'New York', 0,
            'Diana', 'diana@example.com', 28, 'Paris', 1,
            'Eve', 'eve@example.com', 22, 'London', 1,
        ]);

        $stmt = $pdo->prepare('
            INSERT INTO `orders` (`user_id`, `product`, `amount`, `status`) VALUES
            (?, ?, ?, ?),
            (?, ?, ?, ?),
            (?, ?, ?, ?),
            (?, ?, ?, ?),
            (?, ?, ?, ?),
            (?, ?, ?, ?)
        ');
        $stmt->execute([
            1, 'Widget', 29.99, 'completed',
            1, 'Gadget', 49.99, 'completed',
            2, 'Widget', 29.99, 'pending',
            3, 'Gizmo', 19.99, 'completed',
            4, 'Widget', 29.99, 'cancelled',
            4, 'Gadget', 49.99, 'pending',
        ]);
    }

    private function fresh(): Builder
    {
        return $this->builder->reset();
    }

    public function testSelectWithWhere(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('city', ['New York'])])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testSelectWithOrderByAndLimit(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name', 'age'])
            ->sortDesc('age')
            ->limit(3)
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Alice', $rows[1]['name']);
        $this->assertSame('Diana', $rows[2]['name']);
    }

    public function testSelectWithJoin(): void
    {
        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name', 'o.product', 'o.amount'])
            ->join('orders', 'u.id', 'o.user_id', '=', 'o')
            ->filter([Query::equal('o.status', ['completed'])])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(3, $rows);
        $products = array_column($rows, 'product');
        $this->assertContains('Widget', $products);
        $this->assertContains('Gadget', $products);
        $this->assertContains('Gizmo', $products);
    }

    public function testSelectWithLeftJoin(): void
    {
        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name', 'o.product'])
            ->leftJoin('orders', 'u.id', 'o.user_id', '=', 'o')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertNotEmpty($rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Eve', $names);
    }

    public function testInsertSingleRow(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Frank', 'email' => 'frank@example.com', 'age' => 40, 'city' => 'Berlin', 'active' => 1])
            ->insert();

        $this->executeOnMysql($result);

        $rows = $this->executeOnMysql(
            $this->fresh()
                ->from('users')
                ->select(['name'])
                ->filter([Query::equal('email', ['frank@example.com'])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Frank', $rows[0]['name']);
    }

    public function testInsertMultipleRows(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Grace', 'email' => 'grace@example.com', 'age' => 33, 'city' => 'Tokyo', 'active' => 1])
            ->set(['name' => 'Hank', 'email' => 'hank@example.com', 'age' => 45, 'city' => 'Tokyo', 'active' => 0])
            ->insert();

        $this->executeOnMysql($result);

        $rows = $this->executeOnMysql(
            $this->fresh()
                ->from('users')
                ->select(['name'])
                ->filter([Query::equal('city', ['Tokyo'])])
                ->build()
        );

        $this->assertCount(2, $rows);
    }

    public function testUpdateWithWhere(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->set(['active' => 0])
            ->filter([Query::equal('name', ['Bob'])])
            ->update();

        $this->executeOnMysql($result);

        $rows = $this->executeOnMysql(
            $this->fresh()
                ->from('users')
                ->select(['active'])
                ->filter([Query::equal('name', ['Bob'])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]['active']); // @phpstan-ignore cast.int
    }

    public function testDeleteWithWhere(): void
    {
        $this->mysqlStatement('DELETE FROM `orders` WHERE `user_id` = 3');

        $result = $this->fresh()
            ->from('users')
            ->filter([Query::equal('name', ['Charlie'])])
            ->delete();

        $this->executeOnMysql($result);

        $rows = $this->executeOnMysql(
            $this->fresh()
                ->from('users')
                ->select(['name'])
                ->filter([Query::equal('name', ['Charlie'])])
                ->build()
        );

        $this->assertCount(0, $rows);
    }

    public function testSelectWithGroupByAndHaving(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id'])
            ->count('*', 'order_count')
            ->groupBy(['user_id'])
            ->having([Query::greaterThan('order_count', 1)])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(1, (int) $row['order_count']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithUnion(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['New York'])])
            ->union(
                (new Builder())
                    ->from('users')
                    ->select(['name'])
                    ->filter([Query::equal('city', ['London'])])
            )
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(4, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('age', Operator::LessThan, 25, 'young')
            ->whenRaw('`age` BETWEEN 25 AND 30', 'mid')
            ->else('senior')
            ->alias('age_group');

        $result = $this->fresh()
            ->from('users')
            ->select(['name'])
            ->selectCase($case)
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(5, $rows);
        $map = array_column($rows, 'age_group', 'name');
        $this->assertSame('mid', $map['Alice']);
        $this->assertSame('mid', $map['Bob']);
        $this->assertSame('senior', $map['Charlie']);
        $this->assertSame('mid', $map['Diana']);
        $this->assertSame('young', $map['Eve']);
    }

    public function testSelectWithWhereInSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->filter([Query::equal('status', ['completed'])]);

        $result = $this->fresh()
            ->from('users')
            ->select(['name'])
            ->filterWhereIn('id', $subquery)
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testSelectWithExistsSubquery(): void
    {
        $subquery = (new Builder())
            ->from('orders', 'o')
            ->select('1')
            ->filter([Query::equal('o.status', ['completed'])]);

        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name'])
            ->filterExists($subquery)
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(5, $rows);

        $noMatchSubquery = (new Builder())
            ->from('orders', 'o')
            ->select('1')
            ->filter([Query::equal('o.status', ['refunded'])]);

        $emptyResult = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name'])
            ->filterExists($noMatchSubquery)
            ->build();

        $emptyRows = $this->executeOnMysql($emptyResult);

        $this->assertCount(0, $emptyRows);
    }

    public function testSelectWithCte(): void
    {
        $cteQuery = (new Builder())
            ->from('orders')
            ->select(['user_id'])
            ->sum('amount', 'total')
            ->groupBy(['user_id']);

        $result = $this->fresh()
            ->with('user_totals', $cteQuery)
            ->from('user_totals')
            ->select(['user_id', 'total'])
            ->filter([Query::greaterThan('total', 30)])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(30, (float) $row['total']); // @phpstan-ignore cast.double
        }
    }

    public function testUpsertOnDuplicateKeyUpdate(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 31, 'city' => 'New York', 'active' => 1])
            ->onConflict(['email'], ['age'])
            ->upsert();

        $this->executeOnMysql($result);

        $rows = $this->executeOnMysql(
            $this->fresh()
                ->from('users')
                ->select(['age'])
                ->filter([Query::equal('email', ['alice@example.com'])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(31, (int) $rows[0]['age']); // @phpstan-ignore cast.int
    }

    public function testSelectWithWindowFunction(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id', 'product', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-amount'])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('rn', $row);
            $this->assertGreaterThanOrEqual(1, (int) $row['rn']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithDistinct(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['product'])
            ->distinct()
            ->sortAsc('product')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(3, $rows);
        $products = array_column($rows, 'product');
        $this->assertSame(['Gadget', 'Gizmo', 'Widget'], $products);
    }

    public function testSelectWithBetween(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name', 'age'])
            ->filter([Query::between('age', 25, 30)])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(25, (int) $row['age']); // @phpstan-ignore cast.int
            $this->assertLessThanOrEqual(30, (int) $row['age']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithStartsWith(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::startsWith('name', 'Al')])
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testSelectForUpdate(): void
    {
        $pdo = $this->connectMysql();
        $pdo->beginTransaction();

        try {
            $result = $this->fresh()
                ->from('users')
                ->select(['name', 'age'])
                ->filter([Query::equal('name', ['Alice'])])
                ->forUpdate()
                ->build();

            $rows = $this->executeOnMysql($result);

            $this->assertCount(1, $rows);
            $this->assertSame('Alice', $rows[0]['name']);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function testRecursiveCte(): void
    {
        $seed = (new Builder())
            ->from()
            ->select('1 AS n');

        $step = (new Builder())
            ->from('t')
            ->select('n + 1')
            ->filter([Query::lessThan('n', 5)]);

        $result = $this->fresh()
            ->withRecursiveSeedStep('t', $seed, $step, ['n'])
            ->from('t')
            ->select(['n'])
            ->sortAsc('n')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(5, $rows);
        $values = array_map(fn (array $row): int => (int) $row['n'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([1, 2, 3, 4, 5], $values);
    }

    private function createJsonDocsTable(): void
    {
        $this->trackMysqlTable('json_docs');
        $this->mysqlStatement('DROP TABLE IF EXISTS `json_docs`');
        $this->mysqlStatement('
            CREATE TABLE `json_docs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `tags` JSON NOT NULL,
                `metadata` JSON NOT NULL
            ) ENGINE=InnoDB
        ');

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare('INSERT INTO `json_docs` (`tags`, `metadata`) VALUES (?, ?), (?, ?), (?, ?), (?, ?)');
        $stmt->execute([
            '["php", "mysql"]', '{"level": 3, "active": true}',
            '["go", "mysql"]', '{"level": 7, "active": true}',
            '["rust"]', '{"level": 10, "active": false}',
            '["php", "rust"]', '{"level": 5, "active": true}',
        ]);
    }

    public function testJsonFilterContains(): void
    {
        $this->createJsonDocsTable();

        $result = $this->fresh()
            ->from('json_docs')
            ->select(['id'])
            ->filterJsonContains('tags', 'php')
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnMysql($result);

        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([1, 4], $ids);
    }

    public function testJsonFilterPath(): void
    {
        $this->createJsonDocsTable();

        $result = $this->fresh()
            ->from('json_docs')
            ->select(['id'])
            ->filterJsonPath('metadata', 'level', '>', 5)
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnMysql($result);

        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([2, 3], $ids);
    }

    public function testJsonSetAppend(): void
    {
        $this->createJsonDocsTable();

        $update = $this->fresh()
            ->from('json_docs')
            ->setJsonAppend('tags', ['added'])
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->executeOnMysql($update);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare('SELECT `tags` FROM `json_docs` WHERE `id` = 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        /** @var string $tagsJson */
        $tagsJson = $row['tags'];
        $tags = \json_decode($tagsJson, true);
        $this->assertIsArray($tags);
        $this->assertContains('added', $tags);
        $this->assertContains('php', $tags);
    }

    public function testJsonSetRemove(): void
    {
        $this->createJsonDocsTable();

        $update = $this->fresh()
            ->from('json_docs')
            ->setJsonRemove('tags', 'mysql')
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->executeOnMysql($update);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare('SELECT `tags` FROM `json_docs` WHERE `id` = 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        /** @var string $tagsJson */
        $tagsJson = $row['tags'];
        $tags = \json_decode($tagsJson, true);
        $this->assertIsArray($tags);
        $this->assertNotContains('mysql', $tags);
        $this->assertContains('php', $tags);
    }

    public function testJsonSetPath(): void
    {
        $this->createJsonDocsTable();

        $update = $this->fresh()
            ->from('json_docs')
            ->setJsonPath('metadata', '$.level', 42)
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->executeOnMysql($update);

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare('SELECT `metadata` FROM `json_docs` WHERE `id` = 1');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        \assert(\is_array($row));

        /** @var string $metadataJson */
        $metadataJson = $row['metadata'];
        $metadata = \json_decode($metadataJson, true);
        $this->assertIsArray($metadata);
        $this->assertSame(42, $metadata['level']);
        $this->assertTrue($metadata['active']);
    }

    public function testHintUsesIndex(): void
    {
        $this->mysqlStatement('CREATE INDEX `idx_users_age` ON `users`(`age`)');

        $result = $this->fresh()
            ->from('users')
            ->select(['name', 'age'])
            ->hint('INDEX(`users` `idx_users_age`)')
            ->filter([Query::greaterThan('age', 20)])
            ->sortAsc('age')
            ->build();

        $this->assertStringContainsString('/*+ INDEX(`users` `idx_users_age`) */', $result->query);

        $rows = $this->executeOnMysql($result);

        $this->assertCount(5, $rows);
        $this->assertSame('Eve', $rows[0]['name']);
    }

    public function testLateralJoin(): void
    {
        $topOrder = (new Builder())
            ->from('orders')
            ->select(['product', 'amount'])
            ->whereColumn('user_id', '=', 'u.id')
            ->sortDesc('amount')
            ->limit(1);

        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name', 'top_order.product', 'top_order.amount'])
            ->joinLateral($topOrder, 'top_order')
            ->sortAsc('u.name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $byName = [];
        foreach ($rows as $row) {
            /** @var string $name */
            $name = $row['name'];
            $byName[$name] = $row;
        }

        $this->assertCount(4, $rows);
        $this->assertSame('Gadget', $byName['Alice']['product']);
        $this->assertSame('49.99', (string) $byName['Alice']['amount']); // @phpstan-ignore cast.string
        $this->assertSame('Widget', $byName['Bob']['product']);
        $this->assertSame('Gizmo', $byName['Charlie']['product']);
        $this->assertSame('Gadget', $byName['Diana']['product']);
    }

    public function testGroupConcat(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id'])
            ->groupConcat('product', ',', 'products', ['product'])
            ->groupBy(['user_id'])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnMysql($result);

        $map = [];
        foreach ($rows as $row) {
            /** @var int $userId */
            $userId = (int) $row['user_id']; // @phpstan-ignore cast.int
            /** @var string $products */
            $products = (string) $row['products']; // @phpstan-ignore cast.string
            $map[$userId] = $products;
        }

        $this->assertSame('Gadget,Widget', $map[1]);
        $this->assertSame('Widget', $map[2]);
        $this->assertSame('Gizmo', $map[3]);
        $this->assertSame('Gadget,Widget', $map[4]);
    }

    public function testGroupByWithRollup(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['status'])
            ->count('*', 'total')
            ->groupBy(['status'])
            ->withRollup()
            ->build();

        $rows = $this->executeOnMysql($result);

        $statuses = array_column($rows, 'status');
        $this->assertContains(null, $statuses, 'Expected a NULL rollup row');

        $grandTotal = 0;
        foreach ($rows as $row) {
            if ($row['status'] === null) {
                $grandTotal = (int) $row['total']; // @phpstan-ignore cast.int
            }
        }
        $this->assertSame(6, $grandTotal);
    }

    public function testCountWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->countWhen('`status` = ?', 'completed_count', 'completed')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['completed_count']); // @phpstan-ignore cast.int
    }

    public function testSumWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->sumWhen('amount', '`status` = ?', 'completed_total', 'completed')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(1, $rows);
        $this->assertSame('99.97', (string) $rows[0]['completed_total']); // @phpstan-ignore cast.string
    }

    public function testAvgWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->avgWhen('amount', '`status` = ?', 'completed_avg', 'completed')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(33.32, (float) $rows[0]['completed_avg'], 0.01); // @phpstan-ignore cast.double
    }

    public function testMaxWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->maxWhen('amount', '`status` = ?', 'completed_max', 'completed')
            ->build();

        $rows = $this->executeOnMysql($result);

        $this->assertCount(1, $rows);
        $this->assertSame('49.99', (string) $rows[0]['completed_max']); // @phpstan-ignore cast.string
    }

    private function createPlacesTable(): void
    {
        $this->trackMysqlTable('places');
        $this->mysqlStatement('DROP TABLE IF EXISTS `places`');
        $this->mysqlStatement('
            CREATE TABLE `places` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `location` POINT SRID 4326 NOT NULL,
                SPATIAL INDEX `sp_location` (`location`)
            ) ENGINE=InnoDB
        ');

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare(
            'INSERT INTO `places` (`name`, `location`) VALUES '
            . "(?, ST_GeomFromText(?, 4326, 'axis-order=long-lat')), "
            . "(?, ST_GeomFromText(?, 4326, 'axis-order=long-lat')), "
            . "(?, ST_GeomFromText(?, 4326, 'axis-order=long-lat')), "
            . "(?, ST_GeomFromText(?, 4326, 'axis-order=long-lat'))"
        );
        $stmt->execute([
            'Inside1', 'POINT(0.5 0.5)',
            'Inside2', 'POINT(0.2 0.8)',
            'Outside1', 'POINT(5 5)',
            'Outside2', 'POINT(-1 -1)',
        ]);
    }

    public function testSpatialIntersects(): void
    {
        $this->createPlacesTable();

        $polygon = [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]];

        $result = $this->fresh()
            ->from('places')
            ->select(['name'])
            ->filterIntersects('location', $polygon)
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $names = array_column($rows, 'name');
        $this->assertSame(['Inside1', 'Inside2'], $names);
    }

    public function testSpatialDistance(): void
    {
        $this->createPlacesTable();

        $result = $this->fresh()
            ->from('places')
            ->select(['name'])
            ->filterDistance('location', [0.0, 0.0], '<', 2.0)
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnMysql($result);

        $names = array_column($rows, 'name');
        $this->assertContains('Inside1', $names);
        $this->assertContains('Inside2', $names);
        $this->assertContains('Outside2', $names);
        $this->assertNotContains('Outside1', $names);
    }

    public function testFullTextSearch(): void
    {
        $this->trackMysqlTable('articles');
        $this->mysqlStatement('DROP TABLE IF EXISTS `articles`');
        $this->mysqlStatement('
            CREATE TABLE `articles` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(200) NOT NULL,
                `body` TEXT NOT NULL,
                FULLTEXT KEY `ft_body` (`body`)
            ) ENGINE=InnoDB
        ');

        $pdo = $this->connectMysql();
        $stmt = $pdo->prepare('INSERT INTO `articles` (`title`, `body`) VALUES (?, ?), (?, ?), (?, ?)');
        $stmt->execute([
            'Gardening', 'Statementting tomatoes in the garden is rewarding',
            'Cooking', 'A great recipe uses fresh tomatoes and basil',
            'Tech', 'Database internals and query optimization',
        ]);

        $result = $this->fresh()
            ->from('articles')
            ->select(['title'])
            ->filterSearch('body', 'tomatoes')
            ->sortAsc('title')
            ->build();

        $rows = $this->executeOnMysql($result);

        $titles = array_column($rows, 'title');
        $this->assertSame(['Cooking', 'Gardening'], $titles);
    }
}
