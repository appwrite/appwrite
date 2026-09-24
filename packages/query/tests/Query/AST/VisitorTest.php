<?php

namespace Tests\Query\AST;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Definition\Window as WindowDefinition;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\CaseWhen;
use Utopia\Query\AST\Expression\Cast;
use Utopia\Query\AST\Expression\Conditional;
use Utopia\Query\AST\Expression\Exists;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Expression\Subquery;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\JoinClause;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\Visitor\ColumnValidator;
use Utopia\Query\AST\Visitor\FilterInjector;
use Utopia\Query\AST\Visitor\TableRenamer;
use Utopia\Query\AST\Walker;
use Utopia\Query\Exception;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;

class VisitorTest extends TestCase
{
    private function serialize(Select $stmt): string
    {
        $serializer = new Serializer();
        return $serializer->serialize($stmt);
    }

    public function testTableRenamerSingleTable(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['users' => 'accounts']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT * FROM `accounts`', $this->serialize($result));
    }

    public function testTableRenamerInJoin(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users', 'u'),
            joins: [
                new JoinClause(
                    'JOIN',
                    new Table('orders', 'o'),
                    new Binary(
                        new Column('id', 'u'),
                        '=',
                        new Column('user_id', 'o'),
                    ),
                ),
            ],
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['orders' => 'purchases']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` AS `u` JOIN `purchases` AS `o` ON `u`.`id` = `o`.`user_id`',
            $this->serialize($result),
        );
    }

    public function testTableRenamerInColumnReference(): void
    {
        $stmt = new Select(
            columns: [
                new Column('name', 'u'),
                new Column('email', 'u'),
            ],
            from: new Table('users', 'u'),
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['u' => 'a']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT `a`.`name`, `a`.`email` FROM `users` AS `a`', $this->serialize($result));
    }

    public function testTableRenamerInStar(): void
    {
        $stmt = new Select(
            columns: [new Star('users')],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['users' => 'accounts']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT `accounts`.* FROM `accounts`', $this->serialize($result));
    }

    public function testTableRenamerMultiple(): void
    {
        $stmt = new Select(
            columns: [
                new Column('name', 'users'),
                new Column('title', 'orders'),
            ],
            from: new Table('users'),
            joins: [
                new JoinClause(
                    'JOIN',
                    new Table('orders'),
                    new Binary(
                        new Column('id', 'users'),
                        '=',
                        new Column('user_id', 'orders'),
                    ),
                ),
            ],
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['users' => 'accounts', 'orders' => 'purchases']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT `accounts`.`name`, `purchases`.`title` FROM `accounts` JOIN `purchases` ON `accounts`.`id` = `purchases`.`user_id`',
            $this->serialize($result),
        );
    }

    public function testTableRenamerNoMatch(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['products' => 'items']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT * FROM `users`', $this->serialize($result));
    }

    public function testColumnValidatorAllowed(): void
    {
        $stmt = new Select(
            columns: [
                new Column('name'),
                new Column('email'),
            ],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email', 'id']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT `name`, `email` FROM `users`', $this->serialize($result));
    }

    public function testColumnValidatorDisallowed(): void
    {
        $stmt = new Select(
            columns: [
                new Column('name'),
                new Column('password'),
            ],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email', 'id']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Column 'password' is not in the allowed list");
        $walker->walk($stmt, $visitor);
    }

    public function testColumnValidatorInWhere(): void
    {
        $stmt = new Select(
            columns: [new Column('name')],
            from: new Table('users'),
            where: new Binary(
                new Column('secret'),
                '=',
                new Literal('foo'),
            ),
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Column 'secret' is not in the allowed list");
        $walker->walk($stmt, $visitor);
    }

    public function testColumnValidatorInOrderBy(): void
    {
        $stmt = new Select(
            columns: [new Column('name')],
            from: new Table('users'),
            orderBy: [
                new OrderByItem(new Column('hidden')),
            ],
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Column 'hidden' is not in the allowed list");
        $walker->walk($stmt, $visitor);
    }

    public function testColumnValidatorRejectsStarByDefault(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wildcard (*) selection is not allowed');
        $walker->walk($stmt, $visitor);
    }

    public function testColumnValidatorAllowsStarWhenOptedIn(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = new ColumnValidator(['name', 'email'], allowStar: true);

        $result = $walker->walk($stmt, $visitor);
        $this->assertInstanceOf(Select::class, $result);
    }

    public function testFilterInjectorEmptyWhere(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );

        $condition = new Binary(
            new Column('active'),
            '=',
            new Literal(true),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame('SELECT * FROM `users` WHERE `active` = TRUE', $this->serialize($result));
    }

    public function testFilterInjectorExistingWhere(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
            where: new Binary(
                new Column('age'),
                '>',
                new Literal(18),
            ),
        );

        $condition = new Binary(
            new Column('active'),
            '=',
            new Literal(true),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `age` > 18 AND `active` = TRUE',
            $this->serialize($result),
        );
    }

    public function testFilterInjectorPreservesOther(): void
    {
        $stmt = new Select(
            columns: [new Column('name')],
            from: new Table('users'),
            orderBy: [new OrderByItem(new Column('name'))],
            limit: new Literal(10),
        );

        $condition = new Binary(
            new Column('active'),
            '=',
            new Literal(true),
        );

        $walker = new Walker();
        $visitor = new FilterInjector($condition);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT `name` FROM `users` WHERE `active` = TRUE ORDER BY `name` ASC LIMIT 10',
            $this->serialize($result),
        );
    }

    public function testComposedVisitors(): void
    {
        $stmt = new Select(
            columns: [new Column('name')],
            from: new Table('users'),
        );

        $walker = new Walker();

        $result = $walker->walk($stmt, new TableRenamer(['users' => 'accounts']));
        $result = $walker->walk($result, new FilterInjector(
            new Binary(new Column('active'), '=', new Literal(true)),
        ));

        $this->assertSame(
            'SELECT `name` FROM `accounts` WHERE `active` = TRUE',
            $this->serialize($result),
        );
    }

    public function testVisitorWithSubquery(): void
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

        $walker = new Walker();
        $visitor = new TableRenamer(['orders' => 'purchases']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `id` FROM `purchases`)',
            $this->serialize($result),
        );
    }

    public function testVisitorWithCte(): void
    {
        $cteQuery = new Select(
            columns: [new Column('id'), new Column('name')],
            from: new Table('users'),
            where: new Binary(
                new Column('active'),
                '=',
                new Literal(true),
            ),
        );

        $stmt = new Select(
            columns: [new Star()],
            from: new Table('active_users'),
            ctes: [
                new Cte('active_users', $cteQuery),
            ],
        );

        $walker = new Walker();
        $visitor = new TableRenamer(['users' => 'accounts']);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame(
            'WITH `active_users` AS (SELECT `id`, `name` FROM `accounts` WHERE `active` = TRUE) SELECT * FROM `active_users`',
            $this->serialize($result),
        );
    }

    public function testWalkerRoundTrip(): void
    {
        $stmt = new Select(
            columns: [
                new Column('name', 'u'),
                new Aliased(new Func('COUNT', [new Star()]), 'total'),
            ],
            from: new Table('users', 'u'),
            joins: [
                new JoinClause(
                    'LEFT JOIN',
                    new Table('orders', 'o'),
                    new Binary(
                        new Column('id', 'u'),
                        '=',
                        new Column('user_id', 'o'),
                    ),
                ),
            ],
            where: new Binary(
                new Column('active', 'u'),
                '=',
                new Literal(true),
            ),
            groupBy: [new Column('name', 'u')],
            having: new Binary(
                new Func('COUNT', [new Star()]),
                '>',
                new Literal(5),
            ),
            orderBy: [new OrderByItem(new Column('name', 'u'))],
            limit: new Literal(10),
            offset: new Literal(0),
        );

        $before = $this->serialize($stmt);

        $walker = new Walker();
        $visitor = new TableRenamer([]);
        $result = $walker->walk($stmt, $visitor);

        $this->assertSame($before, $this->serialize($result));
    }

    private function createCollectingVisitor(): CollectingVisitor
    {
        return new CollectingVisitor();
    }

    public function testWalkerWithCastExpression(): void
    {
        $stmt = new Select(
            columns: [
                new Cast(new Column('price'), 'INTEGER'),
            ],
            from: new Table('products'),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Cast', $visitor->visited);
        $this->assertContains('Column', $visitor->visited);
        $this->assertSame(
            'SELECT CAST(`price` AS INTEGER) FROM `products`',
            $this->serialize($result),
        );
    }

    public function testWalkerWithBetweenExpression(): void
    {
        $stmt = new Select(
            columns: [new Star()],
            from: new Table('users'),
            where: new Between(
                new Column('age'),
                new Literal(18),
                new Literal(65),
            ),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Between', $visitor->visited);
        $this->assertSame(
            'SELECT * FROM `users` WHERE `age` BETWEEN 18 AND 65',
            $this->serialize($result),
        );
    }

    public function testWalkerWithConditionalExpression(): void
    {
        $stmt = new Select(
            columns: [
                new Conditional(
                    null,
                    [
                        new CaseWhen(
                            new Binary(new Column('status'), '=', new Literal('active')),
                            new Literal(1),
                        ),
                        new CaseWhen(
                            new Binary(new Column('status'), '=', new Literal('inactive')),
                            new Literal(0),
                        ),
                    ],
                    new Literal(-1),
                ),
            ],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Conditional', $visitor->visited);
        $this->assertSame(
            "SELECT CASE WHEN `status` = 'active' THEN 1 WHEN `status` = 'inactive' THEN 0 ELSE -1 END FROM `users`",
            $this->serialize($result),
        );
    }

    public function testWalkerWithExistsExpression(): void
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

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Exists', $visitor->visited);
        $this->assertSame(
            'SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)',
            $this->serialize($result),
        );
    }

    public function testWalkerWithWindowExpression(): void
    {
        $windowFunc = new Window(
            new Func('ROW_NUMBER', []),
            null,
            new WindowSpecification(
                partitionBy: [new Column('department')],
                orderBy: [new OrderByItem(new Column('salary'), OrderDirection::Desc)],
            ),
        );

        $stmt = new Select(
            columns: [
                new Column('name'),
                new Aliased($windowFunc, 'rn'),
            ],
            from: new Table('employees'),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Window', $visitor->visited);
        $this->assertSame(
            'SELECT `name`, ROW_NUMBER() OVER (PARTITION BY `department` ORDER BY `salary` DESC) AS `rn` FROM `employees`',
            $this->serialize($result),
        );
    }

    public function testWalkerWithFunctionFilter(): void
    {
        $funcWithFilter = new Func(
            'COUNT',
            [new Column('id')],
            false,
            new Binary(new Column('active'), '=', new Literal(true)),
        );

        $stmt = new Select(
            columns: [$funcWithFilter],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Func', $visitor->visited);
        $this->assertSame(
            'SELECT COUNT(`id`) FILTER (WHERE `active` = TRUE) FROM `users`',
            $this->serialize($result),
        );
    }

    public function testWalkerWithWindowDefinition(): void
    {
        $windowFunc = new Window(
            new Func('ROW_NUMBER', []),
            'w',
            null,
        );

        $stmt = new Select(
            columns: [
                new Column('name'),
                new Aliased($windowFunc, 'rn'),
            ],
            from: new Table('employees'),
            windows: [
                new WindowDefinition(
                    'w',
                    new WindowSpecification(
                        orderBy: [new OrderByItem(new Column('salary'), OrderDirection::Desc)],
                    ),
                ),
            ],
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Window', $visitor->visited);
        $serialized = $this->serialize($result);
        $this->assertStringContainsString('WINDOW', $serialized);
        $this->assertStringContainsString('OVER `w`', $serialized);
    }

    public function testWalkerWithOrderByExpressions(): void
    {
        $stmt = new Select(
            columns: [new Column('name'), new Column('age')],
            from: new Table('users'),
            orderBy: [
                new OrderByItem(new Column('name'), OrderDirection::Asc, NullsPosition::First),
                new OrderByItem(new Column('age'), OrderDirection::Desc, NullsPosition::Last),
            ],
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $columnVisits = array_filter($visitor->visited, fn (string $type) => $type === 'Column');
        $this->assertGreaterThanOrEqual(4, count($columnVisits));

        $serialized = $this->serialize($result);
        $this->assertStringContainsString('ORDER BY `name` ASC NULLS FIRST, `age` DESC NULLS LAST', $serialized);
    }

    public function testWalkerWithSubqueryExpression(): void
    {
        $subquery = new Subquery(
            new Select(
                columns: [new Func('MAX', [new Column('salary')])],
                from: new Table('employees'),
            ),
        );

        $stmt = new Select(
            columns: [new Star()],
            from: new Table('employees'),
            where: new Binary(
                new Column('salary'),
                '=',
                $subquery,
            ),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Subquery', $visitor->visited);
        $this->assertSame(
            'SELECT * FROM `employees` WHERE `salary` = (SELECT MAX(`salary`) FROM `employees`)',
            $this->serialize($result),
        );
    }

    public function testWalkerWithConditionalOperand(): void
    {
        $stmt = new Select(
            columns: [
                new Conditional(
                    new Column('status'),
                    [
                        new CaseWhen(new Literal('active'), new Literal(1)),
                        new CaseWhen(new Literal('inactive'), new Literal(0)),
                    ],
                    null,
                ),
            ],
            from: new Table('users'),
        );

        $walker = new Walker();
        $visitor = $this->createCollectingVisitor();
        $result = $walker->walk($stmt, $visitor);

        $this->assertContains('Conditional', $visitor->visited);
        $serialized = $this->serialize($result);
        $this->assertStringContainsString('CASE `status`', $serialized);
    }
}
