<?php

namespace Tests\Query\AST;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\OrderByItem;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Placeholder;
use Utopia\Query\AST\Raw;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Star;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\OrderDirection;
use Utopia\Query\Tokenizer\Tokenizer;

class SerializerTest extends TestCase
{
    private function parse(string $sql): Select
    {
        $tokenizer = new Tokenizer();
        $tokens = Tokenizer::filter($tokenizer->tokenize($sql));
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    private function serialize(Select $stmt): string
    {
        $serializer = new Serializer();
        return $serializer->serialize($stmt);
    }

    private function roundTrip(string $sql): string
    {
        return $this->serialize($this->parse($sql));
    }

    public function testSelectStar(): void
    {
        $result = $this->roundTrip('SELECT * FROM users');
        $this->assertSame('SELECT * FROM `users`', $result);
    }

    public function testSelectColumns(): void
    {
        $result = $this->roundTrip('SELECT name, email FROM users');
        $this->assertSame('SELECT `name`, `email` FROM `users`', $result);
    }

    public function testSelectDistinct(): void
    {
        $result = $this->roundTrip('SELECT DISTINCT country FROM users');
        $this->assertSame('SELECT DISTINCT `country` FROM `users`', $result);
    }

    public function testSelectAlias(): void
    {
        $result = $this->roundTrip('SELECT name AS n FROM users u');
        $this->assertSame('SELECT `name` AS `n` FROM `users` AS `u`', $result);
    }

    public function testWhereEqual(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE id = 1');
        $this->assertSame('SELECT * FROM `users` WHERE `id` = 1', $result);
    }

    public function testWhereComplex(): void
    {
        $result = $this->roundTrip("SELECT * FROM users WHERE age > 18 AND status = 'active'");
        $this->assertSame("SELECT * FROM `users` WHERE `age` > 18 AND `status` = 'active'", $result);
    }

    public function testOperatorPrecedencePreserved(): void
    {
        $result = $this->roundTrip('SELECT * FROM t WHERE (a = 1 OR b = 2) AND c = 3');
        $this->assertSame('SELECT * FROM `t` WHERE (`a` = 1 OR `b` = 2) AND `c` = 3', $result);
    }

    public function testWhereIn(): void
    {
        $result = $this->roundTrip("SELECT * FROM users WHERE status IN ('active', 'pending')");
        $this->assertSame("SELECT * FROM `users` WHERE `status` IN ('active', 'pending')", $result);
    }

    public function testWhereNotIn(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE id NOT IN (1, 2, 3)');
        $this->assertSame('SELECT * FROM `users` WHERE `id` NOT IN (1, 2, 3)', $result);
    }

    public function testWhereBetween(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE age BETWEEN 18 AND 65');
        $this->assertSame('SELECT * FROM `users` WHERE `age` BETWEEN 18 AND 65', $result);
    }

    public function testWhereLike(): void
    {
        $result = $this->roundTrip("SELECT * FROM users WHERE name LIKE 'A%'");
        $this->assertSame("SELECT * FROM `users` WHERE `name` LIKE 'A%'", $result);
    }

    public function testWhereIsNull(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE deleted_at IS NULL');
        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $result);
    }

    public function testWhereIsNotNull(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE verified_at IS NOT NULL');
        $this->assertSame('SELECT * FROM `users` WHERE `verified_at` IS NOT NULL', $result);
    }

    public function testJoin(): void
    {
        $result = $this->roundTrip('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        $this->assertSame('SELECT * FROM `users` JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result);
    }

