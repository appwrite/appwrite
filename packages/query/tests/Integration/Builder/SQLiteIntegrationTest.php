<?php

namespace Tests\Integration\Builder;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\SQLite as Builder;
use Utopia\Query\Query;

class SQLiteIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->sqliteStatement('
            CREATE TABLE `users` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `age` INTEGER NOT NULL DEFAULT 0,
                `city` VARCHAR(100) DEFAULT NULL,
                `active` INTEGER NOT NULL DEFAULT 1,
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->sqliteStatement('
            CREATE TABLE `orders` (
                `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                `user_id` INTEGER NOT NULL REFERENCES `users`(`id`),
                `product` VARCHAR(255) NOT NULL,
                `amount` REAL NOT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT \'pending\',
                `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->sqliteStatement("
            INSERT INTO `users` (`name`, `email`, `age`, `city`, `active`) VALUES
            ('Alice', 'alice@example.com', 30, 'New York', 1),
            ('Bob', 'bob@example.com', 25, 'London', 1),
            ('Charlie', 'charlie@example.com', 35, 'New York', 0),
            ('Diana', 'diana@example.com', 28, 'Paris', 1),
            ('Eve', 'eve@example.com', 22, 'London', 1)
        ");

        $this->sqliteStatement("
            INSERT INTO `orders` (`user_id`, `product`, `amount`, `status`) VALUES
            (1, 'Widget', 29.99, 'completed'),
            (1, 'Gadget', 49.99, 'completed'),
            (2, 'Widget', 29.99, 'pending'),
            (3, 'Gizmo', 99.99, 'completed'),
            (4, 'Widget', 29.99, 'cancelled'),
            (4, 'Gadget', 49.99, 'pending'),
            (5, 'Gizmo', 99.99, 'completed')
        ");
    }

    public function testSelectWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('city', ['New York'])])
            ->build();

        $rows = $this->executeOnSqlite($result);

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

        $rows = $this->executeOnSqlite($result);

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

        $rows = $this->executeOnSqlite($result);

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

        $rows = $this->executeOnSqlite($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Diana', $rows[0]['name']);
    }

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Frank', 'email' => 'frank@example.com', 'age' => 40, 'city' => 'Berlin', 'active' => true])
            ->insert();

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'city'])
            ->filter([Query::equal('email', ['frank@example.com'])])
            ->build();

        $rows = $this->executeOnSqlite($check);

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

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name'])
            ->filter([Query::equal('city', ['Tokyo'])])
            ->sortAsc('name')
            ->build();

        $rows = $this->executeOnSqlite($check);

        $this->assertCount(2, $rows);
        $this->assertSame('Grace', $rows[0]['name']);
        $this->assertSame('Hank', $rows[1]['name']);
    }

    public function testUpdateWithWhere(): void
    {
        $result = (new Builder())
            ->from('users')
            ->set(['city' => 'San Francisco'])
            ->filter([Query::equal('name', ['Alice'])])
            ->update();

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->select(['city'])
            ->filter([Query::equal('name', ['Alice'])])
            ->build();

        $rows = $this->executeOnSqlite($check);

        $this->assertCount(1, $rows);
        $this->assertSame('San Francisco', $rows[0]['city']);
    }

    public function testDeleteWithWhere(): void
    {
        $this->sqliteStatement('DELETE FROM `orders` WHERE `user_id` = 5');

        $result = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Eve'])])
            ->delete();

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->filter([Query::equal('name', ['Eve'])])
            ->build();

        $rows = $this->executeOnSqlite($check);

        $this->assertCount(0, $rows);
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

        $rows = $this->executeOnSqlite($result);

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

        $this->assertStringNotContainsString('(SELECT', $result->query);

        $rows = $this->executeOnSqlite($result);

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

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'age', 'city'])
            ->filter([Query::equal('email', ['alice@example.com'])])
            ->build();

        $rows = $this->executeOnSqlite($check);

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

        $this->executeOnSqlite($result);

        $check = (new Builder())
            ->from('users')
            ->select(['name', 'age'])
            ->filter([Query::equal('email', ['alice@example.com'])])
            ->build();

        $rows = $this->executeOnSqlite($check);

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

        $rows = $this->executeOnSqlite($result);

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

        $rows = $this->executeOnSqlite($result);

        $this->assertGreaterThan(0, count($rows));
        $this->assertArrayHasKey('rn', $rows[0]);

        $user1Rows = array_filter($rows, fn ($r) => (int) $r['user_id'] === 1); // @phpstan-ignore cast.int
        $user1Rows = array_values($user1Rows);
        $this->assertSame(1, (int) $user1Rows[0]['rn']); // @phpstan-ignore cast.int
        $this->assertSame(2, (int) $user1Rows[1]['rn']); // @phpstan-ignore cast.int
    }

    public function testRecursiveCte(): void
    {
        $seed = (new Builder())
            ->fromNone()
            ->selectRaw('1');

        $step = (new Builder())
            ->from('t')
            ->selectRaw('`n` + 1')
            ->filter([Query::lessThan('n', 5)]);

        $result = (new Builder())
            ->withRecursiveSeedStep('t', $seed, $step, ['n'])
            ->from('t')
            ->select(['n'])
            ->sortAsc('n')
            ->build();

        $rows = $this->executeOnSqlite($result);

        $values = array_map(static fn (array $row): int => (int) $row['n'], $rows); // @phpstan-ignore cast.int
        $this->assertSame([1, 2, 3, 4, 5], $values);
    }
}
