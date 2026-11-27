<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Unions
{
    public function union(\Utopia\Query\Builder $other): static;
    public function unionAll(\Utopia\Query\Builder $other): static;
    public function intersect(\Utopia\Query\Builder $other): static;
    public function intersectAll(\Utopia\Query\Builder $other): static;
    public function except(\Utopia\Query\Builder $other): static;
    public function exceptAll(\Utopia\Query\Builder $other): static;
}
