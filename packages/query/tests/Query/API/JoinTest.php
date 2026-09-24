<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;

class JoinTest extends TestCase
{
    public function testJoin(): void
    {
        $query = Query::join('orders', 'users.id', 'orders.user_id');
        $this->assertSame(Method::Join, $query->getMethod());
        $this->assertSame('orders', $query->getAttribute());
        $this->assertSame(['users.id', '=', 'orders.user_id'], $query->getValues());
    }

    public function testJoinWithOperator(): void
    {
        $query = Query::join('orders', 'users.id', 'orders.user_id', '!=');
        $this->assertSame(['users.id', '!=', 'orders.user_id'], $query->getValues());
    }

    public function testLeftJoin(): void
    {
        $query = Query::leftJoin('profiles', 'users.id', 'profiles.user_id');
        $this->assertSame(Method::LeftJoin, $query->getMethod());
        $this->assertSame('profiles', $query->getAttribute());
        $this->assertSame(['users.id', '=', 'profiles.user_id'], $query->getValues());
    }

    public function testRightJoin(): void
    {
        $query = Query::rightJoin('orders', 'users.id', 'orders.user_id');
        $this->assertSame(Method::RightJoin, $query->getMethod());
        $this->assertSame('orders', $query->getAttribute());
    }

    public function testCrossJoin(): void
    {
        $query = Query::crossJoin('colors');
        $this->assertSame(Method::CrossJoin, $query->getMethod());
        $this->assertSame('colors', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testJoinMethodsAreJoin(): void
    {
        $this->assertTrue(Method::Join->isJoin());
        $this->assertTrue(Method::LeftJoin->isJoin());
        $this->assertTrue(Method::RightJoin->isJoin());
        $this->assertTrue(Method::CrossJoin->isJoin());
        $this->assertTrue(Method::FullOuterJoin->isJoin());
        $this->assertTrue(Method::NaturalJoin->isJoin());
        $this->assertFalse(Method::On->isJoin());
        $joinMethods = array_filter(Method::cases(), fn (Method $m) => $m->isJoin());
        $this->assertCount(6, $joinMethods);
    }

    public function testJoinWithEmptyTableName(): void
    {
        $query = Query::join('', 'left', 'right');
        $this->assertSame('', $query->getAttribute());
        $this->assertSame(['left', '=', 'right'], $query->getValues());
    }

    public function testJoinWithEmptyLeftColumn(): void
    {
        $query = Query::join('t', '', 'right');
        $this->assertSame(['', '=', 'right'], $query->getValues());
    }

    public function testJoinWithEmptyRightColumn(): void
    {
        $query = Query::join('t', 'left', '');
        $this->assertSame(['left', '=', ''], $query->getValues());
    }

    public function testJoinWithSpecialOperators(): void
    {
        $ops = ['!=', '<>', '<', '>', '<=', '>='];
        foreach ($ops as $op) {
            $query = Query::join('t', 'a', 'b', $op);
            $this->assertSame(['a', $op, 'b'], $query->getValues());
        }
    }

    public function testLeftJoinValues(): void
    {
        $query = Query::leftJoin('t', 'a.id', 'b.aid', '!=');
        $this->assertSame(['a.id', '!=', 'b.aid'], $query->getValues());
    }

    public function testRightJoinValues(): void
    {
        $query = Query::rightJoin('t', 'a.id', 'b.aid');
        $this->assertSame(['a.id', '=', 'b.aid'], $query->getValues());
    }

    public function testCrossJoinEmptyTableName(): void
    {
        $query = Query::crossJoin('');
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testJoinCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::join('orders', 'users.id', 'orders.uid');
        $sql = $query->compile($builder);
        $this->assertSame('JOIN `orders` ON `users`.`id` = `orders`.`uid`', $sql);
    }

    public function testLeftJoinCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::leftJoin('p', 'u.id', 'p.uid');
        $sql = $query->compile($builder);
        $this->assertSame('LEFT JOIN `p` ON `u`.`id` = `p`.`uid`', $sql);
    }

    public function testRightJoinCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::rightJoin('o', 'u.id', 'o.uid');
        $sql = $query->compile($builder);
        $this->assertSame('RIGHT JOIN `o` ON `u`.`id` = `o`.`uid`', $sql);
    }