    public function testLeftJoin(): void
    {
        $result = $this->roundTrip('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');
        $this->assertSame('SELECT * FROM `users` LEFT JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result);
    }

    public function testCrossJoin(): void
    {
        $result = $this->roundTrip('SELECT * FROM users CROSS JOIN roles');
        $this->assertSame('SELECT * FROM `users` CROSS JOIN `roles`', $result);
    }

    public function testOrderBy(): void
    {
        $result = $this->roundTrip('SELECT * FROM users ORDER BY name ASC, created_at DESC');
        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ASC, `created_at` DESC', $result);
    }

    public function testOrderByNulls(): void
    {
        $result = $this->roundTrip('SELECT * FROM users ORDER BY name ASC NULLS LAST');
        $this->assertSame('SELECT * FROM `users` ORDER BY `name` ASC NULLS LAST', $result);
    }

    public function testGroupByHaving(): void
    {
        $result = $this->roundTrip('SELECT status, COUNT(*) FROM users GROUP BY status HAVING COUNT(*) > 5');
        $this->assertSame('SELECT `status`, COUNT(*) FROM `users` GROUP BY `status` HAVING COUNT(*) > 5', $result);
    }

    public function testLimitOffset(): void
    {
        $result = $this->roundTrip('SELECT * FROM users LIMIT 10 OFFSET 20');
        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $result);
    }

    public function testFunctionCall(): void
    {
        $result = $this->roundTrip('SELECT COUNT(*), SUM(amount) FROM orders');
        $this->assertSame('SELECT COUNT(*), SUM(`amount`) FROM `orders`', $result);
    }

    public function testCountDistinct(): void
    {
        $result = $this->roundTrip('SELECT COUNT(DISTINCT user_id) FROM orders');
        $this->assertSame('SELECT COUNT(DISTINCT `user_id`) FROM `orders`', $result);
    }

    public function testCaseExpression(): void
    {
        $result = $this->roundTrip("SELECT CASE WHEN x > 0 THEN 'pos' ELSE 'neg' END FROM t");
        $this->assertSame("SELECT CASE WHEN `x` > 0 THEN 'pos' ELSE 'neg' END FROM `t`", $result);
    }

    public function testCastExpression(): void
    {
        $result = $this->roundTrip('SELECT CAST(val AS INTEGER) FROM t');
        $this->assertSame('SELECT CAST(`val` AS INTEGER) FROM `t`', $result);
    }

