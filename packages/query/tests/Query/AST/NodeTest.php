<?php

namespace Tests\Query\AST;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Definition\Window as WindowDefinition;
use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\CaseWhen;
use Utopia\Query\AST\Expression\Cast;
use Utopia\Query\AST\Expression\Conditional;
use Utopia\Query\AST\Expression\Exists;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Expression\Subquery;
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\JoinClause;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Placeholder;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\SubquerySource;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;

class NodeTest extends TestCase
{
    public function testColumnReference(): void
    {
        $col = new Column('id');
        $this->assertInstanceOf(Expression::class, $col);
        $this->assertSame('id', $col->name);
        $this->assertNull($col->table);
        $this->assertNull($col->schema);

        $col = new Column('id', 'users');
        $this->assertSame('id', $col->name);
        $this->assertSame('users', $col->table);
        $this->assertNull($col->schema);

        $col = new Column('id', 'users', 'public');
        $this->assertSame('id', $col->name);
        $this->assertSame('users', $col->table);
        $this->assertSame('public', $col->schema);
    }

    public function testLiteral(): void
    {
        $str = new Literal('hello');
        $this->assertInstanceOf(Expression::class, $str);
        $this->assertSame('hello', $str->value);

        $int = new Literal(42);
        $this->assertSame(42, $int->value);

        $float = new Literal(3.14);
        $this->assertSame(3.14, $float->value);

        $bool = new Literal(true);
        $this->assertSame(true, $bool->value);

        $null = new Literal(null);
        $this->assertNull($null->value);
    }

    public function testStar(): void
    {
        $star = new Star();
        $this->assertInstanceOf(Expression::class, $star);
        $this->assertNull($star->table);

        $star = new Star('users');
        $this->assertSame('users', $star->table);
    }

    public function testPlaceholder(): void
    {
        $q = new Placeholder('?');
        $this->assertInstanceOf(Expression::class, $q);
        $this->assertSame('?', $q->value);

        $named = new Placeholder(':name');
        $this->assertSame(':name', $named->value);

        $numbered = new Placeholder('$1');
        $this->assertSame('$1', $numbered->value);
    }

    public function testRaw(): void
    {
        $raw = new Raw('NOW() + INTERVAL 1 DAY');
        $this->assertInstanceOf(Expression::class, $raw);
        $this->assertSame('NOW() + INTERVAL 1 DAY', $raw->sql);
    }

    public function testBinaryExpression(): void
    {
        $left = new Column('age');
        $right = new Literal(18);
        $expression = new Binary($left, '>=', $right);

        $this->assertInstanceOf(Expression::class, $expression);
        $this->assertSame($left, $expression->left);
        $this->assertSame('>=', $expression->operator);
        $this->assertSame($right, $expression->right);

        $and = new Binary(
            new Binary(new Column('a'), '=', new Literal(1)),
            'AND',
            new Binary(new Column('b'), '=', new Literal(2)),
        );
        $this->assertSame('AND', $and->operator);
    }

    public function testUnaryExpressionPrefix(): void
    {
        $operand = new Column('active');
        $not = new Unary('NOT', $operand);

        $this->assertInstanceOf(Expression::class, $not);
        $this->assertSame('NOT', $not->operator);
        $this->assertSame($operand, $not->operand);
        $this->assertTrue($not->prefix);

        $neg = new Unary('-', new Literal(5));
        $this->assertSame('-', $neg->operator);
        $this->assertTrue($neg->prefix);
    }

    public function testUnaryExpressionPostfix(): void
    {
        $operand = new Column('deleted_at');
        $isNull = new Unary('IS NULL', $operand, false);

        $this->assertInstanceOf(Expression::class, $isNull);
        $this->assertSame('IS NULL', $isNull->operator);
        $this->assertSame($operand, $isNull->operand);
        $this->assertFalse($isNull->prefix);

        $isNotNull = new Unary('IS NOT NULL', $operand, false);
        $this->assertSame('IS NOT NULL', $isNotNull->operator);
        $this->assertFalse($isNotNull->prefix);
    }

