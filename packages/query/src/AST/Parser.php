<?php

namespace Utopia\Query\AST;

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
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Exception;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\TokenType;

/**
 * Recursive-descent SQL parser.
 *
 * Consumes filtered {@see Token}s from a {@see \Utopia\Query\Tokenizer\Tokenizer}
 * and builds a {@see Select} tree that can be inspected, rewritten via
 * {@see Walker} and {@see Visitor}, and re-serialized by {@see Serializer}.
 *
 * Not to be confused with {@see \Utopia\Query\Classifier}, which only reads a
 * message's leading keyword to route it to a primary or replica.
 */
class Parser
{
    private const int MAX_DEPTH = 256;

    /** @var Token[] */
    private array $tokens;
    private int $tokenCount;
    private int $pos;
    private int $depth = 0;
    private bool $inColumnList = false;

    /**
     * Parse tokens into a Select.
     * @param Token[] $tokens filtered tokens (no whitespace/comments), must end with Eof
     */
    public function parse(array $tokens): Select
    {
        $this->tokens = $tokens;
        $this->tokenCount = count($tokens);
        $this->pos = 0;
        $this->depth = 0;
        $this->inColumnList = false;

        return $this->parseSelect();
    }

    private function parseSelect(): Select
    {
        $ctes = [];
        $recursive = false;

        if ($this->matchKeyword('WITH')) {
            $this->advance();
            if ($this->matchKeyword('RECURSIVE')) {
                $recursive = true;
                $this->advance();
            }
            $ctes = $this->parseCteList($recursive);
        }

        $this->consumeKeyword('SELECT');

        $distinct = false;
        if ($this->matchKeyword('DISTINCT')) {
            $distinct = true;
            $this->advance();
        }

        $columns = $this->parseColumnList();

        $from = null;
        $joins = [];
        $where = null;
        $groupBy = [];
        $having = null;
        $windows = [];
        $orderBy = [];
        $limit = null;
        $offset = null;

        if ($this->matchKeyword('FROM')) {
            $this->advance();
            $from = $this->parseTableSource();
            $joins = $this->parseJoins();
        }

        if ($this->matchKeyword('WHERE')) {
            $this->advance();
            $where = $this->parseExpression();
        }

        if ($this->matchKeyword('GROUP')) {
            $this->advance();
            $this->consumeKeyword('BY');
            $groupBy = $this->parseExpressionList();
        }

        if ($this->matchKeyword('HAVING')) {
            $this->advance();
            $having = $this->parseExpression();
        }

        if ($this->matchKeyword('WINDOW')) {
            $this->advance();
            $windows = $this->parseWindowDefinitions();
        }

        if ($this->matchKeyword('ORDER')) {
            $this->advance();
            $this->consumeKeyword('BY');
            $orderBy = $this->parseOrderByList();
        }

        if ($this->matchKeyword('LIMIT')) {
            $this->advance();
            $limit = $this->parseExpression();
        }

        if ($this->matchKeyword('OFFSET')) {
            $this->advance();
            $offset = $this->parseExpression();
        }

        if ($this->matchKeyword('FETCH')) {
            $this->advance();
            if (!$this->matchKeyword('FIRST', 'NEXT')) {
                throw new Exception('Expected FIRST or NEXT after FETCH at position ' . $this->current()->position);
            }
            $this->advance();
            $limit = $this->parseExpression();
            if (!$this->matchKeyword('ROW', 'ROWS')) {
                throw new Exception('Expected ROW or ROWS at position ' . $this->current()->position);
            }
            $this->advance();
            $this->expectIdentifierValue('ONLY');
        }

        return new Select(
            columns: $columns,
            from: $from,
            joins: $joins,
            where: $where,
            groupBy: $groupBy,
            having: $having,
            orderBy: $orderBy,
            limit: $limit,
            offset: $offset,
            distinct: $distinct,
            ctes: $ctes,
            windows: $windows,
        );
    }

