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
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Statement\Select;

class Walker
{
    /**
     * Walk the entire AST, applying the visitor to every node.
     * Returns a new (possibly transformed) Select.
     */
    public function walk(Select $stmt, Visitor $visitor): Select
    {
        $stmt = $this->walkStatement($stmt, $visitor);
        return $visitor->visitSelect($stmt);
    }

    private function walkStatement(Select $stmt, Visitor $visitor): Select
    {
        $columns = $this->walkExpressionArray($stmt->columns, $visitor);
        $columnsChanged = $columns !== $stmt->columns;

        $from = $stmt->from;
        if ($from instanceof Table) {
            $from = $visitor->visitTableReference($from);
        } elseif ($from instanceof SubquerySource) {
            $from = $this->walkSubquerySource($from, $visitor);
        }
        $fromChanged = $from !== $stmt->from;

        $joins = [];
        $joinsChanged = false;
        foreach ($stmt->joins as $i => $join) {
            $walkedJoin = $this->walkJoin($join, $visitor);
            if ($walkedJoin !== $join) {
                $joinsChanged = true;
            }
            $joins[$i] = $walkedJoin;
        }

        $where = $stmt->where !== null ? $this->walkExpression($stmt->where, $visitor) : null;
        $whereChanged = $where !== $stmt->where;

        $groupBy = $this->walkExpressionArray($stmt->groupBy, $visitor);
        $groupByChanged = $groupBy !== $stmt->groupBy;

        $having = $stmt->having !== null ? $this->walkExpression($stmt->having, $visitor) : null;
        $havingChanged = $having !== $stmt->having;

        $orderBy = [];
        $orderByChanged = false;
        foreach ($stmt->orderBy as $i => $item) {
            $walkedItem = $this->walkOrderByItem($item, $visitor);
            if ($walkedItem !== $item) {
                $orderByChanged = true;
            }
            $orderBy[$i] = $walkedItem;
        }

        $limit = $stmt->limit !== null ? $this->walkExpression($stmt->limit, $visitor) : null;
        $limitChanged = $limit !== $stmt->limit;

        $offset = $stmt->offset !== null ? $this->walkExpression($stmt->offset, $visitor) : null;
        $offsetChanged = $offset !== $stmt->offset;

        $ctes = [];
        $ctesChanged = false;
        foreach ($stmt->ctes as $i => $cte) {
            $walkedCte = $this->walkCte($cte, $visitor);
            if ($walkedCte !== $cte) {
                $ctesChanged = true;
            }
            $ctes[$i] = $walkedCte;
        }

        $windows = [];
        $windowsChanged = false;
        foreach ($stmt->windows as $i => $win) {
            $walkedWin = $this->walkWindowDefinition($win, $visitor);
            if ($walkedWin !== $win) {
                $windowsChanged = true;
            }
            $windows[$i] = $walkedWin;
        }

        // Identity fast-path: if no child was replaced, return the original
        // Select to avoid allocating a fresh node for pure-inspection visitors.
        if (
            ! $columnsChanged
            && ! $fromChanged
            && ! $joinsChanged
            && ! $whereChanged
            && ! $groupByChanged
            && ! $havingChanged
            && ! $orderByChanged
            && ! $limitChanged
            && ! $offsetChanged
            && ! $ctesChanged
            && ! $windowsChanged
        ) {
            return $stmt;
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
            distinct: $stmt->distinct,
            ctes: $ctes,
            windows: $windows,
        );
    }

    private function walkExpression(Expression $expression, Visitor $visitor): Expression
    {
        $walked = match (true) {
            $expression instanceof Binary => $this->walkBinary($expression, $visitor),
            $expression instanceof Unary => $this->walkUnary($expression, $visitor),
            $expression instanceof Func => $this->walkFunctionCall($expression, $visitor),
            $expression instanceof Aliased => $this->walkAliased($expression, $visitor),
            $expression instanceof In => $this->walkInExpression($expression, $visitor),
            $expression instanceof Between => $this->walkBetween($expression, $visitor),
            $expression instanceof Exists => $this->walkExists($expression, $visitor),
            $expression instanceof Conditional => $this->walkConditionalExpression($expression, $visitor),
            $expression instanceof Cast => $this->walkCast($expression, $visitor),
            $expression instanceof Subquery => $this->walkSubquery($expression, $visitor),
            $expression instanceof Window => $this->walkWindowExpression($expression, $visitor),
            default => $expression,
        };

        return $visitor->visitExpression($walked);
    }

