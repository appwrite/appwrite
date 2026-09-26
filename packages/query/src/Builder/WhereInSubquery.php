<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Builder;

readonly class WhereInSubquery
{
    public function __construct(
        public string $column,
        public Builder $subquery,
        public bool $not,
    ) {
    }
}
