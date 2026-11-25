<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface LateralJoins
{
    public function joinLateral(\Utopia\Query\Builder $subquery, string $alias, \Utopia\Query\Builder\JoinType $type = \Utopia\Query\Builder\JoinType::Inner): static;
    public function leftJoinLateral(\Utopia\Query\Builder $subquery, string $alias): static;
}