    public function testFunctionCall(): void
    {
        $fn = new Func('UPPER', [new Column('name')]);
        $this->assertInstanceOf(Expression::class, $fn);
        $this->assertSame('UPPER', $fn->name);
        $this->assertCount(1, $fn->arguments);
        $this->assertFalse($fn->distinct);

        $noArgs = new Func('NOW');
        $this->assertSame('NOW', $noArgs->name);
        $this->assertSame([], $noArgs->arguments);
    }

    public function testFunctionCallDistinct(): void
    {
        $count = new Func('COUNT', [new Column('id')], true);
        $this->assertSame('COUNT', $count->name);
        $this->assertTrue($count->distinct);
        $this->assertCount(1, $count->arguments);
    }

    public function testInExpression(): void
    {
        $col = new Column('status');
        $list = [new Literal('active'), new Literal('pending')];
        $in = new In($col, $list);

        $this->assertInstanceOf(Expression::class, $in);
        $this->assertSame($col, $in->expression);
        $this->assertSame($list, $in->list);
        $this->assertFalse($in->negated);

        $notIn = new In($col, $list, true);
        $this->assertTrue($notIn->negated);

        $subquery = new Select(
            columns: [new Column('id')],
            from: new Table('other'),
        );
        $inSub = new In($col, $subquery);
        $this->assertInstanceOf(Select::class, $inSub->list);
    }

    public function testBetweenExpression(): void
    {
        $col = new Column('age');
        $low = new Literal(18);
        $high = new Literal(65);
        $between = new Between($col, $low, $high);

        $this->assertInstanceOf(Expression::class, $between);
        $this->assertSame($col, $between->expression);
        $this->assertSame($low, $between->low);
        $this->assertSame($high, $between->high);
        $this->assertFalse($between->negated);

        $notBetween = new Between($col, $low, $high, true);
        $this->assertTrue($notBetween->negated);
    }

    public function testExistsExpression(): void
    {
        $subquery = new Select(
            columns: [new Literal(1)],
            from: new Table('users'),
            where: new Binary(new Column('id'), '=', new Literal(1)),
        );

        $exists = new Exists($subquery);
        $this->assertInstanceOf(Expression::class, $exists);
        $this->assertSame($subquery, $exists->subquery);
        $this->assertFalse($exists->negated);

        $notExists = new Exists($subquery, true);
        $this->assertTrue($notExists->negated);
    }

    public function testConditionalExpression(): void
    {
        $whens = [
            new CaseWhen(
                new Binary(new Column('status'), '=', new Literal('active')),
                new Literal(1),
            ),
            new CaseWhen(
                new Binary(new Column('status'), '=', new Literal('inactive')),
                new Literal(0),
            ),
        ];
        $else = new Literal(-1);
        $searched = new Conditional(null, $whens, $else);

        $this->assertInstanceOf(Expression::class, $searched);
        $this->assertNull($searched->operand);
        $this->assertCount(2, $searched->whens);
        $this->assertSame($else, $searched->else);

        $simple = new Conditional(new Column('status'), $whens);
        $this->assertInstanceOf(Expression::class, $simple);
        $this->assertNotNull($simple->operand);
        $this->assertNull($simple->else);
    }

    public function testCastExpression(): void
    {
        $expression = new Column('price');
        $cast = new Cast($expression, 'INTEGER');

        $this->assertInstanceOf(Expression::class, $cast);
        $this->assertSame($expression, $cast->expression);
        $this->assertSame('INTEGER', $cast->type);
    }

    public function testAliasedExpression(): void
    {
        $expression = new Func('COUNT', [new Star()]);
        $aliased = new Aliased($expression, 'total');

        $this->assertInstanceOf(Expression::class, $aliased);
        $this->assertSame($expression, $aliased->expression);
        $this->assertSame('total', $aliased->alias);
    }

    public function testSubqueryExpression(): void
    {
        $query = new Select(
            columns: [new Func('MAX', [new Column('salary')])],
            from: new Table('employees'),
        );
        $sub = new Subquery($query);

        $this->assertInstanceOf(Expression::class, $sub);
        $this->assertSame($query, $sub->query);
    }

    public function testWindowExpression(): void
    {
        $fn = new Func('ROW_NUMBER');
        $specification = new WindowSpecification(
            partitionBy: [new Column('department')],
            orderBy: [new OrderByItem(new Column('salary'), OrderDirection::Desc)],
        );
        $window = new Window($fn, specification: $specification);

        $this->assertInstanceOf(Expression::class, $window);
        $this->assertSame($fn, $window->function);
        $this->assertNull($window->windowName);
        $this->assertSame($specification, $window->specification);

        $namedWindow = new Window($fn, windowName: 'w');
        $this->assertSame('w', $namedWindow->windowName);
        $this->assertNull($namedWindow->specification);
    }

