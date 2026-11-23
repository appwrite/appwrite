<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder;

readonly class LateralJoin
{
    public function __construct(
        public Builder $subquery,
        public string $alias,
        public JoinType $type,
    ) {
    }
}