    /**
     * @return Cte[]
     */
    private function parseCteList(bool $recursive): array
    {
        $ctes = [];
        do {
            $ctes[] = $this->parseCteDefinition($recursive);
        } while ($this->matchAndConsume(TokenType::Comma));

        return $ctes;
    }

    private function parseCteDefinition(bool $recursive): Cte
    {
        $name = $this->expectIdentifier();
        $columns = [];

        if ($this->current()->type === TokenType::LeftParen) {
            if ($this->peekIsColumnList()) {
                $this->expect(TokenType::LeftParen);
                $columns[] = $this->expectIdentifier();
                while ($this->matchAndConsume(TokenType::Comma)) {
                    $columns[] = $this->expectIdentifier();
                }
                $this->expect(TokenType::RightParen);
            }
        }

        $this->consumeKeyword('AS');
        $this->expect(TokenType::LeftParen);
        $query = $this->parseSelect();
        $this->expect(TokenType::RightParen);

        return new Cte($name, $query, $columns, $recursive);
    }

    private function peekIsColumnList(): bool
    {
        $depth = 0;

        for ($i = $this->pos; $i < $this->tokenCount; $i++) {
            $t = $this->tokens[$i];
            if ($t->type === TokenType::LeftParen) {
                $depth++;
            } elseif ($t->type === TokenType::RightParen) {
                $depth--;
                if ($depth === 0) {
                    $next = $i + 1 < $this->tokenCount ? $this->tokens[$i + 1] : null;
                    return $next !== null
                        && $next->type === TokenType::Keyword
                        && strtoupper($next->value) === 'AS';
                }
            }
        }

        return false;
    }

    /**
     * @return Expression[]
     */
    private function parseColumnList(): array
    {
        $this->inColumnList = true;
        $columns = [];
        $columns[] = $this->parseSelectColumn();

        while ($this->matchAndConsume(TokenType::Comma)) {
            $columns[] = $this->parseSelectColumn();
        }

        $this->inColumnList = false;
        return $columns;
    }

    private function parseSelectColumn(): Expression
    {
        $expression = $this->parseExpression();

        if ($this->matchKeyword('AS')) {
            $this->advance();
            $alias = $this->expectIdentifier();
            return new Aliased($expression, $alias);
        }

        if ($this->inColumnList && $this->isImplicitAlias()) {
            $alias = $this->expectIdentifier();
            return new Aliased($expression, $alias);
        }

        return $expression;
    }

    private function isImplicitAlias(): bool
    {
        $token = $this->current();
        if ($token->type === TokenType::Identifier) {
            return true;
        }
        if ($token->type === TokenType::QuotedIdentifier) {
            return true;
        }
        return false;
    }

    private function parseExpression(): Expression
    {
        if ($this->depth >= self::MAX_DEPTH) {
            throw new ValidationException('Expression nesting too deep');
        }

        $this->depth++;

        try {
            return $this->parseOr();
        } finally {
            $this->depth--;
        }
    }

    private function parseOr(): Expression
    {
        $left = $this->parseAnd();

        while ($this->matchKeyword('OR')) {
            $this->advance();
            $right = $this->parseAnd();
            $left = new Binary($left, 'OR', $right);
        }

        return $left;
    }

    private function parseAnd(): Expression
    {
        $left = $this->parseNot();

        while ($this->matchKeyword('AND')) {
            $this->advance();
            $right = $this->parseNot();
            $left = new Binary($left, 'AND', $right);
        }

        return $left;
    }

    private function parseNot(): Expression
    {
        if ($this->matchKeyword('NOT')) {
            if ($this->peekKeyword(1, 'EXISTS')) {
                $this->advance(); // consume NOT
                $this->advance(); // consume EXISTS
                $this->expect(TokenType::LeftParen);
                $subquery = $this->parseSelect();
                $this->expect(TokenType::RightParen);
                return new Exists($subquery, true);
            }

            $this->advance();
            $operand = $this->parseNot();
            return new Unary('NOT', $operand);
        }

        return $this->parseComparison();
    }

