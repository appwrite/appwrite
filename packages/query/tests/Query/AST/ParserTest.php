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
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\JoinClause;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Placeholder;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\AST\SubquerySource;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;
use Utopia\Query\Tokenizer\Tokenizer;

class ParserTest extends TestCase
{
    private function parse(string $sql): Select
    {
        $tokenizer = new Tokenizer();
        $tokens = Tokenizer::filter($tokenizer->tokenize($sql));
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    public function testSimpleSelect(): void
    {
        $stmt = $this->parse('SELECT * FROM users');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Star::class, $stmt->columns[0]);
        $this->assertNull($stmt->columns[0]->table);
        $this->assertInstanceOf(Table::class, $stmt->from);
        $this->assertSame('users', $stmt->from->name);
        $this->assertFalse($stmt->distinct);
    }

    public function testSelectColumns(): void
    {
        $stmt = $this->parse('SELECT name, email FROM users');

        $this->assertCount(2, $stmt->columns);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]);
        $this->assertSame('name', $stmt->columns[0]->name);
        $this->assertInstanceOf(Column::class, $stmt->columns[1]);
        $this->assertSame('email', $stmt->columns[1]->name);
    }

    public function testSelectDistinct(): void
    {
        $stmt = $this->parse('SELECT DISTINCT country FROM users');

        $this->assertTrue($stmt->distinct);
        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]);
        $this->assertSame('country', $stmt->columns[0]->name);
    }

    public function testSelectWithAlias(): void
    {
        $stmt = $this->parse('SELECT name AS n, email AS e FROM users u');

        $this->assertCount(2, $stmt->columns);

        $this->assertInstanceOf(Aliased::class, $stmt->columns[0]);
        $this->assertSame('n', $stmt->columns[0]->alias);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]->expression);
        $this->assertSame('name', $stmt->columns[0]->expression->name);

        $this->assertInstanceOf(Aliased::class, $stmt->columns[1]);
        $this->assertSame('e', $stmt->columns[1]->alias);

        $this->assertInstanceOf(Table::class, $stmt->from);
        $this->assertSame('users', $stmt->from->name);
        $this->assertSame('u', $stmt->from->alias);
    }

    public function testWhereEqual(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE id = 1');

        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('=', $stmt->where->operator);
        $this->assertInstanceOf(Column::class, $stmt->where->left);
        $this->assertSame('id', $stmt->where->left->name);
        $this->assertInstanceOf(Literal::class, $stmt->where->right);
        $this->assertSame(1, $stmt->where->right->value);
    }

    public function testWhereComplex(): void
    {
        $stmt = $this->parse("SELECT * FROM users WHERE age > 18 AND status = 'active' OR role = 'admin'");

        // OR is lowest precedence, so: OR(AND(age>18, status='active'), role='admin')
        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('OR', $stmt->where->operator);

        $left = $stmt->where->left;
        $this->assertInstanceOf(Binary::class, $left);
        $this->assertSame('AND', $left->operator);

        $right = $stmt->where->right;
        $this->assertInstanceOf(Binary::class, $right);
        $this->assertSame('=', $right->operator);
    }

    public function testOperatorPrecedence(): void
    {
        // a AND b OR c  =>  OR(AND(a, b), c)
        $stmt = $this->parse("SELECT * FROM t WHERE a = 1 AND b = 2 OR c = 3");

        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('OR', $stmt->where->operator);

        $andExpression = $stmt->where->left;
        $this->assertInstanceOf(Binary::class, $andExpression);
        $this->assertSame('AND', $andExpression->operator);
    }

    public function testNotPrecedence(): void
    {
        // NOT a AND b  =>  AND(NOT(a), b)
        $stmt = $this->parse("SELECT * FROM t WHERE NOT a = 1 AND b = 2");

        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('AND', $stmt->where->operator);

        $left = $stmt->where->left;
        $this->assertInstanceOf(Unary::class, $left);
        $this->assertSame('NOT', $left->operator);
        $this->assertTrue($left->prefix);
    }

    public function testWhereIn(): void
    {
        $stmt = $this->parse("SELECT * FROM users WHERE status IN ('active', 'pending')");

        $this->assertInstanceOf(In::class, $stmt->where);
        $this->assertFalse($stmt->where->negated);
        $this->assertInstanceOf(Column::class, $stmt->where->expression);
        $this->assertSame('status', $stmt->where->expression->name);
        $this->assertIsArray($stmt->where->list);
        $this->assertCount(2, $stmt->where->list);
        $this->assertInstanceOf(Literal::class, $stmt->where->list[0]);
        $this->assertSame('active', $stmt->where->list[0]->value);
    }

    public function testWhereNotIn(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE id NOT IN (1, 2, 3)');

        $this->assertInstanceOf(In::class, $stmt->where);
        $this->assertTrue($stmt->where->negated);
        $this->assertIsArray($stmt->where->list);
        $this->assertCount(3, $stmt->where->list);
    }

    public function testWhereBetween(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE age BETWEEN 18 AND 65');

        $this->assertInstanceOf(Between::class, $stmt->where);
        $this->assertFalse($stmt->where->negated);
        $this->assertInstanceOf(Column::class, $stmt->where->expression);
        $this->assertSame('age', $stmt->where->expression->name);
        $this->assertInstanceOf(Literal::class, $stmt->where->low);
        $this->assertSame(18, $stmt->where->low->value);
        $this->assertInstanceOf(Literal::class, $stmt->where->high);
        $this->assertSame(65, $stmt->where->high->value);
    }

    public function testWhereNotBetween(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE id NOT BETWEEN 100 AND 200');

        $this->assertInstanceOf(Between::class, $stmt->where);
        $this->assertTrue($stmt->where->negated);
        $this->assertInstanceOf(Literal::class, $stmt->where->low);
        $this->assertSame(100, $stmt->where->low->value);
        $this->assertInstanceOf(Literal::class, $stmt->where->high);
        $this->assertSame(200, $stmt->where->high->value);
    }

    public function testWhereLike(): void
    {
        $stmt = $this->parse("SELECT * FROM users WHERE name LIKE 'A%'");

        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('LIKE', $stmt->where->operator);
        $this->assertInstanceOf(Column::class, $stmt->where->left);
        $this->assertSame('name', $stmt->where->left->name);
        $this->assertInstanceOf(Literal::class, $stmt->where->right);
        $this->assertSame('A%', $stmt->where->right->value);
    }

    public function testWhereIsNull(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE deleted_at IS NULL');

        $this->assertInstanceOf(Unary::class, $stmt->where);
        $this->assertSame('IS NULL', $stmt->where->operator);
        $this->assertFalse($stmt->where->prefix);
        $this->assertInstanceOf(Column::class, $stmt->where->operand);
        $this->assertSame('deleted_at', $stmt->where->operand->name);
    }

    public function testWhereIsNotNull(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE verified_at IS NOT NULL');

        $this->assertInstanceOf(Unary::class, $stmt->where);
        $this->assertSame('IS NOT NULL', $stmt->where->operator);
        $this->assertFalse($stmt->where->prefix);
    }

    public function testJoin(): void
    {
        $stmt = $this->parse('SELECT * FROM users JOIN orders ON users.id = orders.user_id');

        $this->assertCount(1, $stmt->joins);
        $join = $stmt->joins[0];
        $this->assertInstanceOf(JoinClause::class, $join);
        $this->assertSame('JOIN', $join->type);
        $this->assertInstanceOf(Table::class, $join->table);
        $this->assertSame('orders', $join->table->name);
        $this->assertInstanceOf(Binary::class, $join->condition);
    }

    public function testLeftJoin(): void
    {
        $stmt = $this->parse('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');

        $this->assertCount(1, $stmt->joins);
        $this->assertSame('LEFT JOIN', $stmt->joins[0]->type);
    }

    public function testMultipleJoins(): void
    {
        $stmt = $this->parse(
            'SELECT * FROM users '
            . 'INNER JOIN orders ON users.id = orders.user_id '
            . 'LEFT JOIN items ON orders.id = items.order_id'
        );

        $this->assertCount(2, $stmt->joins);
        $this->assertSame('INNER JOIN', $stmt->joins[0]->type);
        $this->assertSame('LEFT JOIN', $stmt->joins[1]->type);
    }

    public function testCrossJoin(): void
    {
        $stmt = $this->parse('SELECT * FROM users CROSS JOIN roles');

        $this->assertCount(1, $stmt->joins);
        $this->assertSame('CROSS JOIN', $stmt->joins[0]->type);
        $this->assertNull($stmt->joins[0]->condition);
    }

    public function testFullOuterJoin(): void
    {
        $stmt = $this->parse('SELECT * FROM a FULL OUTER JOIN b ON a.id = b.a_id');

        $this->assertCount(1, $stmt->joins);
        $this->assertSame('FULL OUTER JOIN', $stmt->joins[0]->type);
    }

    public function testNaturalJoin(): void
    {
        $stmt = $this->parse('SELECT * FROM users NATURAL JOIN orders');

        $this->assertCount(1, $stmt->joins);
        $this->assertSame('NATURAL JOIN', $stmt->joins[0]->type);
        $this->assertNull($stmt->joins[0]->condition);
    }

    public function testOrderByAsc(): void
    {
        $stmt = $this->parse('SELECT * FROM users ORDER BY name ASC');

        $this->assertCount(1, $stmt->orderBy);
        $this->assertInstanceOf(OrderByItem::class, $stmt->orderBy[0]);
        $this->assertSame(OrderDirection::Asc, $stmt->orderBy[0]->direction);
        $this->assertInstanceOf(Column::class, $stmt->orderBy[0]->expression);
        $this->assertSame('name', $stmt->orderBy[0]->expression->name);
    }

    public function testOrderByDesc(): void
    {
        $stmt = $this->parse('SELECT * FROM users ORDER BY created_at DESC');

        $this->assertCount(1, $stmt->orderBy);
        $this->assertSame(OrderDirection::Desc, $stmt->orderBy[0]->direction);
    }

    public function testOrderByMultiple(): void
    {
        $stmt = $this->parse('SELECT * FROM users ORDER BY status ASC, name DESC');

        $this->assertCount(2, $stmt->orderBy);
        $this->assertSame(OrderDirection::Asc, $stmt->orderBy[0]->direction);
        $this->assertSame(OrderDirection::Desc, $stmt->orderBy[1]->direction);
    }

    public function testOrderByNulls(): void
    {
        $stmt = $this->parse('SELECT * FROM users ORDER BY name ASC NULLS LAST');

        $this->assertCount(1, $stmt->orderBy);
        $this->assertSame(OrderDirection::Asc, $stmt->orderBy[0]->direction);
        $this->assertSame(NullsPosition::Last, $stmt->orderBy[0]->nulls);
    }

    public function testGroupBy(): void
    {
        $stmt = $this->parse('SELECT status, COUNT(*) FROM users GROUP BY status');

        $this->assertCount(1, $stmt->groupBy);
        $this->assertInstanceOf(Column::class, $stmt->groupBy[0]);
        $this->assertSame('status', $stmt->groupBy[0]->name);
    }

    public function testGroupByHaving(): void
    {
        $stmt = $this->parse('SELECT status, COUNT(*) FROM users GROUP BY status HAVING COUNT(*) > 5');

        $this->assertCount(1, $stmt->groupBy);
        $this->assertInstanceOf(Binary::class, $stmt->having);
        $this->assertSame('>', $stmt->having->operator);
        $this->assertInstanceOf(Func::class, $stmt->having->left);
        $this->assertSame('COUNT', $stmt->having->left->name);
    }

    public function testLimitOffset(): void
    {
        $stmt = $this->parse('SELECT * FROM users LIMIT 10 OFFSET 20');

        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(10, $stmt->limit->value);
        $this->assertInstanceOf(Literal::class, $stmt->offset);
        $this->assertSame(20, $stmt->offset->value);
    }

    public function testFunctionCall(): void
    {
        $stmt = $this->parse('SELECT COUNT(*) FROM users');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Func::class, $stmt->columns[0]);
        $this->assertSame('COUNT', $stmt->columns[0]->name);
        $this->assertCount(1, $stmt->columns[0]->arguments);
        $this->assertInstanceOf(Star::class, $stmt->columns[0]->arguments[0]);
    }

    public function testFunctionCallArgs(): void
    {
        $stmt = $this->parse("SELECT COALESCE(name, 'unknown') FROM users");

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Func::class, $stmt->columns[0]);
        $this->assertSame('COALESCE', $stmt->columns[0]->name);
        $this->assertCount(2, $stmt->columns[0]->arguments);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]->arguments[0]);
        $this->assertInstanceOf(Literal::class, $stmt->columns[0]->arguments[1]);
        $this->assertSame('unknown', $stmt->columns[0]->arguments[1]->value);
    }

    public function testCountDistinct(): void
    {
        $stmt = $this->parse('SELECT COUNT(DISTINCT user_id) FROM orders');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Func::class, $stmt->columns[0]);
        $this->assertSame('COUNT', $stmt->columns[0]->name);
        $this->assertTrue($stmt->columns[0]->distinct);
        $this->assertCount(1, $stmt->columns[0]->arguments);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]->arguments[0]);
        $this->assertSame('user_id', $stmt->columns[0]->arguments[0]->name);
    }

    public function testNestedFunctions(): void
    {
        $stmt = $this->parse('SELECT UPPER(TRIM(name)) FROM users');

        $this->assertCount(1, $stmt->columns);
        $outer = $stmt->columns[0];
        $this->assertInstanceOf(Func::class, $outer);
        $this->assertSame('UPPER', $outer->name);
        $this->assertCount(1, $outer->arguments);

        $inner = $outer->arguments[0];
        $this->assertInstanceOf(Func::class, $inner);
        $this->assertSame('TRIM', $inner->name);
    }

    public function testCaseSearched(): void
    {
        $stmt = $this->parse("SELECT CASE WHEN x > 0 THEN 'pos' ELSE 'neg' END FROM t");

        $this->assertCount(1, $stmt->columns);
        $case = $stmt->columns[0];
        $this->assertInstanceOf(Conditional::class, $case);
        $this->assertNull($case->operand);
        $this->assertCount(1, $case->whens);
        $this->assertInstanceOf(CaseWhen::class, $case->whens[0]);
        $this->assertInstanceOf(Binary::class, $case->whens[0]->condition);
        $this->assertInstanceOf(Literal::class, $case->whens[0]->result);
        $this->assertSame('pos', $case->whens[0]->result->value);
        $this->assertInstanceOf(Literal::class, $case->else);
        $this->assertSame('neg', $case->else->value);
    }

    public function testCaseSimple(): void
    {
        $stmt = $this->parse("SELECT CASE status WHEN 'active' THEN 1 ELSE 0 END FROM t");

        $case = $stmt->columns[0];
        $this->assertInstanceOf(Conditional::class, $case);
        $this->assertInstanceOf(Column::class, $case->operand);
        $this->assertSame('status', $case->operand->name);
        $this->assertCount(1, $case->whens);
        $this->assertInstanceOf(Literal::class, $case->whens[0]->condition);
        $this->assertSame('active', $case->whens[0]->condition->value);
        $this->assertInstanceOf(Literal::class, $case->else);
        $this->assertSame(0, $case->else->value);
    }

    public function testCastExpression(): void
    {
        $stmt = $this->parse('SELECT CAST(value AS INTEGER) FROM t');

        $this->assertCount(1, $stmt->columns);
        $cast = $stmt->columns[0];
        $this->assertInstanceOf(Cast::class, $cast);
        $this->assertInstanceOf(Column::class, $cast->expression);
        $this->assertSame('value', $cast->expression->name);
        $this->assertSame('INTEGER', $cast->type);
    }

    public function testPostgresCast(): void
    {
        $stmt = $this->parse('SELECT value::integer FROM t');

        $this->assertCount(1, $stmt->columns);
        $cast = $stmt->columns[0];
        $this->assertInstanceOf(Cast::class, $cast);
        $this->assertInstanceOf(Column::class, $cast->expression);
        $this->assertSame('value', $cast->expression->name);
        $this->assertSame('integer', $cast->type);
    }

    public function testSubquery(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)');

        $this->assertInstanceOf(In::class, $stmt->where);
        $this->assertInstanceOf(Select::class, $stmt->where->list);
        $this->assertCount(1, $stmt->where->list->columns);
    }

    public function testSubqueryInFrom(): void
    {
        $stmt = $this->parse('SELECT * FROM (SELECT * FROM users) AS sub');

        $this->assertInstanceOf(SubquerySource::class, $stmt->from);
        $this->assertSame('sub', $stmt->from->alias);
        $this->assertInstanceOf(Select::class, $stmt->from->query);
    }

    public function testExistsExpression(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)');

        $this->assertInstanceOf(Exists::class, $stmt->where);
        $this->assertFalse($stmt->where->negated);
        $this->assertInstanceOf(Select::class, $stmt->where->subquery);
    }

    public function testNotExistsExpression(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE NOT EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)');

        $this->assertInstanceOf(Exists::class, $stmt->where);
        $this->assertTrue($stmt->where->negated);
    }

    public function testPlaceholders(): void
    {
        $stmt = $this->parse('SELECT * FROM users WHERE id = ? AND name = :name AND seq = $1');

        $and1 = $stmt->where;
        $this->assertInstanceOf(Binary::class, $and1);
        $this->assertSame('AND', $and1->operator);

        // The left side is: (id = ?) AND (name = :name)
        $and2 = $and1->left;
        $this->assertInstanceOf(Binary::class, $and2);
        $this->assertSame('AND', $and2->operator);

        // id = ?
        $eq1 = $and2->left;
        $this->assertInstanceOf(Binary::class, $eq1);
        $this->assertInstanceOf(Placeholder::class, $eq1->right);
        $this->assertSame('?', $eq1->right->value);

        // name = :name
        $eq2 = $and2->right;
        $this->assertInstanceOf(Binary::class, $eq2);
        $this->assertInstanceOf(Placeholder::class, $eq2->right);
        $this->assertSame(':name', $eq2->right->value);

        // seq = $1
        $eq3 = $and1->right;
        $this->assertInstanceOf(Binary::class, $eq3);
        $this->assertInstanceOf(Placeholder::class, $eq3->right);
        $this->assertSame('$1', $eq3->right->value);
    }

    public function testDotNotation(): void
    {
        $stmt = $this->parse('SELECT u.name, u.email FROM users u');

        $this->assertCount(2, $stmt->columns);

        $this->assertInstanceOf(Column::class, $stmt->columns[0]);
        $this->assertSame('name', $stmt->columns[0]->name);
        $this->assertSame('u', $stmt->columns[0]->table);

        $this->assertInstanceOf(Column::class, $stmt->columns[1]);
        $this->assertSame('email', $stmt->columns[1]->name);
        $this->assertSame('u', $stmt->columns[1]->table);
    }

    public function testStarQualified(): void
    {
        $stmt = $this->parse('SELECT users.* FROM users');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Star::class, $stmt->columns[0]);
        $this->assertSame('users', $stmt->columns[0]->table);
    }

    public function testWindowFunction(): void
    {
        $stmt = $this->parse('SELECT ROW_NUMBER() OVER (PARTITION BY dept ORDER BY sal DESC) FROM employees');

        $this->assertCount(1, $stmt->columns);
        $window = $stmt->columns[0];
        $this->assertInstanceOf(Window::class, $window);
        $this->assertInstanceOf(Func::class, $window->function);
        $this->assertSame('ROW_NUMBER', $window->function->name);
        $this->assertInstanceOf(WindowSpecification::class, $window->specification);
        $this->assertCount(1, $window->specification->partitionBy);
        $this->assertInstanceOf(Column::class, $window->specification->partitionBy[0]);
        $this->assertSame('dept', $window->specification->partitionBy[0]->name);
        $this->assertCount(1, $window->specification->orderBy);
        $this->assertSame(OrderDirection::Desc, $window->specification->orderBy[0]->direction);
    }

    public function testNamedWindow(): void
    {
        $stmt = $this->parse('SELECT SUM(amount) OVER w FROM orders WINDOW w AS (PARTITION BY user_id)');

        $this->assertCount(1, $stmt->columns);
        $window = $stmt->columns[0];
        $this->assertInstanceOf(Window::class, $window);
        $this->assertSame('w', $window->windowName);

        $this->assertCount(1, $stmt->windows);
        $this->assertInstanceOf(WindowDefinition::class, $stmt->windows[0]);
        $this->assertSame('w', $stmt->windows[0]->name);
        $this->assertCount(1, $stmt->windows[0]->specification->partitionBy);
    }

    public function testCte(): void
    {
        $stmt = $this->parse('WITH active AS (SELECT * FROM users WHERE status = \'active\') SELECT * FROM active');

        $this->assertCount(1, $stmt->ctes);
        $cte = $stmt->ctes[0];
        $this->assertInstanceOf(Cte::class, $cte);
        $this->assertSame('active', $cte->name);
        $this->assertFalse($cte->recursive);
        $this->assertInstanceOf(Select::class, $cte->query);

        $this->assertInstanceOf(Table::class, $stmt->from);
        $this->assertSame('active', $stmt->from->name);
    }

    public function testRecursiveCte(): void
    {
        $stmt = $this->parse(
            'WITH RECURSIVE org AS (SELECT id, name FROM employees WHERE manager_id IS NULL) SELECT * FROM org'
        );

        $this->assertCount(1, $stmt->ctes);
        $this->assertTrue($stmt->ctes[0]->recursive);
        $this->assertSame('org', $stmt->ctes[0]->name);
    }

    public function testArithmeticExpression(): void
    {
        $stmt = $this->parse('SELECT price * quantity AS total FROM items');

        $this->assertCount(1, $stmt->columns);
        $aliased = $stmt->columns[0];
        $this->assertInstanceOf(Aliased::class, $aliased);
        $this->assertSame('total', $aliased->alias);
        $expression = $aliased->expression;
        $this->assertInstanceOf(Binary::class, $expression);
        $this->assertSame('*', $expression->operator);
        $this->assertInstanceOf(Column::class, $expression->left);
        $this->assertSame('price', $expression->left->name);
        $this->assertInstanceOf(Column::class, $expression->right);
        $this->assertSame('quantity', $expression->right->name);
    }

    public function testParenthesizedExpression(): void
    {
        // (a OR b) AND c  =>  AND(OR(a, b), c)
        $stmt = $this->parse('SELECT * FROM t WHERE (a = 1 OR b = 2) AND c = 3');

        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('AND', $stmt->where->operator);

        $left = $stmt->where->left;
        $this->assertInstanceOf(Binary::class, $left);
        $this->assertSame('OR', $left->operator);
    }

    public function testComplexQuery(): void
    {
        $sql = "SELECT u.name, COUNT(o.id) AS order_count "
             . "FROM users u "
             . "LEFT JOIN orders o ON u.id = o.user_id "
             . "WHERE u.active = 1 AND u.created_at IS NOT NULL "
             . "GROUP BY u.name "
             . "HAVING COUNT(o.id) > 5 "
             . "ORDER BY order_count DESC "
             . "LIMIT 10 OFFSET 0";

        $stmt = $this->parse($sql);

        $this->assertCount(2, $stmt->columns);
        $this->assertInstanceOf(Table::class, $stmt->from);
        $this->assertSame('users', $stmt->from->name);
        $this->assertSame('u', $stmt->from->alias);
        $this->assertCount(1, $stmt->joins);
        $this->assertSame('LEFT JOIN', $stmt->joins[0]->type);
        $this->assertInstanceOf(Binary::class, $stmt->where);
        $this->assertSame('AND', $stmt->where->operator);
        $this->assertCount(1, $stmt->groupBy);
        $this->assertInstanceOf(Binary::class, $stmt->having);
        $this->assertCount(1, $stmt->orderBy);
        $this->assertSame(OrderDirection::Desc, $stmt->orderBy[0]->direction);
        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(10, $stmt->limit->value);
        $this->assertInstanceOf(Literal::class, $stmt->offset);
        $this->assertSame(0, $stmt->offset->value);
    }

    public function testSelectWithoutFrom(): void
    {
        $stmt = $this->parse('SELECT 1 + 2');

        $this->assertCount(1, $stmt->columns);
        $this->assertNull($stmt->from);
        $expression = $stmt->columns[0];
        $this->assertInstanceOf(Binary::class, $expression);
        $this->assertSame('+', $expression->operator);
        $this->assertInstanceOf(Literal::class, $expression->left);
        $this->assertSame(1, $expression->left->value);
        $this->assertInstanceOf(Literal::class, $expression->right);
        $this->assertSame(2, $expression->right->value);
    }

    public function testFetchFirstRows(): void
    {
        $stmt = $this->parse('SELECT * FROM users FETCH FIRST 10 ROWS ONLY');

        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(10, $stmt->limit->value);
    }

    public function testFetchNextRows(): void
    {
        $stmt = $this->parse('SELECT * FROM users FETCH NEXT 10 ROWS ONLY');

        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(10, $stmt->limit->value);
    }

    public function testFetchFirstSingularRow(): void
    {
        $stmt = $this->parse('SELECT * FROM users FETCH FIRST 1 ROW ONLY');

        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(1, $stmt->limit->value);
    }

    public function testFetchNextSingularRow(): void
    {
        $stmt = $this->parse('SELECT * FROM users FETCH NEXT 1 ROW ONLY');

        $this->assertInstanceOf(Literal::class, $stmt->limit);
        $this->assertSame(1, $stmt->limit->value);
    }

    public function testBacktickIdentifierUndoublesEscapedDelimiter(): void
    {
        $stmt = $this->parse('SELECT `foo``bar` FROM t');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]);
        $this->assertSame('foo`bar', $stmt->columns[0]->name);
    }

    public function testDoubleQuotedIdentifierUndoublesEscapedDelimiter(): void
    {
        $stmt = $this->parse('SELECT "foo""bar" FROM t');

        $this->assertCount(1, $stmt->columns);
        $this->assertInstanceOf(Column::class, $stmt->columns[0]);
        $this->assertSame('foo"bar', $stmt->columns[0]->name);
    }

    public function testDeeplyNestedExpressionRejected(): void
    {
        $depth = 500;
        $sql = 'SELECT ' . \str_repeat('(', $depth) . '1' . \str_repeat(')', $depth) . ' FROM t';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Expression nesting too deep');

        $this->parse($sql);
    }
}
