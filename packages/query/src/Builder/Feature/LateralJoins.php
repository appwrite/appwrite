<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Builder;
use Utopia\Query\Builder\JoinType;

interface LateralJoins
{
    public function joinLateral(Builder $subquery, string $alias, JoinType $type = JoinType::Inner): static;

    public function leftJoinLateral(Builder $subquery, string $alias): static;
}
