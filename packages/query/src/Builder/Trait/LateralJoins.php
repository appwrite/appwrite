<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Builder as BaseBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\LateralJoin;

trait LateralJoins
{
    #[\Override]
    public function joinLateral(BaseBuilder $subquery, string $alias, JoinType $type = JoinType::Inner): static
    {
        $this->lateralJoins[] = new LateralJoin($subquery, $alias, $type);

        return $this;
    }

    #[\Override]
    public function leftJoinLateral(BaseBuilder $subquery, string $alias): static
    {
        return $this->joinLateral($subquery, $alias, JoinType::Left);
    }
}
