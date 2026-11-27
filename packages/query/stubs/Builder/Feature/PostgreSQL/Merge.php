<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface Merge
{
    public function mergeInto(string $target): static;
    public function on(string $condition, mixed ...$bindings): static;
    public function whenMatched(string $action, mixed ...$bindings): static;
    public function whenNotMatched(string $action, mixed ...$bindings): static;
    public function executeMerge(): \Utopia\Query\Builder\Statement;
    public function using(\Utopia\Query\Builder $source, string $alias): static;
}
