<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface AggregateFilter
{
    /**
     * Add an aggregate expression with a FILTER (WHERE ...) clause to the SELECT.
     *
     * @param  list<mixed>  $bindings
     */
    public function selectAggregateFilter(string $aggregateExpr, string $filterCondition, string $alias = '', array $bindings = []): static;
}
