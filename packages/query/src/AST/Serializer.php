<?php

namespace Utopia\Query\AST;

use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\Binary;
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

class Serializer
{
    public function serialize(Select $stmt): string
    {
        $parts = [];

        if (!empty($stmt->ctes)) {
            $parts[] = $this->serializeCtes($stmt->ctes);
        }

        $select = 'SELECT';
        if ($stmt->distinct) {
            $select .= ' DISTINCT';
        }

        $columns = [];
        foreach ($stmt->columns as $col) {
            $columns[] = $this->serializeExpression($col);
        }
        $select .= ' ' . implode(', ', $columns);
        $parts[] = $select;

        if ($stmt->from !== null) {
            $parts[] = 'FROM ' . $this->serializeTableSource($stmt->from);
        }

        foreach ($stmt->joins as $join) {
            $parts[] = $this->serializeJoin($join);
        }

        if ($stmt->where !== null) {
            $parts[] = 'WHERE ' . $this->serializeExpression($stmt->where);
        }

        if (!empty($stmt->groupBy)) {
            $expressions = [];
            foreach ($stmt->groupBy as $expression) {
                $expressions[] = $this->serializeExpression($expression);
            }
            $parts[] = 'GROUP BY ' . implode(', ', $expressions);
        }

        if ($stmt->having !== null) {
            $parts[] = 'HAVING ' . $this->serializeExpression($stmt->having);
        }

        if (!empty($stmt->windows)) {
            $defs = [];
            foreach ($stmt->windows as $win) {
                $defs[] = $this->quoteIdentifier($win->name) . ' AS (' . $this->serializeWindowSpecification($win->specification) . ')';
            }
            $parts[] = 'WINDOW ' . implode(', ', $defs);
        }

        if (!empty($stmt->orderBy)) {
            $items = [];
            foreach ($stmt->orderBy as $item) {
                $items[] = $this->serializeOrderByItem($item);
            }
            $parts[] = 'ORDER BY ' . implode(', ', $items);
        }

        if ($stmt->limit !== null) {
            $parts[] = 'LIMIT ' . $this->serializeExpression($stmt->limit);
        }

        if ($stmt->offset !== null) {
            $parts[] = 'OFFSET ' . $this->serializeExpression($stmt->offset);
        }

        return implode(' ', $parts);
    }

    public function serializeExpression(Expression $expression): string
    {
        return match (true) {
            $expression instanceof Aliased => $this->serializeExpression($expression->expression) . ' AS ' . $this->quoteIdentifier($expression->alias),
            $expression instanceof Window => $this->serializeWindowExpression($expression),
            $expression instanceof Binary => $this->serializeBinary($expression, null),
            $expression instanceof Unary => $this->serializeUnary($expression),
            $expression instanceof Column => $this->serializeColumnReference($expression),
            $expression instanceof Literal => $this->serializeLiteral($expression),
            $expression instanceof Star => $this->serializeStar($expression),
            $expression instanceof Placeholder => $expression->value,
            $expression instanceof Raw => $expression->sql,
            $expression instanceof Func => $this->serializeFunctionCall($expression),
            $expression instanceof In => $this->serializeIn($expression),
            $expression instanceof Between => $this->serializeBetween($expression),
            $expression instanceof Exists => $this->serializeExists($expression),
            $expression instanceof Conditional => $this->serializeConditional($expression),
            $expression instanceof Cast => $this->serializeCast($expression),
            $expression instanceof Subquery => '(' . $this->serialize($expression->query) . ')',
            default => throw new Exception('Unsupported expression type: ' . get_class($expression)),
        };
    }

    protected function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function operatorPrecedence(string $op): int
    {
        return match (strtoupper($op)) {
            'OR' => 1,
            'AND' => 2,
            '=', '!=', '<>', '<', '>', '<=', '>=' => 3,
            'LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE' => 3,
            '+', '-', '||' => 4,
            '*', '/', '%' => 5,
            default => 0,
        };
    }

