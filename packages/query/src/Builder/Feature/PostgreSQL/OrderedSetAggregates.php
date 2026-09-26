<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface OrderedSetAggregates
{
    public function arrayAgg(string $column, string $alias = ''): static;

    public function boolAnd(string $column, string $alias = ''): static;

    public function boolOr(string $column, string $alias = ''): static;

    public function every(string $column, string $alias = ''): static;

    /**
     * Emit `mode() WITHIN GROUP (ORDER BY <column>)` — the most frequent value.
     *
     * Adds to SELECT. Column is quoted per dialect.
     */
    public function mode(string $column, string $alias = ''): static;

    public function percentileCont(float $fraction, string $orderColumn, string $alias = ''): static;

    public function percentileDisc(float $fraction, string $orderColumn, string $alias = ''): static;
}
