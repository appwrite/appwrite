<?php

namespace Utopia\Query\Builder\Feature;

interface ConditionalAggregates
{
    public function countWhen(string $condition, string $alias = '', mixed ...$bindings): static;

    public function sumWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static;

    public function avgWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static;

    public function minWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static;

    public function maxWhen(string $column, string $condition, string $alias = '', mixed ...$bindings): static;
}