    public function testWindowSpecification(): void
    {
        $specification = new WindowSpecification();
        $this->assertSame([], $specification->partitionBy);
        $this->assertSame([], $specification->orderBy);
        $this->assertNull($specification->frameType);
        $this->assertNull($specification->frameStart);
        $this->assertNull($specification->frameEnd);

        $specification = new WindowSpecification(
            partitionBy: [new Column('dept')],
            orderBy: [new OrderByItem(new Column('hire_date'))],
            frameType: 'ROWS',
            frameStart: 'UNBOUNDED PRECEDING',
            frameEnd: 'CURRENT ROW',
        );
        $this->assertCount(1, $specification->partitionBy);
        $this->assertCount(1, $specification->orderBy);
        $this->assertSame('ROWS', $specification->frameType);
        $this->assertSame('UNBOUNDED PRECEDING', $specification->frameStart);
        $this->assertSame('CURRENT ROW', $specification->frameEnd);
    }

    public function testTableReference(): void
    {
        $table = new Table('users');
        $this->assertSame('users', $table->name);
        $this->assertNull($table->alias);
        $this->assertNull($table->schema);

        $aliased = new Table('users', 'u');
        $this->assertSame('users', $aliased->name);
        $this->assertSame('u', $aliased->alias);

        $schemed = new Table('users', 'u', 'public');
        $this->assertSame('public', $schemed->schema);
    }

    public function testSubquerySource(): void
    {
        $query = new Select(
            columns: [new Star()],
            from: new Table('users'),
        );
        $source = new SubquerySource($query, 'sub');

        $this->assertSame($query, $source->query);
        $this->assertSame('sub', $source->alias);
    }

    public function testJoinClause(): void
    {
        $table = new Table('orders', 'o');
        $condition = new Binary(
            new Column('id', 'u'),
            '=',
            new Column('user_id', 'o'),
        );

        $join = new JoinClause('JOIN', $table, $condition);
        $this->assertSame('JOIN', $join->type);
        $this->assertSame($table, $join->table);
        $this->assertSame($condition, $join->condition);

        $leftJoin = new JoinClause('LEFT JOIN', $table, $condition);
        $this->assertSame('LEFT JOIN', $leftJoin->type);

        $cross = new JoinClause('CROSS JOIN', $table);
        $this->assertNull($cross->condition);

        $subSource = new SubquerySource(
            new Select(columns: [new Star()], from: new Table('items')),
            'i',
        );
        $subJoin = new JoinClause('LEFT JOIN', $subSource, $condition);
        $this->assertInstanceOf(SubquerySource::class, $subJoin->table);
    }

    public function testOrderByItem(): void
    {
        $item = new OrderByItem(new Column('name'));
        $this->assertSame(OrderDirection::Asc, $item->direction);
        $this->assertNull($item->nulls);

        $desc = new OrderByItem(new Column('created_at'), OrderDirection::Desc);
        $this->assertSame(OrderDirection::Desc, $desc->direction);

        $nullsFirst = new OrderByItem(new Column('score'), OrderDirection::Asc, NullsPosition::First);
        $this->assertSame(NullsPosition::First, $nullsFirst->nulls);

        $nullsLast = new OrderByItem(new Column('score'), OrderDirection::Desc, NullsPosition::Last);
        $this->assertSame(NullsPosition::Last, $nullsLast->nulls);
    }

    public function testOrderByItemEnumTypes(): void
    {
        $item = new OrderByItem(new Column('name'), OrderDirection::Desc, NullsPosition::First);
        $this->assertInstanceOf(OrderDirection::class, $item->direction);
        $this->assertInstanceOf(NullsPosition::class, $item->nulls);
        $this->assertSame(OrderDirection::Desc, $item->direction);
        $this->assertSame(NullsPosition::First, $item->nulls);
    }

