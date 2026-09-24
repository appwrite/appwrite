<?php

namespace Utopia\Query\Builder\Trait;

trait ConditionalAggregates
{
    #[\Override]
    public function countWhen(string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('COUNT', null, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function sumWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('SUM', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function avgWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('AVG', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function minWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('MIN', $column, $condition, $alias, \array_values($bindings));
    }

    #[\Override]
    public function maxWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static
    {
        return $this->aggregateFilter('MAX', $column, $condition, $alias, \array_values($bindings));
    }

    /**
     * Emit a conditional aggregate using the portable `CASE WHEN ... THEN ... END` pattern.
     *
     * For COUNT we emit `CASE WHEN cond THEN 1 END` (matching rows counted regardless of column).
     * For other aggregates we emit `CASE WHEN cond THEN column END` (NULL branches excluded by SQL aggregates).
     *
     * @param  list<mixed>  $bindings
     */
    private function aggregateFilter(string $aggregate, ?string $column, string $condition, string $alias, array $bindings): static
    {
        $thenBranch = $column === null ? '1' : $this->resolveAndWrap($column);
        $expr = $aggregate . '(CASE WHEN ' . $condition . ' THEN ' . $thenBranch . ' END)';
        if ($alias !== '') {
            $expr .= ' AS ' . $this->quote($alias);
        }

        return $this->select($expr, $bindings);
    }
}