    private function serializeBinary(Binary $expression, ?int $parentPrecedence): string
    {
        $prec = $this->operatorPrecedence($expression->operator);

        // Non-commutative left-associative operators require a stricter precedence
        // on the right child so equal-precedence right subtrees keep their grouping.
        $rightPrec = in_array(strtoupper($expression->operator), ['-', '/', '%'], true)
            ? $prec + 1
            : $prec;

        $left = $this->serializeBinaryChild($expression->left, $prec);
        $right = $this->serializeBinaryChild($expression->right, $rightPrec);

        $sql = $left . ' ' . $expression->operator . ' ' . $right;

        if ($parentPrecedence !== null && $prec < $parentPrecedence) {
            return '(' . $sql . ')';
        }

        return $sql;
    }

    private function serializeBinaryChild(Expression $child, int $parentPrecedence): string
    {
        if ($child instanceof Binary) {
            return $this->serializeBinary($child, $parentPrecedence);
        }

        return $this->serializeExpression($child);
    }

    private function serializeUnary(Unary $expression): string
    {
        if ($expression->prefix) {
            $operand = $this->serializeExpression($expression->operand);
            // Always separate prefix operator from operand with parens so a
            // negative numeric literal or nested unary cannot produce '--',
            // which MySQL parses as the start of a line comment.
            return $expression->operator . ' (' . $operand . ')';
        }

        $operand = $this->serializeExpression($expression->operand);
        return $operand . ' ' . $expression->operator;
    }

    private function serializeColumnReference(Column $expression): string
    {
        $parts = [];
        if ($expression->schema !== null) {
            $parts[] = $this->quoteIdentifier($expression->schema);
        }
        if ($expression->table !== null) {
            $parts[] = $this->quoteIdentifier($expression->table);
        }
        $parts[] = $this->quoteIdentifier($expression->name);
        return implode('.', $parts);
    }

    private function serializeLiteral(Literal $expression): string
    {
        if ($expression->value === null) {
            return 'NULL';
        }
        if (is_bool($expression->value)) {
            return $expression->value ? 'TRUE' : 'FALSE';
        }
        if (is_int($expression->value)) {
            return (string) $expression->value;
        }
        if (is_float($expression->value)) {
            return (string) $expression->value;
        }
        $escaped = str_replace(['\\', "'"], ['\\\\', "''"], $expression->value);
        return "'" . $escaped . "'";
    }

    private function serializeStar(Star $expression): string
    {
        if ($expression->schema !== null && $expression->table !== null) {
            return $this->quoteIdentifier($expression->schema) . '.' . $this->quoteIdentifier($expression->table) . '.*';
        }
        if ($expression->table !== null) {
            return $this->quoteIdentifier($expression->table) . '.*';
        }
        return '*';
    }

    private function serializeFunctionCall(Func $expression): string
    {
        if (count($expression->arguments) === 1 && $expression->arguments[0] instanceof Star) {
            return $expression->name . '(*)';
        }

        if (empty($expression->arguments)) {
            return $expression->name . '()';
        }

        $args = [];
        foreach ($expression->arguments as $arg) {
            $args[] = $this->serializeExpression($arg);
        }

        $prefix = $expression->distinct ? 'DISTINCT ' : '';
        $sql = $expression->name . '(' . $prefix . implode(', ', $args) . ')';

        if ($expression->filter !== null) {
            $sql .= ' FILTER (WHERE ' . $this->serializeExpression($expression->filter) . ')';
        }

        return $sql;
    }

    private function serializeIn(In $expression): string
    {
        $left = $this->serializeExpression($expression->expression);
        $keyword = $expression->negated ? 'NOT IN' : 'IN';

        if ($expression->list instanceof Select) {
            return $left . ' ' . $keyword . ' (' . $this->serialize($expression->list) . ')';
        }

        $items = [];
        foreach ($expression->list as $item) {
            $items[] = $this->serializeExpression($item);
        }
        return $left . ' ' . $keyword . ' (' . implode(', ', $items) . ')';
    }

    private function serializeBetween(Between $expression): string
    {
        $left = $this->serializeExpression($expression->expression);
        $keyword = $expression->negated ? 'NOT BETWEEN' : 'BETWEEN';
        $low = $this->serializeExpression($expression->low);
        $high = $this->serializeExpression($expression->high);
        return $left . ' ' . $keyword . ' ' . $low . ' AND ' . $high;
    }

    private function serializeExists(Exists $expression): string
    {
        $keyword = $expression->negated ? 'NOT EXISTS' : 'EXISTS';
        return $keyword . ' (' . $this->serialize($expression->subquery) . ')';
    }