    private function walkBinary(Binary $expression, Visitor $visitor): Binary
    {
        $left = $this->walkExpression($expression->left, $visitor);
        $right = $this->walkExpression($expression->right, $visitor);

        if ($left === $expression->left && $right === $expression->right) {
            return $expression;
        }

        return new Binary($left, $expression->operator, $right);
    }

    private function walkUnary(Unary $expression, Visitor $visitor): Unary
    {
        $operand = $this->walkExpression($expression->operand, $visitor);

        if ($operand === $expression->operand) {
            return $expression;
        }

        return new Unary($expression->operator, $operand, $expression->prefix);
    }

    private function walkAliased(Aliased $expression, Visitor $visitor): Aliased
    {
        $inner = $this->walkExpression($expression->expression, $visitor);

        if ($inner === $expression->expression) {
            return $expression;
        }

        return new Aliased($inner, $expression->alias);
    }

    private function walkBetween(Between $expression, Visitor $visitor): Between
    {
        $inner = $this->walkExpression($expression->expression, $visitor);
        $low = $this->walkExpression($expression->low, $visitor);
        $high = $this->walkExpression($expression->high, $visitor);

        if (
            $inner === $expression->expression
            && $low === $expression->low
            && $high === $expression->high
        ) {
            return $expression;
        }

        return new Between($inner, $low, $high, $expression->negated);
    }

    private function walkExists(Exists $expression, Visitor $visitor): Exists
    {
        $walked = $this->walk($expression->subquery, $visitor);

        if ($walked === $expression->subquery) {
            return $expression;
        }

        return new Exists($walked, $expression->negated);
    }

    private function walkCast(Cast $expression, Visitor $visitor): Cast
    {
        $inner = $this->walkExpression($expression->expression, $visitor);

        if ($inner === $expression->expression) {
            return $expression;
        }

        return new Cast($inner, $expression->type);
    }

    private function walkSubquery(Subquery $expression, Visitor $visitor): Subquery
    {
        $walked = $this->walk($expression->query, $visitor);

        if ($walked === $expression->query) {
            return $expression;
        }

        return new Subquery($walked);
    }

    /**
     * @param Expression[] $expressions
     * @return Expression[]
     */
    private function walkExpressionArray(array $expressions, Visitor $visitor): array
    {
        $result = [];
        $changed = false;
        foreach ($expressions as $i => $expression) {
            $walked = $this->walkExpression($expression, $visitor);
            if ($walked !== $expression) {
                $changed = true;
            }
            $result[$i] = $walked;
        }
        return $changed ? $result : $expressions;
    }

    private function walkFunctionCall(Func $expression, Visitor $visitor): Func
    {
        $args = $this->walkExpressionArray($expression->arguments, $visitor);
        $filter = $expression->filter !== null ? $this->walkExpression($expression->filter, $visitor) : null;

        if ($args === $expression->arguments && $filter === $expression->filter) {
            return $expression;
        }

        return new Func(
            $expression->name,
            $args,
            $expression->distinct,
            $filter,
        );
    }

    private function walkInExpression(In $expression, Visitor $visitor): In
    {
        $walked = $this->walkExpression($expression->expression, $visitor);

        if ($expression->list instanceof Select) {
            $list = $this->walk($expression->list, $visitor);
        } else {
            $list = $this->walkExpressionArray($expression->list, $visitor);
        }

        if ($walked === $expression->expression && $list === $expression->list) {
            return $expression;
        }

        return new In($walked, $list, $expression->negated);
    }

