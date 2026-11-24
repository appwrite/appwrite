<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder;
interface CTEs
{
    /**
     * @param  list<string>  $columns
     */
    public function with(string $name, Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    public function withRecursive(string $name, Builder $query, array $columns = []): static;
    /**
     * @param  list<string>  $columns
     */
    public function withRecursiveSeedStep(string $name, Builder $seed, Builder $step, array $columns = []): static;
}
