<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface CTEs
{
    /**
     * @param  list<string>  $columns
     */
    public function with(string $name, \Utopia\Query\Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    public function withRecursive(string $name, \Utopia\Query\Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    public function withRecursiveSeedStep(string $name, \Utopia\Query\Builder $seed, \Utopia\Query\Builder $step, array $columns = []): static;
}