    public function testWindowDefinition(): void
    {
        $specification = new WindowSpecification(
            partitionBy: [new Column('dept')],
            orderBy: [new OrderByItem(new Column('salary'), OrderDirection::Desc)],
        );
        $def = new WindowDefinition('w', $specification);

        $this->assertSame('w', $def->name);
        $this->assertSame($specification, $def->specification);
    }

    public function testCteDefinition(): void
    {
        $query = new Select(
            columns: [new Star()],
            from: new Table('employees'),
            where: new Binary(new Column('active'), '=', new Literal(true)),
        );

        $cte = new Cte('active_employees', $query);
        $this->assertSame('active_employees', $cte->name);
        $this->assertSame($query, $cte->query);
        $this->assertSame([], $cte->columns);
        $this->assertFalse($cte->recursive);

        $cteWithCols = new Cte('ranked', $query, ['id', 'name', 'rank']);
        $this->assertSame(['id', 'name', 'rank'], $cteWithCols->columns);

        $recursive = new Cte('hierarchy', $query, recursive: true);
        $this->assertTrue($recursive->recursive);
    }

    public function testSelect(): void
    {
        $select = new Select(
            columns: [
                new Column('name', 'u'),
                new Aliased(new Func('COUNT', [new Star()]), 'order_count'),
            ],
            from: new Table('users', 'u'),
            joins: [
                new JoinClause(
                    'LEFT JOIN',
                    new Table('orders', 'o'),
                    new Binary(new Column('id', 'u'), '=', new Column('user_id', 'o')),
                ),
            ],
            where: new Binary(new Column('active', 'u'), '=', new Literal(true)),
            groupBy: [new Column('name', 'u')],
            having: new Binary(
                new Func('COUNT', [new Star()]),
                '>',
                new Literal(5),
            ),
            orderBy: [new OrderByItem(new Column('name', 'u'))],
            limit: new Literal(10),
            offset: new Literal(0),
            distinct: true,
        );

        $this->assertCount(2, $select->columns);
        $this->assertInstanceOf(Table::class, $select->from);
        $this->assertCount(1, $select->joins);
        $this->assertNotNull($select->where);
        $this->assertCount(1, $select->groupBy);
        $this->assertNotNull($select->having);
        $this->assertCount(1, $select->orderBy);
        $this->assertNotNull($select->limit);
        $this->assertNotNull($select->offset);
        $this->assertTrue($select->distinct);
        $this->assertSame([], $select->ctes);
        $this->assertSame([], $select->windows);
    }

    public function testSelectWith(): void
    {
        $original = new Select(
            columns: [new Star()],
            from: new Table('users'),
            limit: new Literal(10),
            distinct: false,
        );

        $modified = $original->with(
            limit: new Literal(20),
            distinct: true,
        );

        $this->assertNotSame($original, $modified);
        $this->assertSame($original->columns, $modified->columns);
        $this->assertSame($original->from, $modified->from);
        $this->assertTrue($modified->distinct);
        $this->assertInstanceOf(Literal::class, $modified->limit);
        $this->assertSame(20, $modified->limit->value);
        $this->assertInstanceOf(Literal::class, $original->limit);
        $this->assertSame(10, $original->limit->value);
        $this->assertFalse($original->distinct);

        $withWhere = $original->with(
            where: new Binary(new Column('id'), '=', new Literal(1)),
        );
        $this->assertNotNull($withWhere->where);
        $this->assertNull($original->where);

        $withNullWhere = $withWhere->with(where: null);
        $this->assertNull($withNullWhere->where);

        $withFrom = $original->with(from: null);
        $this->assertNull($withFrom->from);
        $this->assertNotNull($original->from);

        $unchanged = $original->with();
        $this->assertNotSame($original, $unchanged);
        $this->assertSame($original->columns, $unchanged->columns);
        $this->assertSame($original->from, $unchanged->from);
        $this->assertSame($original->limit, $unchanged->limit);

        $withCtes = $original->with(
            ctes: [
                new Cte('sub', new Select(columns: [new Literal(1)])),
            ],
        );
        $this->assertCount(1, $withCtes->ctes);
        $this->assertSame([], $original->ctes);

        $withWindows = $original->with(
            windows: [
                new WindowDefinition('w', new WindowSpecification(
                    orderBy: [new OrderByItem(new Column('id'))],
                )),
            ],
        );
        $this->assertCount(1, $withWindows->windows);
    }
}
