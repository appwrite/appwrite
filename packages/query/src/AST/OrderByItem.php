<?php

namespace Utopia\Query\AST;

use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;

readonly class OrderByItem
{
    public function __construct(
        public Expression $expression,
        public OrderDirection $direction = OrderDirection::Asc,
        public ?NullsPosition $nulls = null,
    ) {
    }
}
