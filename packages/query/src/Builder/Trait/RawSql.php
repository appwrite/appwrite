<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\ColumnPredicate;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Exception\ValidationException;

trait RawSql
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        return $this->select($expression, $bindings);
    }

    #[\Override]
    public function selectCast(string $column, string $type, string $alias = ''): static
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$/', $type)) {
            throw new ValidationException('Invalid cast type: ' . $type);
        }

        $expr = 'CAST(' . $this->resolveAndWrap($column) . ' AS ' . $type . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }
        $this->rawSelects[] = new Condition($expr, []);

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function orderByRaw(string $expression, array $bindings = []): static
    {
        $this->rawOrders[] = new Condition($expression, $bindings);

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function groupByRaw(string $expression, array $bindings = []): static
    {
        $this->rawGroups[] = new Condition($expression, $bindings);

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): static
    {
        $this->rawHavings[] = new Condition($expression, $bindings);

        return $this;
    }

    /**
     * Append a raw WHERE fragment with its own bindings.
     *
     * Caller owns the SQL fragment - no column or operator validation is performed.
     * Use this sparingly; prefer `filter()` with typed `Query::*` factories when possible.
     *
     * @param  list<mixed>  $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static
    {
        $this->rawWheres[] = new Condition($expression, $bindings);

        return $this;
    }

    /**
     * Append a column-to-column WHERE predicate (e.g. `users.id = orders.user_id`).
     *
     * Both columns are quoted per dialect. The operator is validated against
     * an allowlist: =, !=, <>, <, >, <=, >=.
     */
    public function whereColumn(string $left, string $operator, string $right): static
    {
        if (! \in_array($operator, self::COLUMN_PREDICATE_OPERATORS, true)) {
            throw new ValidationException('Invalid whereColumn operator: ' . $operator);
        }

        $this->columnPredicates[] = new ColumnPredicate($left, $operator, $right);

        return $this;
    }

    public function selectCase(CaseExpression $case): static
    {
        $this->cases[] = $case;

        return $this;
    }

    public function setCase(string $column, CaseExpression $case): static
    {
        $this->caseSets[$column] = $case;

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    public function setRaw(string $column, string $expression, array $bindings = []): static
    {
        $this->rawSets[$column] = $expression;
        $this->rawSetBindings[$column] = $bindings;

        return $this;
    }

    /**
     * @param  list<mixed>  $bindings
     */
    public function conflictSetRaw(string $column, string $expression, array $bindings = []): static
    {
        $this->conflictRawSets[$column] = $expression;
        $this->conflictRawSetBindings[$column] = $bindings;

        return $this;
    }

    /**
     * Register a raw expression wrapper for a column in INSERT statements.
     *
     * The expression must contain exactly one `?` placeholder which will receive
     * the column's value from each row. E.g. `ST_GeomFromText(?, 4326)`.
     *
     * @param  list<mixed>  $extraBindings  Additional bindings beyond the column value (e.g. SRID)
     */
    public function insertColumnExpression(string $column, string $expression, array $extraBindings = []): static
    {
        $this->insertColumnExpressions[$column] = $expression;
        if (! empty($extraBindings)) {
            $this->insertColumnExpressionBindings[$column] = $extraBindings;
        }

        return $this;
    }
}
