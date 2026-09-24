<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

use Utopia\Query\Builder;
use Utopia\Query\Builder\Statement;

interface Merge
{
    public function mergeInto(string $target): static;

    public function using(Builder $source, string $alias): static;

    public function on(string $condition, mixed ...$bindings): static;

    public function whenMatched(string $action, mixed ...$bindings): static;

    public function whenNotMatched(string $action, mixed ...$bindings): static;

    public function executeMerge(): Statement;
}