    public function testCrossJoinCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::crossJoin('colors');
        $sql = $query->compile($builder);
        $this->assertSame('CROSS JOIN `colors`', $sql);
    }

    public function testJoinIsNotNested(): void
    {
        $query = Query::join('t', 'a', 'b');
        $this->assertFalse($query->isNested());
        $this->assertFalse($query->isNestedJoin());
    }

    public function testOn(): void
    {
        $query = Query::on('$id', 'customerId');
        $this->assertSame(Method::On, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame(['$id', '=', 'customerId'], $query->getValues());
    }

    public function testOnWithOperator(): void
    {
        $query = Query::on('a.id', 'b.aid', '!=');
        $this->assertSame(['a.id', '!=', 'b.aid'], $query->getValues());
    }

    public function testNestedLeftJoinWithoutAlias(): void
    {
        $query = Query::leftJoin('orders', [
            Query::on('$id', 'customerId'),
            Query::equal('ord.status', ['paid']),
        ]);

        $this->assertTrue($query->isNestedJoin());
        $this->assertSame('', $query->getJoinAlias());
        $on = $query->getJoinOnQueries();
        $this->assertCount(2, $on);
        $this->assertSame(Method::On, $on[0]->getMethod());
        $this->assertSame(Method::Equal, $on[1]->getMethod());
    }

    public function testNestedLeftJoinWithAlias(): void
    {
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::equal('ord.status', ['paid']),
        ]);

        $this->assertSame(Method::LeftJoin, $query->getMethod());
        $this->assertSame('orders', $query->getAttribute());
        $this->assertTrue($query->isNestedJoin());
        $this->assertSame('ord', $query->getJoinAlias());
        $this->assertCount(2, $query->getJoinOnQueries());
    }

    public function testNestedJoinAndRightJoinAndFullOuterJoin(): void
    {
        $on = [Query::on('users.id', 'orders.user_id')];

        $inner = Query::join('orders', 'ord', $on);
        $this->assertSame(Method::Join, $inner->getMethod());
        $this->assertTrue($inner->isNestedJoin());
        $this->assertSame('ord', $inner->getJoinAlias());

        $right = Query::rightJoin('orders', $on);
        $this->assertSame(Method::RightJoin, $right->getMethod());
        $this->assertTrue($right->isNestedJoin());
        $this->assertSame('', $right->getJoinAlias());

        $full = Query::fullOuterJoin('orders', 'ord', $on);
        $this->assertSame(Method::FullOuterJoin, $full->getMethod());
        $this->assertTrue($full->isNestedJoin());
        $this->assertSame('ord', $full->getJoinAlias());
    }

    public function testNestedJoinEmptyOnThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON requires at least one condition');
        Query::leftJoin('orders', []);
    }

    public function testNestedJoinRejectsNonQueryOn(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON conditions must be Query objects');
        /** @phpstan-ignore argument.type */
        Query::leftJoin('orders', [42]);
    }

    public function testNestedJoinFromJsonString(): void
    {
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId')->toString(),
        ]);

        $this->assertTrue($query->isNestedJoin());
        $this->assertSame(Method::On, $query->getJoinOnQueries()[0]->getMethod());
    }

    public function testSimpleJoinStillUsesTriple(): void
    {
        $query = Query::leftJoin('orders', '$id', 'customerId', '=', 'ord');
        $this->assertFalse($query->isNestedJoin());
        $this->assertSame(['$id', '=', 'customerId', 'ord'], $query->getValues());
        $this->assertSame('ord', $query->getJoinAlias());
    }

    public function testNestedLeftJoinCompile(): void
    {
        $builder = new MySQL();
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
        ]);

        $this->assertSame(
            'LEFT JOIN `orders` AS `ord` ON `$id` = `customerId`',
            $query->compile($builder),
        );
    }

    public function testNestedLeftJoinCompileWithFilter(): void
    {
        $builder = new MySQL();
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('users.id', 'orders.customer_id'),
            Query::equal('ord.status', ['paid']),
        ]);

        $this->assertSame(
            'LEFT JOIN `orders` AS `ord` ON `users`.`id` = `orders`.`customer_id` AND `ord`.`status` IN (?)',
            $query->compile($builder),
        );
        $this->assertSame(['paid'], $builder->getBindings());
    }

    public function testNestedJoinCompileWithoutAlias(): void
    {
        $builder = new MySQL();
        $query = Query::join('orders', [
            Query::on('users.id', 'orders.user_id'),
        ]);

        $this->assertSame(
            'JOIN `orders` ON `users`.`id` = `orders`.`user_id`',
            $query->compile($builder),
        );
    }

    public function testOnCompileRequiresColumns(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Join ON requires left and right columns');
        Query::on('', 'customerId')->compile(new MySQL());
    }

    public function testOnCompileRejectsInvalidOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid join operator: LIKE');
        Query::on('$id', 'customerId', 'LIKE')->compile(new MySQL());
    }

    public function testNestedJoinRejectsSearchOnCompile(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: search');
        Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::search('ord.status', 'paid'),
        ])->compile(new MySQL());
    }

    public function testNestedJoinRejectsRegexOnCompile(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported join ON condition: regex');
        Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::regex('ord.status', 'paid'),
        ])->compile(new MySQL());
    }

    public function testNestedJoinShapeIncludesOnQueries(): void
    {
        $query = Query::leftJoin('orders', 'ord', [
            Query::on('$id', 'customerId'),
            Query::equal('ord.status', ['paid']),
        ]);

        $this->assertSame('leftJoin:orders(equal:ord.status|on:)', $query->shape());
    }
}
