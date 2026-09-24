<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Builder\SQLite as SQLiteBuilder;
use Utopia\Query\Query;

class UnionsTest extends TestCase
{
    use AssertsBindingCount;

    public function testUnionWrapsEachArmInParensByDefault(): void
    {
        $other = (new MySQLBuilder())
            ->from('admins')
            ->filter([Query::equal('role', ['admin'])]);

        $result = (new MySQLBuilder())
            ->from('users')
            ->filter([Query::equal('status', ['active'])])
            ->union($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            '(SELECT * FROM `users` WHERE `status` IN (?)) UNION (SELECT * FROM `admins` WHERE `role` IN (?))',
            $result->query,
        );
        $this->assertSame(['active', 'admin'], $result->bindings);
    }

    public function testUnionAllEmitsAllKeyword(): void
    {
        $other = (new MySQLBuilder())->from('archive');

        $result = (new MySQLBuilder())
            ->from('current')
            ->unionAll($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            '(SELECT * FROM `current`) UNION ALL (SELECT * FROM `archive`)',
            $result->query,
        );
    }

    public function testIntersectEmitsIntersectKeyword(): void
    {
        $other = (new PostgreSQLBuilder())->from('b');

        $result = (new PostgreSQLBuilder())
            ->from('a')
            ->intersect($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM "a") INTERSECT (SELECT * FROM "b")', $result->query);
    }

    public function testIntersectAllEmitsIntersectAllKeyword(): void
    {
        $other = (new PostgreSQLBuilder())->from('b');

        $result = (new PostgreSQLBuilder())
            ->from('a')
            ->intersectAll($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM "a") INTERSECT ALL (SELECT * FROM "b")', $result->query);
    }

    public function testExceptEmitsExceptKeyword(): void
    {
        $other = (new PostgreSQLBuilder())->from('b');

        $result = (new PostgreSQLBuilder())
            ->from('a')
            ->except($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM "a") EXCEPT (SELECT * FROM "b")', $result->query);
    }

    public function testExceptAllEmitsExceptAllKeyword(): void
    {
        $other = (new PostgreSQLBuilder())->from('b');

        $result = (new PostgreSQLBuilder())
            ->from('a')
            ->exceptAll($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM "a") EXCEPT ALL (SELECT * FROM "b")', $result->query);
    }

    public function testBindingsAppendInArmOrder(): void
    {
        $q2 = (new MySQLBuilder())
            ->from('t2')
            ->filter([Query::equal('year', [2023])]);
        $q3 = (new MySQLBuilder())
            ->from('t3')
            ->filter([Query::equal('year', [2022])]);

        $result = (new MySQLBuilder())
            ->from('t1')
            ->filter([Query::equal('year', [2024])])
            ->union($q2)
            ->unionAll($q3)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([2024, 2023, 2022], $result->bindings);
    }

    public function testSQLiteStripsParensFromUnionArms(): void
    {
        // SQLite's compound-SELECT parser rejects parenthesised members,
        // so the builder must emit bare SELECTs joined by UNION.
        $other = (new SQLiteBuilder())
            ->from('archived_users')
            ->select(['id', 'name']);

        $result = (new SQLiteBuilder())
            ->from('users')
            ->select(['id', 'name'])
            ->union($other)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT `id`, `name` FROM `users` UNION SELECT `id`, `name` FROM `archived_users`',
            $result->query,
        );
        $this->assertStringNotContainsString('(SELECT', $result->query);
    }

    public function testSQLiteStripsParensAcrossMultipleCompoundOps(): void
    {
        $q2 = (new SQLiteBuilder())->from('t2');
        $q3 = (new SQLiteBuilder())->from('t3');

        $result = (new SQLiteBuilder())
            ->from('t1')
            ->union($q2)
            ->unionAll($q3)
            ->build();

        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('(SELECT', $result->query);
        $this->assertSame('SELECT * FROM `t1` UNION SELECT * FROM `t2` UNION ALL SELECT * FROM `t3`', $result->query);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new MySQLBuilder();
        $other = (new MySQLBuilder())->from('t2');
        $returned = $builder->from('t1')->unionAll($other);

        $this->assertSame($builder, $returned);
    }
}