    private function serializeConditional(Conditional $expression): string
    {
        $sql = 'CASE';
        if ($expression->operand !== null) {
            $sql .= ' ' . $this->serializeExpression($expression->operand);
        }

        foreach ($expression->whens as $when) {
            $sql .= ' WHEN ' . $this->serializeExpression($when->condition);
            $sql .= ' THEN ' . $this->serializeExpression($when->result);
        }

        if ($expression->else !== null) {
            $sql .= ' ELSE ' . $this->serializeExpression($expression->else);
        }

        $sql .= ' END';
        return $sql;
    }

    private function serializeCast(Cast $expression): string
    {
        return 'CAST(' . $this->serializeExpression($expression->expression) . ' AS ' . $expression->type . ')';
    }

    private function serializeWindowExpression(Window $expression): string
    {
        $function = $this->serializeExpression($expression->function);

        if ($expression->windowName !== null) {
            return $function . ' OVER ' . $this->quoteIdentifier($expression->windowName);
        }

        if ($expression->specification !== null) {
            return $function . ' OVER (' . $this->serializeWindowSpecification($expression->specification) . ')';
        }

        return $function . ' OVER ()';
    }

    private function serializeWindowSpecification(WindowSpecification $specification): string
    {
        $parts = [];

        if (!empty($specification->partitionBy)) {
            $expressions = [];
            foreach ($specification->partitionBy as $expression) {
                $expressions[] = $this->serializeExpression($expression);
            }
            $parts[] = 'PARTITION BY ' . implode(', ', $expressions);
        }

        if (!empty($specification->orderBy)) {
            $items = [];
            foreach ($specification->orderBy as $item) {
                $items[] = $this->serializeOrderByItem($item);
            }
            $parts[] = 'ORDER BY ' . implode(', ', $items);
        }

        if ($specification->frameType !== null) {
            $frame = $specification->frameType;
            if ($specification->frameEnd !== null) {
                $frame .= ' BETWEEN ' . $specification->frameStart . ' AND ' . $specification->frameEnd;
            } else {
                $frame .= ' ' . $specification->frameStart;
            }
            $parts[] = $frame;
        }

        return implode(' ', $parts);
    }

    private function serializeOrderByItem(OrderByItem $item): string
    {
        $sql = $this->serializeExpression($item->expression) . ' ' . $item->direction->value;
        if ($item->nulls !== null) {
            $sql .= ' NULLS ' . $item->nulls->value;
        }
        return $sql;
    }

    private function serializeTableSource(Table|SubquerySource $source): string
    {
        if ($source instanceof SubquerySource) {
            return '(' . $this->serialize($source->query) . ') AS ' . $this->quoteIdentifier($source->alias);
        }

        return $this->serializeTableReference($source);
    }

    private function serializeTableReference(Table $reference): string
    {
        $sql = '';
        if ($reference->schema !== null) {
            $sql .= $this->quoteIdentifier($reference->schema) . '.';
        }
        $sql .= $this->quoteIdentifier($reference->name);
        if ($reference->alias !== null) {
            $sql .= ' AS ' . $this->quoteIdentifier($reference->alias);
        }
        return $sql;
    }

    private function serializeJoin(JoinClause $join): string
    {
        $sql = $join->type . ' ' . $this->serializeTableSource($join->table);
        if ($join->condition !== null) {
            $sql .= ' ON ' . $this->serializeExpression($join->condition);
        }
        return $sql;
    }

    /**
     * @param Cte[] $ctes
     */
    private function serializeCtes(array $ctes): string
    {
        $recursive = false;
        foreach ($ctes as $cte) {
            if ($cte->recursive) {
                $recursive = true;
                break;
            }
        }

        $defs = [];
        foreach ($ctes as $cte) {
            $def = $this->quoteIdentifier($cte->name);
            if (!empty($cte->columns)) {
                $cols = [];
                foreach ($cte->columns as $col) {
                    $cols[] = $this->quoteIdentifier($col);
                }
                $def .= ' (' . implode(', ', $cols) . ')';
            }
            $def .= ' AS (' . $this->serialize($cte->query) . ')';
            $defs[] = $def;
        }

        $keyword = $recursive ? 'WITH RECURSIVE' : 'WITH';
        return $keyword . ' ' . implode(', ', $defs);
    }
}