    private function parseComparison(): Expression
    {
        $left = $this->parseAddition();

        $left = $this->parsePostfixModifiers($left);

        return $left;
    }

    private function parsePostfixModifiers(Expression $left): Expression
    {
        // IS [NOT] NULL
        if ($this->matchKeyword('IS')) {
            $this->advance();
            if ($this->matchKeyword('NOT')) {
                $this->advance();
                $this->expectNull();
                return new Unary('IS NOT NULL', $left, false);
            }
            $this->expectNull();
            return new Unary('IS NULL', $left, false);
        }

        // NOT IN / NOT BETWEEN / NOT LIKE / NOT ILIKE
        if ($this->matchKeyword('NOT')) {
            if ($this->peekKeyword(1, 'IN')) {
                $this->advance(); // NOT
                $this->advance(); // IN
                return $this->parseInList($left, true);
            }
            if ($this->peekKeyword(1, 'BETWEEN')) {
                $this->advance(); // NOT
                $this->advance(); // BETWEEN
                return $this->parseBetween($left, true);
            }
            if ($this->peekKeyword(1, 'LIKE')) {
                $this->advance(); // NOT
                $this->advance(); // LIKE
                $right = $this->parseAddition();
                return new Binary($left, 'NOT LIKE', $right);
            }
            if ($this->peekKeyword(1, 'ILIKE')) {
                $this->advance(); // NOT
                $this->advance(); // ILIKE
                $right = $this->parseAddition();
                return new Binary($left, 'NOT ILIKE', $right);
            }
        }

        // IN
        if ($this->matchKeyword('IN')) {
            $this->advance();
            return $this->parseInList($left, false);
        }

        // BETWEEN
        if ($this->matchKeyword('BETWEEN')) {
            $this->advance();
            return $this->parseBetween($left, false);
        }

        // LIKE / ILIKE
        if ($this->matchKeyword('LIKE')) {
            $this->advance();
            $right = $this->parseAddition();
            return new Binary($left, 'LIKE', $right);
        }
        if ($this->matchKeyword('ILIKE')) {
            $this->advance();
            $right = $this->parseAddition();
            return new Binary($left, 'ILIKE', $right);
        }

        // Comparison operators: =, !=, <>, <, >, <=, >=
        if ($this->current()->type === TokenType::Operator) {
            $op = $this->current()->value;
            if (in_array($op, ['=', '!=', '<>', '<', '>', '<=', '>='], true)) {
                $this->advance();
                $right = $this->parseAddition();
                $result = new Binary($left, $op, $right);
                return $this->parsePostfixModifiers($result);
            }
        }

        return $left;
    }

    private function parseInList(Expression $left, bool $negated): In
    {
        $this->expect(TokenType::LeftParen);

        if ($this->matchKeyword('SELECT') || $this->matchKeyword('WITH')) {
            $subquery = $this->parseSelect();
            $this->expect(TokenType::RightParen);
            return new In($left, $subquery, $negated);
        }

        $list = [];
        $list[] = $this->parseExpression();
        while ($this->matchAndConsume(TokenType::Comma)) {
            $list[] = $this->parseExpression();
        }
        $this->expect(TokenType::RightParen);

        return new In($left, $list, $negated);
    }

    private function parseBetween(Expression $left, bool $negated): Between
    {
        $low = $this->parseAddition();
        $this->consumeKeyword('AND');
        $high = $this->parseAddition();

        return new Between($left, $low, $high, $negated);
    }

