<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder;

readonly class SubSelect
{
    public function __construct(
        public Builder $subquery,
        public string $alias,
    ) {
    }
}