    public function testSubquery(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)');
        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders`)', $result);
    }

    public function testSubqueryFrom(): void
    {
        $result = $this->roundTrip('SELECT * FROM (SELECT * FROM users) AS sub');
        $this->assertSame('SELECT * FROM (SELECT * FROM `users`) AS `sub`', $result);
    }

    public function testExists(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)');
        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)', $result);
    }

    public function testWindowFunction(): void
    {
        $result = $this->roundTrip('SELECT ROW_NUMBER() OVER (PARTITION BY dept ORDER BY sal DESC) FROM employees');
        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `dept` ORDER BY `sal` DESC) FROM `employees`', $result);
    }

    public function testNamedWindow(): void
    {
        $result = $this->roundTrip('SELECT SUM(amount) OVER w FROM orders WINDOW w AS (PARTITION BY user_id)');
        $this->assertSame('SELECT SUM(`amount`) OVER `w` FROM `orders` WINDOW `w` AS (PARTITION BY `user_id`)', $result);
    }

    public function testCte(): void
    {
        $result = $this->roundTrip("WITH active AS (SELECT * FROM users WHERE status = 'active') SELECT * FROM active");
        $this->assertSame("WITH `active` AS (SELECT * FROM `users` WHERE `status` = 'active') SELECT * FROM `active`", $result);
    }

    public function testRecursiveCte(): void
    {
        $result = $this->roundTrip('WITH RECURSIVE org AS (SELECT id, name FROM employees WHERE manager_id IS NULL) SELECT * FROM org');
        $this->assertSame('WITH RECURSIVE `org` AS (SELECT `id`, `name` FROM `employees` WHERE `manager_id` IS NULL) SELECT * FROM `org`', $result);
    }

    public function testArithmetic(): void
    {
        $result = $this->roundTrip('SELECT price * quantity FROM items');
        $this->assertSame('SELECT `price` * `quantity` FROM `items`', $result);
    }

    public function testPlaceholders(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE id = ? AND name = :name AND seq = $1');
        $this->assertSame('SELECT * FROM `users` WHERE `id` = ? AND `name` = :name AND `seq` = $1', $result);
    }

    public function testStringEscaping(): void
    {
        $result = $this->roundTrip("SELECT * FROM users WHERE name = 'O''Brien'");
        $this->assertSame("SELECT * FROM `users` WHERE `name` = 'O''Brien'", $result);
    }

    public function testDirectAstConstruction(): void
    {
        $stmt = new Select(
            columns: [
                new Aliased(new Column('name'), 'n'),
                new Func('COUNT', [new Star()]),
            ],
            from: new Table('users', 'u'),
            where: new Binary(
                new Column('active'),
                '=',
                new Literal(true),
            ),
            groupBy: [new Column('name')],
            having: new Binary(
                new Func('COUNT', [new Star()]),
                '>',
                new Literal(5),
            ),
            orderBy: [new OrderByItem(new Column('name'), OrderDirection::Asc)],
            limit: new Literal(10),
        );

        $serializer = new Serializer();
        $result = $serializer->serialize($stmt);

        $this->assertSame(
            'SELECT `name` AS `n`, COUNT(*) FROM `users` AS `u` WHERE `active` = TRUE GROUP BY `name` HAVING COUNT(*) > 5 ORDER BY `name` ASC LIMIT 10',
            $result
        );
    }

    public function testRoundTripComplexQuery(): void
    {
        $sql = "SELECT u.name, COUNT(o.id) AS order_count "
             . "FROM users u "
             . "LEFT JOIN orders o ON u.id = o.user_id "
             . "WHERE u.active = 1 AND u.created_at IS NOT NULL "
             . "GROUP BY u.name "
             . "HAVING COUNT(o.id) > 5 "
             . "ORDER BY order_count DESC "
             . "LIMIT 10 OFFSET 0";

        $expected = 'SELECT `u`.`name`, COUNT(`o`.`id`) AS `order_count` '
                  . 'FROM `users` AS `u` '
                  . 'LEFT JOIN `orders` AS `o` ON `u`.`id` = `o`.`user_id` '
                  . 'WHERE `u`.`active` = 1 AND `u`.`created_at` IS NOT NULL '
                  . 'GROUP BY `u`.`name` '
                  . 'HAVING COUNT(`o`.`id`) > 5 '
                  . 'ORDER BY `order_count` DESC '
                  . 'LIMIT 10 OFFSET 0';

        $result = $this->roundTrip($sql);
        $this->assertSame($expected, $result);
    }

    public function testSerializeExpressionColumnReference(): void
    {
        $serializer = new Serializer();
        $this->assertSame('`name`', $serializer->serializeExpression(new Column('name')));
        $this->assertSame('`t`.`name`', $serializer->serializeExpression(new Column('name', 't')));
        $this->assertSame('`s`.`t`.`name`', $serializer->serializeExpression(new Column('name', 't', 's')));
    }

    public function testSerializeExpressionLiterals(): void
    {
        $serializer = new Serializer();
        $this->assertSame('42', $serializer->serializeExpression(new Literal(42)));
        $this->assertSame('3.14', $serializer->serializeExpression(new Literal(3.14)));
        $this->assertSame("'hello'", $serializer->serializeExpression(new Literal('hello')));
        $this->assertSame('TRUE', $serializer->serializeExpression(new Literal(true)));
        $this->assertSame('FALSE', $serializer->serializeExpression(new Literal(false)));
        $this->assertSame('NULL', $serializer->serializeExpression(new Literal(null)));
    }

    public function testSerializeExpressionStar(): void
    {
        $serializer = new Serializer();
        $this->assertSame('*', $serializer->serializeExpression(new Star()));
        $this->assertSame('`users`.*', $serializer->serializeExpression(new Star('users')));
    }

    public function testSerializeExpressionPlaceholder(): void
    {
        $serializer = new Serializer();
        $this->assertSame('?', $serializer->serializeExpression(new Placeholder('?')));
        $this->assertSame(':name', $serializer->serializeExpression(new Placeholder(':name')));
        $this->assertSame('$1', $serializer->serializeExpression(new Placeholder('$1')));
    }

    public function testSerializeExpressionRaw(): void
    {
        $serializer = new Serializer();
        $this->assertSame('NOW()', $serializer->serializeExpression(new Raw('NOW()')));
    }

    public function testNotExistsExpression(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE NOT EXISTS (SELECT 1 FROM orders WHERE orders.user_id = users.id)');
        $this->assertSame('SELECT * FROM `users` WHERE NOT EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)', $result);
    }

    public function testNotBetween(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE age NOT BETWEEN 18 AND 65');
        $this->assertSame('SELECT * FROM `users` WHERE `age` NOT BETWEEN 18 AND 65', $result);
    }

    public function testUnaryNot(): void
    {
        $result = $this->roundTrip('SELECT * FROM t WHERE NOT a = 1');
        $this->assertSame('SELECT * FROM `t` WHERE NOT (`a` = 1)', $result);
    }

    public function testUnaryMinus(): void
    {
        $serializer = new Serializer();
        $expression = new Unary('-', new Literal(5));
        $this->assertSame('- (5)', $serializer->serializeExpression($expression));
    }

    public function testUnaryMinusOfNegativeLiteralDoesNotProduceDoubleDash(): void
    {
        $serializer = new Serializer();
        $expression = new Unary('-', new Literal(-5));
        $result = $serializer->serializeExpression($expression);

        $this->assertStringNotContainsString('--', $result);
        $this->assertSame('- (-5)', $result);
    }

    public function testUnaryMinusOfUnaryMinusDoesNotProduceDoubleDash(): void
    {
        $serializer = new Serializer();
        $expression = new Unary('-', new Unary('-', new Literal(5)));
        $result = $serializer->serializeExpression($expression);

        $this->assertStringNotContainsString('--', $result);
    }

    public function testSubtractionRightAssociativityParenthesized(): void
    {
        $serializer = new Serializer();
        $expression = new Binary(
            new Column('a'),
            '-',
            new Binary(new Column('b'), '-', new Column('c')),
        );
        $this->assertSame('`a` - (`b` - `c`)', $serializer->serializeExpression($expression));
    }

    public function testSubtractionLeftAssociativityNoExtraParens(): void
    {
        $serializer = new Serializer();
        $expression = new Binary(
            new Binary(new Column('a'), '-', new Column('b')),
            '-',
            new Column('c'),
        );
        $this->assertSame('`a` - `b` - `c`', $serializer->serializeExpression($expression));
    }

    public function testDivisionRightAssociativityParenthesized(): void
    {
        $serializer = new Serializer();
        $expression = new Binary(
            new Column('a'),
            '/',
            new Binary(new Column('b'), '/', new Column('c')),
        );
        $this->assertSame('`a` / (`b` / `c`)', $serializer->serializeExpression($expression));
    }

    public function testModuloRightAssociativityParenthesized(): void
    {
        $serializer = new Serializer();
        $expression = new Binary(
            new Column('a'),
            '%',
            new Binary(new Column('b'), '%', new Column('c')),
        );
        $this->assertSame('`a` % (`b` % `c`)', $serializer->serializeExpression($expression));
    }

    public function testLiteralEscapesBackslash(): void
    {
        $serializer = new Serializer();
        $this->assertSame("'a\\\\b'", $serializer->serializeExpression(new Literal('a\\b')));
        $this->assertSame("'trailing\\\\'", $serializer->serializeExpression(new Literal('trailing\\')));
    }

    public function testLiteralEscapesSingleQuote(): void
    {
        $serializer = new Serializer();
        $this->assertSame("'O''Brien'", $serializer->serializeExpression(new Literal("O'Brien")));
    }

    public function testLiteralEscapesBackslashBeforeQuote(): void
    {
        $serializer = new Serializer();
        $this->assertSame("'a\\\\''b'", $serializer->serializeExpression(new Literal("a\\'b")));
    }

    public function testCaseSimple(): void
    {
        $result = $this->roundTrip("SELECT CASE status WHEN 'active' THEN 1 ELSE 0 END FROM t");
        $this->assertSame("SELECT CASE `status` WHEN 'active' THEN 1 ELSE 0 END FROM `t`", $result);
    }

    public function testWindowWithFrame(): void
    {
        $result = $this->roundTrip('SELECT SUM(amount) OVER (ORDER BY created_at ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM orders');
        $this->assertSame('SELECT SUM(`amount`) OVER (ORDER BY `created_at` ASC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) FROM `orders`', $result);
    }

    public function testCteWithColumns(): void
    {
        $result = $this->roundTrip('WITH cte (a, b) AS (SELECT 1, 2) SELECT * FROM cte');
        $this->assertSame('WITH `cte` (`a`, `b`) AS (SELECT 1, 2) SELECT * FROM `cte`', $result);
    }

    public function testTableReferenceWithSchema(): void
    {
        $result = $this->roundTrip('SELECT * FROM public.users');
        $this->assertSame('SELECT * FROM `public`.`users`', $result);
    }

    public function testSubqueryExpressionInColumn(): void
    {
        $result = $this->roundTrip('SELECT (SELECT COUNT(*) FROM orders) FROM users');
        $this->assertSame('SELECT (SELECT COUNT(*) FROM `orders`) FROM `users`', $result);
    }

    public function testPrecedenceMultiplicationOverAddition(): void
    {
        $result = $this->roundTrip('SELECT a + b * c FROM t');
        $this->assertSame('SELECT `a` + `b` * `c` FROM `t`', $result);
    }

    public function testPrecedenceAdditionNeedsParens(): void
    {
        $result = $this->roundTrip('SELECT (a + b) * c FROM t');
        $this->assertSame('SELECT (`a` + `b`) * `c` FROM `t`', $result);
    }

    public function testBooleanLiterals(): void
    {
        $result = $this->roundTrip('SELECT * FROM t WHERE active = TRUE AND deleted = FALSE');
        $this->assertSame('SELECT * FROM `t` WHERE `active` = TRUE AND `deleted` = FALSE', $result);
    }

    public function testNullLiteral(): void
    {
        $result = $this->roundTrip('SELECT NULL FROM t');
        $this->assertSame('SELECT NULL FROM `t`', $result);
    }

    public function testFloatLiteral(): void
    {
        $result = $this->roundTrip('SELECT 3.14 FROM t');
        $this->assertSame('SELECT 3.14 FROM `t`', $result);
    }

    public function testSelectWithoutFrom(): void
    {
        $result = $this->roundTrip('SELECT 1 + 2');
        $this->assertSame('SELECT 1 + 2', $result);
    }

    public function testMultipleJoins(): void
    {
        $result = $this->roundTrip(
            'SELECT * FROM users '
            . 'INNER JOIN orders ON users.id = orders.user_id '
            . 'LEFT JOIN items ON orders.id = items.order_id'
        );
        $this->assertSame(
            'SELECT * FROM `users` '
            . 'INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id` '
            . 'LEFT JOIN `items` ON `orders`.`id` = `items`.`order_id`',
            $result
        );
    }

    public function testNestedSubquery(): void
    {
        $result = $this->roundTrip('SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE total > 100)');
        $this->assertSame(
            'SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders` WHERE `total` > 100)',
            $result
        );
    }

    public function testOrPrecedenceNeedsParens(): void
    {
        $result = $this->roundTrip('SELECT * FROM t WHERE a = 1 AND b = 2 OR c = 3');
        $this->assertSame('SELECT * FROM `t` WHERE `a` = 1 AND `b` = 2 OR `c` = 3', $result);
    }

    public function testFunctionCallNoArgs(): void
    {
        $result = $this->roundTrip('SELECT NOW() FROM t');
        $this->assertSame('SELECT NOW() FROM `t`', $result);
    }

    public function testFunctionCallMultipleArgs(): void
    {
        $result = $this->roundTrip("SELECT COALESCE(name, 'unknown') FROM users");
        $this->assertSame("SELECT COALESCE(`name`, 'unknown') FROM `users`", $result);
    }

    public function testImplicitAlias(): void
    {
        $result = $this->roundTrip('SELECT name n FROM users u');
        $this->assertSame('SELECT `name` AS `n` FROM `users` AS `u`', $result);
    }
}
