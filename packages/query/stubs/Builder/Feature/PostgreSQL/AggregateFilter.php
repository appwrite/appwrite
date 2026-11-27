<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

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