    private function parseAddition(): Expression
    {
        $left = $this->parseMultiplication();

        while (true) {
            $token = $this->current();
            if ($token->type === TokenType::Operator && in_array($token->value, ['+', '-', '||'], true)) {
                $op = $token->value;
                $this->advance();
                $right = $this->parseMultiplication();
                $left = new Binary($left, $op, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseMultiplication(): Expression
    {
        $left = $this->parseUnary();

        while (true) {
            $token = $this->current();
            if ($token->type === TokenType::Star) {
                $this->advance();
                $right = $this->parseUnary();
                $left = new Binary($left, '*', $right);
            } elseif ($token->type === TokenType::Operator && in_array($token->value, ['/', '%'], true)) {
                $op = $token->value;
                $this->advance();
                $right = $this->parseUnary();
                $left = new Binary($left, $op, $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseUnary(): Expression
    {
        $token = $this->current();

        if ($token->type === TokenType::Operator && ($token->value === '-' || $token->value === '+')) {
            $op = $token->value;
            $this->advance();
            $operand = $this->parseUnary();
            return new Unary($op, $operand);
        }

        $expression = $this->parsePrimary();

        // Handle PostgreSQL-style :: cast at this level so it works everywhere
        while ($this->current()->type === TokenType::Operator && $this->current()->value === '::') {
            $this->advance();
            $type = $this->expectIdentifier();
            $expression = new Cast($expression, $type);
        }

        return $expression;
    }

    private function parsePrimary(): Expression
    {
        $token = $this->current();

        if ($token->type === TokenType::Integer) {
            $this->advance();
            return new Literal((int)$token->value);
        }

        if ($token->type === TokenType::Float) {
            $this->advance();
            return new Literal((float)$token->value);
        }

        if ($token->type === TokenType::String) {
            $this->advance();
            $raw = $token->value;
            $inner = substr($raw, 1, -1);
            $inner = str_replace("''", "'", $inner);
            $inner = str_replace("\\'", "'", $inner);
            return new Literal($inner);
        }

        if ($token->type === TokenType::Boolean) {
            $this->advance();
            return new Literal(strtoupper($token->value) === 'TRUE');
        }

        if ($token->type === TokenType::Null) {
            $this->advance();
            return new Literal(null);
        }

        if ($token->type === TokenType::Placeholder) {
            $this->advance();
            return new Placeholder($token->value);
        }
        if ($token->type === TokenType::NamedPlaceholder) {
            $this->advance();
            return new Placeholder($token->value);
        }
        if ($token->type === TokenType::NumberedPlaceholder) {
            $this->advance();
            return new Placeholder($token->value);
        }

        if ($token->type === TokenType::Star) {
            $this->advance();
            return new Star();
        }

        if ($token->type === TokenType::LeftParen) {
            $this->advance();
            if ($this->matchKeyword('SELECT') || $this->matchKeyword('WITH')) {
                $subquery = $this->parseSelect();
                $this->expect(TokenType::RightParen);
                return new Subquery($subquery);
            }
            $expression = $this->parseExpression();
            $this->expect(TokenType::RightParen);
            return $expression;
        }

        if ($this->matchKeyword('CASE')) {
            return $this->parseCaseExpression();
        }

        if ($this->matchKeyword('CAST')) {
            return $this->parseCastExpression();
        }

        if ($this->matchKeyword('EXISTS')) {
            $this->advance();
            $this->expect(TokenType::LeftParen);
            $subquery = $this->parseSelect();
            $this->expect(TokenType::RightParen);
            return new Exists($subquery);
        }

        if ($token->type === TokenType::Identifier || $token->type === TokenType::QuotedIdentifier) {
            return $this->parseIdentifierExpression();
        }

        if ($token->type === TokenType::Keyword) {
            if ($this->peek(1)->type === TokenType::LeftParen) {
                return $this->parseIdentifierExpression();
            }
        }

        throw new Exception(
            "Unexpected token '{$token->value}' ({$token->type->name}) at position {$token->position}"
        );
    }

    private function parseIdentifierExpression(): Expression
    {
        $token = $this->current();
        $name = $this->extractIdentifier($token);
        $this->advance();

        $next = $this->current();

        if ($next->type === TokenType::LeftParen) {
            return $this->parseFunctionCallExpression($name);
        }

        if ($next->type === TokenType::Dot) {
            $this->advance();
            $afterDot = $this->current();

            if ($afterDot->type === TokenType::Star) {
                $this->advance();
                return new Star($name);
            }

            $second = $this->extractIdentifier($afterDot);
            $this->advance();
            $afterSecond = $this->current();

            if ($afterSecond->type === TokenType::Dot) {
                $this->advance();
                $afterSecondDot = $this->current();

                if ($afterSecondDot->type === TokenType::Star) {
                    $this->advance();
                    return new Star($second, $name);
                }

                $third = $this->extractIdentifier($afterSecondDot);
                $this->advance();
                return new Column($third, $second, $name);
            }

            return new Column($second, $name);
        }

        return new Column($name);
    }

    private function parseFunctionCallExpression(string $name): Expression
    {
        $upperName = strtoupper($name);
        $this->expect(TokenType::LeftParen);

        if ($this->current()->type === TokenType::Star) {
            $this->advance();
            $this->expect(TokenType::RightParen);
            $function = new Func($upperName, [new Star()]);
            return $this->parseFunctionPostfix($function);
        }

        if ($this->current()->type === TokenType::RightParen) {
            $this->advance();
            $function = new Func($upperName);
            return $this->parseFunctionPostfix($function);
        }

        $distinct = false;
        if ($this->matchKeyword('DISTINCT')) {
            $distinct = true;
            $this->advance();
        }

        $args = [];
        $args[] = $this->parseExpression();
        while ($this->matchAndConsume(TokenType::Comma)) {
            $args[] = $this->parseExpression();
        }

        $this->expect(TokenType::RightParen);
        $function = new Func($upperName, $args, $distinct);
        return $this->parseFunctionPostfix($function);
    }

    private function parseFunctionPostfix(Func $function): Expression
    {
        if ($this->matchKeyword('FILTER')) {
            $this->advance();
            $this->expect(TokenType::LeftParen);
            $this->consumeKeyword('WHERE');
            $filterExpression = $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $function = new Func($function->name, $function->arguments, $function->distinct, $filterExpression);
        }

        if ($this->matchKeyword('OVER')) {
            $this->advance();

            if ($this->current()->type === TokenType::Identifier) {
                $windowName = $this->extractIdentifier($this->current());
                $this->advance();
                return new Window($function, windowName: $windowName);
            }

            $this->expect(TokenType::LeftParen);
            $specification = $this->parseWindowSpecification();
            $this->expect(TokenType::RightParen);
            return new Window($function, specification: $specification);
        }

        return $function;
    }

    private function parseCaseExpression(): Conditional
    {
        $this->consumeKeyword('CASE');

        $operand = null;
        if (!$this->matchKeyword('WHEN')) {
            $operand = $this->parseExpression();
        }

        $whens = [];
        while ($this->matchKeyword('WHEN')) {
            $this->advance();
            $condition = $this->parseExpression();
            $this->consumeKeyword('THEN');
            $result = $this->parseExpression();
            $whens[] = new CaseWhen($condition, $result);
        }

        $else = null;
        if ($this->matchKeyword('ELSE')) {
            $this->advance();
            $else = $this->parseExpression();
        }

        $this->consumeKeyword('END');

        return new Conditional($operand, $whens, $else);
    }

    private function parseCastExpression(): Cast
    {
        $this->consumeKeyword('CAST');
        $this->expect(TokenType::LeftParen);
        $expression = $this->parseExpression();
        $this->consumeKeyword('AS');
        $type = $this->expectIdentifier();
        $this->expect(TokenType::RightParen);

        return new Cast($expression, $type);
    }

    /**
     * @return Table|SubquerySource
     */
    private function parseTableSource(): Table|SubquerySource
    {
        if ($this->current()->type === TokenType::LeftParen) {
            $this->advance();
            $subquery = $this->parseSelect();
            $this->expect(TokenType::RightParen);

            if ($this->matchKeyword('AS')) {
                $this->advance();
            }
            $alias = $this->expectIdentifier();

            return new SubquerySource($subquery, $alias);
        }

        return $this->parseTableReference();
    }

    private function parseTableReference(): Table
    {
        $name = $this->expectIdentifier();
        $schema = null;
        $alias = null;

        if ($this->current()->type === TokenType::Dot) {
            $this->advance();
            $schema = $name;
            $name = $this->expectIdentifier();
        }

        if ($this->matchKeyword('AS')) {
            $this->advance();
            $alias = $this->expectIdentifier();
        } elseif ($this->isTableAlias()) {
            $alias = $this->expectIdentifier();
        }

        return new Table($name, $alias, $schema);
    }

    private function isTableAlias(): bool
    {
        $token = $this->current();
        if ($token->type === TokenType::Identifier || $token->type === TokenType::QuotedIdentifier) {
            return true;
        }
        return false;
    }

    /**
     * @return JoinClause[]
     */
    private function parseJoins(): array
    {
        $joins = [];

        while (true) {
            $joinType = $this->tryParseJoinType();
            if ($joinType === null) {
                break;
            }

            $table = $this->parseTableSource();

            $condition = null;
            if ($joinType !== 'CROSS JOIN' && $joinType !== 'NATURAL JOIN') {
                if ($this->matchKeyword('ON')) {
                    $this->advance();
                    $condition = $this->parseExpression();
                }
            }

            $joins[] = new JoinClause($joinType, $table, $condition);
        }

        return $joins;
    }

    private function tryParseJoinType(): ?string
    {
        if ($this->matchKeyword('JOIN')) {
            $this->advance();
            return 'JOIN';
        }

        if ($this->matchKeyword('INNER')) {
            $this->advance();
            $this->consumeKeyword('JOIN');
            return 'INNER JOIN';
        }

        if ($this->matchKeyword('LEFT')) {
            $this->advance();
            if ($this->matchKeyword('OUTER')) {
                $this->advance();
            }
            $this->consumeKeyword('JOIN');
            return 'LEFT JOIN';
        }

        if ($this->matchKeyword('RIGHT')) {
            $this->advance();
            if ($this->matchKeyword('OUTER')) {
                $this->advance();
            }
            $this->consumeKeyword('JOIN');
            return 'RIGHT JOIN';
        }

        if ($this->matchKeyword('FULL')) {
            $this->advance();
            if ($this->matchKeyword('OUTER')) {
                $this->advance();
            }
            $this->consumeKeyword('JOIN');
            return 'FULL OUTER JOIN';
        }

        if ($this->matchKeyword('CROSS')) {
            $this->advance();
            $this->consumeKeyword('JOIN');
            return 'CROSS JOIN';
        }

        if ($this->matchKeyword('NATURAL')) {
            $this->advance();
            $this->consumeKeyword('JOIN');
            return 'NATURAL JOIN';
        }

        return null;
    }

    /**
     * @return Expression[]
     */
    private function parseExpressionList(): array
    {
        $expressions = [];
        $expressions[] = $this->parseExpression();

        while ($this->matchAndConsume(TokenType::Comma)) {
            $expressions[] = $this->parseExpression();
        }

        return $expressions;
    }

    /**
     * @return OrderByItem[]
     */
    private function parseOrderByList(): array
    {
        $items = [];
        $items[] = $this->parseOrderByItem();

        while ($this->matchAndConsume(TokenType::Comma)) {
            $items[] = $this->parseOrderByItem();
        }

        return $items;
    }

    private function parseOrderByItem(): OrderByItem
    {
        $expression = $this->parseExpression();

        $direction = OrderDirection::Asc;
        if ($this->matchKeyword('ASC')) {
            $this->advance();
            $direction = OrderDirection::Asc;
        } elseif ($this->matchKeyword('DESC')) {
            $this->advance();
            $direction = OrderDirection::Desc;
        }

        $nulls = null;
        if ($this->matchKeyword('NULLS')) {
            $this->advance();
            if ($this->matchKeyword('FIRST')) {
                $this->advance();
                $nulls = NullsPosition::First;
            } elseif ($this->matchKeyword('LAST')) {
                $this->advance();
                $nulls = NullsPosition::Last;
            } else {
                throw new Exception(
                    "Expected FIRST or LAST after NULLS at position {$this->current()->position}, got '{$this->current()->value}'"
                );
            }
        }

        return new OrderByItem($expression, $direction, $nulls);
    }

    /**
     * @return WindowDefinition[]
     */
    private function parseWindowDefinitions(): array
    {
        $defs = [];

        do {
            $name = $this->expectIdentifier();
            $this->consumeKeyword('AS');
            $this->expect(TokenType::LeftParen);
            $specification = $this->parseWindowSpecification();
            $this->expect(TokenType::RightParen);
            $defs[] = new WindowDefinition($name, $specification);
        } while ($this->matchAndConsume(TokenType::Comma));

        return $defs;
    }

    private function parseWindowSpecification(): WindowSpecification
    {
        $partitionBy = [];
        $orderBy = [];
        $frameType = null;
        $frameStart = null;
        $frameEnd = null;

        if ($this->matchKeyword('PARTITION')) {
            $this->advance();
            $this->consumeKeyword('BY');
            $partitionBy = $this->parseExpressionList();
        }

        if ($this->matchKeyword('ORDER')) {
            $this->advance();
            $this->consumeKeyword('BY');
            $orderBy = $this->parseOrderByList();
        }

        if ($this->matchKeyword('ROWS') || $this->matchKeyword('RANGE')) {
            $frameType = strtoupper($this->current()->value);
            $this->advance();

            if ($this->matchKeyword('BETWEEN')) {
                $this->advance();
                $frameStart = $this->parseFrameBound();
                $this->consumeKeyword('AND');
                $frameEnd = $this->parseFrameBound();
            } else {
                $frameStart = $this->parseFrameBound();
            }
        }

        return new WindowSpecification($partitionBy, $orderBy, $frameType, $frameStart, $frameEnd);
    }

    private function parseFrameBound(): string
    {
        if ($this->matchKeyword('UNBOUNDED')) {
            $this->advance();
            if ($this->matchKeyword('PRECEDING')) {
                $this->advance();
                return 'UNBOUNDED PRECEDING';
            }
            if ($this->matchKeyword('FOLLOWING')) {
                $this->advance();
                return 'UNBOUNDED FOLLOWING';
            }
            throw new Exception(
                "Expected PRECEDING or FOLLOWING after UNBOUNDED at position {$this->current()->position}"
            );
        }

        if ($this->matchKeyword('CURRENT')) {
            $this->advance();
            $this->consumeKeyword('ROW');
            return 'CURRENT ROW';
        }

        if ($this->current()->type === TokenType::Integer) {
            $n = $this->current()->value;
            $this->advance();
            if ($this->matchKeyword('PRECEDING')) {
                $this->advance();
                return $n . ' PRECEDING';
            }
            if ($this->matchKeyword('FOLLOWING')) {
                $this->advance();
                return $n . ' FOLLOWING';
            }
            throw new Exception(
                "Expected PRECEDING or FOLLOWING after number at position {$this->current()->position}"
            );
        }

        throw new Exception(
            "Unexpected frame bound token '{$this->current()->value}' at position {$this->current()->position}"
        );
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function peek(int $offset = 1): Token
    {
        $idx = $this->pos + $offset;
        if ($idx < $this->tokenCount) {
            return $this->tokens[$idx];
        }
        return $this->tokens[$this->tokenCount - 1];
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        if ($this->pos < $this->tokenCount - 1) {
            $this->pos++;
        }
        return $token;
    }

    private function expect(TokenType $type, ?string $value = null): Token
    {
        $token = $this->current();
        if ($token->type !== $type) {
            $expected = $value !== null ? "'{$value}'" : $type->name;
            throw new Exception(
                "Expected {$expected} at position {$token->position}, got '{$token->value}'"
            );
        }
        if ($value !== null && strtoupper($token->value) !== strtoupper($value)) {
            throw new Exception(
                "Expected '{$value}' at position {$token->position}, got '{$token->value}'"
            );
        }
        $this->advance();
        return $token;
    }

    private function matchKeyword(string ...$keywords): bool
    {
        $token = $this->current();
        if ($token->type !== TokenType::Keyword) {
            return false;
        }
        $upper = strtoupper($token->value);
        foreach ($keywords as $keyword) {
            if ($upper === strtoupper($keyword)) {
                return true;
            }
        }
        return false;
    }

    private function peekKeyword(int $offset, string $keyword): bool
    {
        $token = $this->peek($offset);
        return $token->type === TokenType::Keyword && strtoupper($token->value) === strtoupper($keyword);
    }

    private function consumeKeyword(string $keyword): Token
    {
        $token = $this->current();
        if ($token->type !== TokenType::Keyword || strtoupper($token->value) !== strtoupper($keyword)) {
            throw new Exception(
                "Expected keyword '{$keyword}' at position {$token->position}, got '{$token->value}'"
            );
        }
        $this->advance();
        return $token;
    }

    private function expectNull(): Token
    {
        $token = $this->current();
        if ($token->type !== TokenType::Null) {
            throw new Exception(
                "Expected NULL at position {$token->position}, got '{$token->value}'"
            );
        }
        $this->advance();
        return $token;
    }

    private function expectIdentifierValue(string $value): Token
    {
        $token = $this->current();
        $upper = strtoupper($value);
        if (
            ($token->type === TokenType::Identifier || $token->type === TokenType::Keyword)
            && strtoupper($token->value) === $upper
        ) {
            $this->advance();
            return $token;
        }
        throw new Exception(
            "Expected '{$value}' at position {$token->position}, got '{$token->value}'"
        );
    }

    private function matchAndConsume(TokenType $type): bool
    {
        if ($this->current()->type === $type) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function expectIdentifier(): string
    {
        $token = $this->current();
        if ($token->type === TokenType::Identifier) {
            $this->advance();
            return $token->value;
        }
        if ($token->type === TokenType::QuotedIdentifier) {
            $this->advance();
            return $this->unquoteIdentifier($token->value);
        }
        if ($token->type === TokenType::Keyword) {
            $this->advance();
            return $token->value;
        }
        throw new Exception(
            "Expected identifier at position {$token->position}, got '{$token->value}' ({$token->type->name})"
        );
    }

    private function extractIdentifier(Token $token): string
    {
        if ($token->type === TokenType::Identifier) {
            return $token->value;
        }
        if ($token->type === TokenType::QuotedIdentifier) {
            return $this->unquoteIdentifier($token->value);
        }
        if ($token->type === TokenType::Keyword) {
            return $token->value;
        }
        throw new Exception(
            "Expected identifier at position {$token->position}, got '{$token->value}' ({$token->type->name})"
        );
    }

    /**
     * Strip the quote delimiters from a quoted identifier token value and
     * un-double any doubled delimiters inside (SQL convention for escaping
     * the delimiter character within a quoted identifier).
     */
    private function unquoteIdentifier(string $raw): string
    {
        $open = $raw[0];
        $inner = substr($raw, 1, -1);

        return match ($open) {
            '`' => str_replace('``', '`', $inner),
            '"' => str_replace('""', '"', $inner),
            '[' => str_replace(']]', ']', $inner),
            default => $inner,
        };
    }
}
