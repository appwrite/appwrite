<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder\Case\Expression as CaseExpression;

/**
 * Splicing raw SQL fragments and CASE expressions into a statement.
 *
 * Every dialect whose statements are SQL text implements this. The MongoDB
 * builder does not: it emits operation documents, so there is no position in
 * its output where a SQL fragment could be placed.
 */
interface RawSql
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static;

    public function selectCast(string $column, string $type, string $alias = ''): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function orderByRaw(string $expression, array $bindings = []): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function groupByRaw(string $expression, array $bindings = []): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): static;

    public function whereColumn(string $left, string $operator, string $right): static;

    public function selectCase(CaseExpression $case): static;

    public function setCase(string $column, CaseExpression $case): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function setRaw(string $column, string $expression, array $bindings = []): static;

    /**
     * @param  list<mixed>  $bindings
     */
    public function conflictSetRaw(string $column, string $expression, array $bindings = []): static;

    /**
     * @param  list<mixed>  $extraBindings
     */
    public function insertColumnExpression(string $column, string $expression, array $extraBindings = []): static;
}
