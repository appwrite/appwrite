<?php

namespace Tests\Integration\Builder;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\MariaDB as Builder;
use Utopia\Query\Query;

class MariaDBIntegrationTest extends IntegrationTestCase
{
    private Builder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new Builder();
        $pdo = $this->connectMariadb();

        $this->trackMariadbTable('users');
        $this->trackMariadbTable('orders');

        $this->mariadbStatement('DROP TABLE IF EXISTS `orders`');
        $this->mariadbStatement('DROP TABLE IF EXISTS `users`');

        $this->mariadbStatement('
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

        $this->mariadbStatement('
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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Alice', $rows[1]['name']);
        $this->assertSame('Diana', $rows[2]['name']);
    }

    public function testSelectWithOffset(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name'])
            ->sortAsc('name')
            ->limit(2)
            ->offset(2)
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Diana', $rows[1]['name']);
    }

    public function testSelectWithJoin(): void
    {
        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name', 'o.product', 'o.amount'])
            ->join('orders', 'u.id', 'o.user_id', '=', 'o')
            ->filter([Query::equal('o.status', ['completed'])])
            ->build();

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithRightJoin(): void
    {
        $result = $this->fresh()
            ->from('orders', 'o')
            ->select(['u.name', 'o.product'])
            ->rightJoin('users', 'o.user_id', 'u.id', '=', 'u')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithCrossJoin(): void
    {
        $this->mariadbStatement('CREATE TEMPORARY TABLE `labels` (`label` VARCHAR(10) NOT NULL)');
        $this->mariadbStatement("INSERT INTO `labels` (`label`) VALUES ('X'), ('Y')");

        $result = $this->fresh()
            ->from('users', 'u')
            ->select(['u.name', 'l.label'])
            ->crossJoin('labels', 'l')
            ->filter([Query::equal('u.city', ['Paris'])])
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(2, $rows);
        $labels = array_column($rows, 'label');
        $this->assertContains('X', $labels);
        $this->assertContains('Y', $labels);
    }

    public function testInsertSingleRow(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Frank', 'email' => 'frank@example.com', 'age' => 40, 'city' => 'Berlin', 'active' => 1])
            ->insert();

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
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

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
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

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
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
        $this->mariadbStatement('DELETE FROM `orders` WHERE `user_id` = 3');

        $result = $this->fresh()
            ->from('users')
            ->filter([Query::equal('name', ['Charlie'])])
            ->delete();

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
            $this->fresh()
                ->from('users')
                ->select(['name'])
                ->filter([Query::equal('name', ['Charlie'])])
                ->build()
        );

        $this->assertCount(0, $rows);
    }

    public function testSelectWithGroupBy(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id'])
            ->count('*', 'order_count')
            ->groupBy(['user_id'])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(4, $rows);
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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(4, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithUnionAll(): void
    {
        $result = $this->fresh()
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['New York'])])
            ->unionAll(
                (new Builder())
                    ->from('users')
                    ->select(['name'])
                    ->filter([Query::equal('city', ['New York'])])
            )
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(4, $rows);
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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

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

        $emptyRows = $this->executeOnMariadb($emptyResult);

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

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(30, (float) $row['total']); // @phpstan-ignore cast.double
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

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(5, $rows);
        $values = array_map(fn (array $row): int => (int) $row['n'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([1, 2, 3, 4, 5], $values);
    }

    public function testUpsertOnDuplicateKeyUpdate(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 31, 'city' => 'New York', 'active' => 1])
            ->onConflict(['email'], ['age'])
            ->upsert();

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
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

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('rn', $row);
            $this->assertGreaterThanOrEqual(1, (int) $row['rn']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithRankWindowFunction(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id', 'amount'])
            ->selectWindow('RANK()', 'rnk', ['user_id'], ['-amount'])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('rnk', $row);
            $this->assertGreaterThanOrEqual(1, (int) $row['rnk']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithAggregateWindow(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->select(['user_id', 'amount'])
            ->selectWindow('SUM(`amount`)', 'running_total', ['user_id'], ['id'])
            ->sortAsc('user_id')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('running_total', $row);
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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testSelectForUpdate(): void
    {
        $pdo = $this->connectMariadb();
        $pdo->beginTransaction();

        try {
            $result = $this->fresh()
                ->from('users')
                ->select(['name', 'age'])
                ->filter([Query::equal('name', ['Alice'])])
                ->forUpdate()
                ->build();

            $rows = $this->executeOnMariadb($result);

            $this->assertCount(1, $rows);
            $this->assertSame('Alice', $rows[0]['name']);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function testInsertWithReturning(): void
    {
        $result = $this->fresh()
            ->into('users')
            ->set(['name' => 'Gina', 'email' => 'gina@example.com', 'age' => 27, 'city' => 'Madrid', 'active' => 1])
            ->returning(['id', 'name'])
            ->insert();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Gina', $rows[0]['name']);
        $this->assertArrayHasKey('id', $rows[0]);
        $this->assertGreaterThan(0, (int) $rows[0]['id']); // @phpstan-ignore cast.int

        $verify = $this->executeOnMariadb(
            $this->fresh()
                ->from('users')
                ->select(['name', 'email'])
                ->filter([Query::equal('id', [(int) $rows[0]['id']])]) // @phpstan-ignore cast.int
                ->build()
        );

        $this->assertCount(1, $verify);
        $this->assertSame('gina@example.com', $verify[0]['email']);
    }

    public function testSequences(): void
    {
        $this->trackMariadbTable('seq_user_id');

        $this->mariadbStatement('DROP SEQUENCE IF EXISTS `seq_user_id`');
        $this->mariadbStatement('CREATE SEQUENCE `seq_user_id` START WITH 1000 INCREMENT BY 1');

        $source = (new Builder())
            ->fromNone()
            ->nextVal('seq_user_id')
            ->selectRaw('?', ['SeqUser'])
            ->selectRaw('?', ['seq@example.com'])
            ->selectRaw('?', [21])
            ->selectRaw('?', ['Lisbon'])
            ->selectRaw('?', [1]);

        $result = $this->fresh()
            ->into('users')
            ->fromSelect(['id', 'name', 'email', 'age', 'city', 'active'], $source)
            ->insertSelect();

        $this->executeOnMariadb($result);

        $rows = $this->executeOnMariadb(
            $this->fresh()
                ->from('users')
                ->select(['id'])
                ->filter([Query::equal('email', ['seq@example.com'])])
                ->build()
        );

        $this->assertCount(1, $rows);
        $this->assertSame(1000, (int) $rows[0]['id']); // @phpstan-ignore cast.int

        $pdo = $this->connectMariadb();
        $stmt = $pdo->query('SELECT NEXTVAL(`seq_user_id`) AS v');
        $this->assertNotFalse($stmt);
        /** @var array<string, mixed> $next */
        $next = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1001, (int) $next['v']); // @phpstan-ignore cast.int
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

        $rows = $this->executeOnMariadb($result);

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

    public function testCountWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->countWhen('`status` = ?', 'completed_count', 'completed')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['completed_count']); // @phpstan-ignore cast.int
    }

    public function testSumWhen(): void
    {
        $result = $this->fresh()
            ->from('orders')
            ->sumWhen('amount', '`status` = ?', 'completed_total', 'completed')
            ->build();

        $rows = $this->executeOnMariadb($result);

        $this->assertCount(1, $rows);
        $this->assertSame('99.97', (string) $rows[0]['completed_total']); // @phpstan-ignore cast.string
    }

    private function createJsonDocsTable(): void
    {
        $this->trackMariadbTable('json_docs');
        $this->mariadbStatement('DROP TABLE IF EXISTS `json_docs`');
        $this->mariadbStatement('
            CREATE TABLE `json_docs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `tags` JSON NOT NULL,
                `metadata` JSON NOT NULL
            ) ENGINE=InnoDB
        ');

        $pdo = $this->connectMariadb();
        $stmt = $pdo->prepare('INSERT INTO `json_docs` (`tags`, `metadata`) VALUES (?, ?), (?, ?), (?, ?), (?, ?)');
        $stmt->execute([
            '["php", "mariadb"]', '{"level": 3, "active": true}',
            '["go", "mariadb"]', '{"level": 7, "active": true}',
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

        $rows = $this->executeOnMariadb($result);

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

        $rows = $this->executeOnMariadb($result);

        $ids = array_map(fn (array $row): int => (int) $row['id'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([2, 3], $ids);
    }

    public function testJsonSetPath(): void
    {
        $this->createJsonDocsTable();

        $update = $this->fresh()
            ->from('json_docs')
            ->setJsonPath('metadata', '$.level', 42)
            ->filter([Query::equal('id', [1])])
            ->update();

        $this->executeOnMariadb($update);

        $pdo = $this->connectMariadb();
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

    private function createPlacesTable(): void
    {
        $this->trackMariadbTable('places');
        $this->mariadbStatement('DROP TABLE IF EXISTS `places`');
        $this->mariadbStatement('
            CREATE TABLE `places` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `location` POINT NOT NULL,
                SPATIAL INDEX `sp_location` (`location`)
            ) ENGINE=InnoDB
        ');

        $pdo = $this->connectMariadb();
        $stmt = $pdo->prepare(
            'INSERT INTO `places` (`name`, `location`) VALUES '
            . '(?, ST_GeomFromText(?, 4326)), '
            . '(?, ST_GeomFromText(?, 4326)), '
            . '(?, ST_GeomFromText(?, 4326)), '
            . '(?, ST_GeomFromText(?, 4326))'
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

        $rows = $this->executeOnMariadb($result);

        $names = array_column($rows, 'name');
        $this->assertSame(['Inside1', 'Inside2'], $names);
    }
}
