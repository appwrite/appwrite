<?php

namespace Utopia\Query\Builder\Trait\PostgreSQL;

trait AggregateFilter
{
    /**
     * @param  list<mixed>  $bindings
     */
    #[\Override]
    public function selectAggregateFilter(string $aggregateExpr, string $filterCondition, string $alias = '', array $bindings = []): static
    {
        $expr = $aggregateExpr . ' FILTER (WHERE ' . $filterCondition . ')';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, $bindings);
    }
}
