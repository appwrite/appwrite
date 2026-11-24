<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\JoinType;

interface LateralJoins
{
    public function joinLateral(Builder $subquery, string $alias, JoinType $type = JoinType::Inner): static;
    public function leftJoinLateral(Builder $subquery, string $alias): static;
}