    private function walkConditionalExpression(Conditional $expression, Visitor $visitor): Conditional
    {
        $operand = $expression->operand !== null ? $this->walkExpression($expression->operand, $visitor) : null;
        $operandChanged = $operand !== $expression->operand;

        $whens = [];
        $whensChanged = false;
        foreach ($expression->whens as $i => $when) {
            $condition = $this->walkExpression($when->condition, $visitor);
            $result = $this->walkExpression($when->result, $visitor);

            if ($condition === $when->condition && $result === $when->result) {
                $whens[$i] = $when;
            } else {
                $whens[$i] = new CaseWhen($condition, $result);
                $whensChanged = true;
            }
        }

        $else = $expression->else !== null ? $this->walkExpression($expression->else, $visitor) : null;
        $elseChanged = $else !== $expression->else;

        if (! $operandChanged && ! $whensChanged && ! $elseChanged) {
            return $expression;
        }

        return new Conditional($operand, $whens, $else);
    }

    private function walkWindowExpression(Window $expression, Visitor $visitor): Window
    {
        $function = $this->walkExpression($expression->function, $visitor);
        $specification = $expression->specification !== null ? $this->walkWindowSpecification($expression->specification, $visitor) : null;

        if ($function === $expression->function && $specification === $expression->specification) {
            return $expression;
        }

        return new Window($function, $expression->windowName, $specification);
    }

    private function walkWindowSpecification(WindowSpecification $specification, Visitor $visitor): WindowSpecification
    {
        $partitionBy = $this->walkExpressionArray($specification->partitionBy, $visitor);
        $partitionChanged = $partitionBy !== $specification->partitionBy;

        $orderBy = [];
        $orderByChanged = false;
        foreach ($specification->orderBy as $i => $item) {
            $walkedItem = $this->walkOrderByItem($item, $visitor);
            if ($walkedItem !== $item) {
                $orderByChanged = true;
            }
            $orderBy[$i] = $walkedItem;
        }

        if (! $partitionChanged && ! $orderByChanged) {
            return $specification;
        }

        return new WindowSpecification(
            $partitionBy,
            $orderBy,
            $specification->frameType,
            $specification->frameStart,
            $specification->frameEnd,
        );
    }

    private function walkWindowDefinition(WindowDefinition $win, Visitor $visitor): WindowDefinition
    {
        $specification = $this->walkWindowSpecification($win->specification, $visitor);

        if ($specification === $win->specification) {
            return $win;
        }

        return new WindowDefinition($win->name, $specification);
    }

    private function walkOrderByItem(OrderByItem $item, Visitor $visitor): OrderByItem
    {
        $expression = $this->walkExpression($item->expression, $visitor);

        if ($expression === $item->expression) {
            return $item;
        }

        return new OrderByItem(
            $expression,
            $item->direction,
            $item->nulls,
        );
    }

    private function walkJoin(JoinClause $join, Visitor $visitor): JoinClause
    {
        $table = $join->table;
        if ($table instanceof Table) {
            $table = $visitor->visitTableReference($table);
        } elseif ($table instanceof SubquerySource) {
            $table = $this->walkSubquerySource($table, $visitor);
        }

        $condition = $join->condition !== null ? $this->walkExpression($join->condition, $visitor) : null;

        if ($table === $join->table && $condition === $join->condition) {
            return $join;
        }

        return new JoinClause($join->type, $table, $condition);
    }

    private function walkSubquerySource(SubquerySource $source, Visitor $visitor): SubquerySource
    {
        $walked = $this->walk($source->query, $visitor);

        if ($walked === $source->query) {
            return $source;
        }

        return new SubquerySource($walked, $source->alias);
    }

    private function walkCte(Cte $cte, Visitor $visitor): Cte
    {
        $walkedQuery = $this->walk($cte->query, $visitor);

        if ($walkedQuery === $cte->query) {
            return $cte;
        }

        return new Cte(
            $cte->name,
            $walkedQuery,
            $cte->columns,
            $cte->recursive,
        );
    }
}
