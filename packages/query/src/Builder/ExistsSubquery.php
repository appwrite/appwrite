<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder;

readonly class ExistsSubquery
{
    public function __construct(
        public Builder $subquery,
        public bool $not,
    ) {
    }
}
