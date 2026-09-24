<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Query;

class LateralJoinsTest extends TestCase
{
    use AssertsBindingCount;

    public function testJoinLateralEmitsJoinLateralAndOnTrueForPostgreSQL(): void
    {
        $sub = (new PostgreSQLBuilder())->from('orders')->select(['id']);

        $result = (new PostgreSQLBuilder())
            ->from('users')
            ->joinLateral($sub, 'o')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "users" JOIN LATERAL (SELECT "id" FROM "orders") AS "o" ON true', $result->query);
    }

    public function testLeftJoinLateralEmitsLeftJoinLateral(): void
    {
        $sub = (new PostgreSQLBuilder())->from('orders')->select(['id']);

        $result = (new PostgreSQLBuilder())
            ->from('users')
            ->leftJoinLateral($sub, 'o')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "users" LEFT JOIN LATERAL (SELECT "id" FROM "orders") AS "o" ON true', $result->query);
    }

    public function testJoinLateralWithLeftTypeEmitsLeftVariant(): void
    {
        $sub = (new PostgreSQLBuilder())->from('orders')->select(['id']);

        $result = (new PostgreSQLBuilder())
            ->from('users')
            ->joinLateral($sub, 'o', JoinType::Left)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "users" LEFT JOIN LATERAL (SELECT "id" FROM "orders") AS "o" ON true', $result->query);
    }

    public function testJoinLateralPreservesSubqueryBindingsInOrder(): void
    {
        $sub = (new PostgreSQLBuilder())
            ->from('orders')
            ->filter([Query::greaterThan('total', 100), Query::equal('status', ['shipped'])]);

        $result = (new PostgreSQLBuilder())
            ->from('users')
            ->joinLateral($sub, 'o')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([0 => 100, 1 => 'shipped'], $result->bindings);
    }

    public function testMySQLUsesBacktickQuotingForLateralAlias(): void
    {
        $sub = (new MySQLBuilder())->from('orders')->select(['id']);

        $result = (new MySQLBuilder())
            ->from('users')
            ->joinLateral($sub, 'o')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `users` JOIN LATERAL (SELECT `id` FROM `orders`) AS `o` ON true', $result->query);
    }
}
