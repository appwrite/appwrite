<?php

namespace Tests\Query\AST;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\Exists;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Expression\Subquery;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor\FilterInjector;
use Utopia\Query\AST\Walker;
use Utopia\Query\OrderDirection;

class WalkerTest extends TestCase
{
    private function serialize(Select $stmt): string
    {
        $serializer = new Serializer();
        return $serializer->serialize($stmt);
    }

    /**
     * Regression: Walker must route nested Select subqueries through walk()
     * so visitors hooking on visitSelect() fire on subqueries too.
     *
     * When a Select appears in an expression context (EXISTS, scalar subquery,
     * IN (SELECT ...)), the Walker previously descended via walkStatement()
     * which skips visitSelect(). FilterInjector hooks on visitSelect(), so
     * its condition silently skipped the nested Select.
     */
    public function testFilterInjectorAppliedToExistsSubquery(): void
    {
        $subquery = new Select(
            columns: [new Literal(1)],
            from: new Table('orders'),
            where: new Binary(
                new Column('user_id', 'orders'),
                '=',
                new Column('id', 'users'),
            ),
        );

        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
            where: new Exists($subquery),
        );

        $condition = new Binary(
            new Column('tenant_id'),
            '=',
            new Literal(42),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id` AND `tenant_id` = 42) AND `tenant_id` = 42',
            $this->serialize($result),
        );
    }

    public function testFilterInjectorAppliedToScalarSubquery(): void
    {
        $subquery = new Subquery(
            new Select(
                columns: [new Column('max_value')],
                from: new Table('limits'),
            ),
        );

        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
            where: new Binary(
                new Column('score'),
                '<',
                $subquery,
            ),
        );

        $condition = new Binary(
            new Column('tenant_id'),
            '=',
            new Literal(42),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `score` < (SELECT `max_value` FROM `limits` WHERE `tenant_id` = 42) AND `tenant_id` = 42',
            $this->serialize($result),
        );
    }

    public function testFilterInjectorAppliedToInSubquery(): void
    {
        $subquery = new Select(
            columns: [new Column('id')],
            from: new Table('orders'),
        );

        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
            where: new In(
                new Column('id'),
                $subquery,
            ),
        );

        $condition = new Binary(
            new Column('tenant_id'),
            '=',
            new Literal(42),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `id` FROM `orders` WHERE `tenant_id` = 42) AND `tenant_id` = 42',
            $this->serialize($result),
        );
    }

    /**
     * A pure-inspection visitor (one that never swaps out nodes) must return
     * the exact same Select instance the caller passed in. This guarantees no
     * Walker allocations for read-only traversals.
     */
    public function testWalkReturnsSameInstanceWhenVisitorIsNoOp(): void
    {
        $stmt = new Select(
            columns: [
                new Column('id'),
                new Column('name'),
                new Literal(1),
            ],
            from: new Table('users'),
            where: new Binary(
                new Column('age'),
                '>',
                new Literal(18),
            ),
            groupBy: [new Column('country')],
            orderBy: [new OrderByItem(new Column('created_at'), OrderDirection::Desc)],
            limit: new Literal(10),
            offset: new Literal(5),
        );

        $walker = new Walker();
        $visitor = new CollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame($stmt, $result);
        $this->assertNotEmpty($visitor->visited, 'Visitor should have been invoked');
    }
}
