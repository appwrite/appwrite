<?php

namespace Utopia\Query\Hook\Join;

use Utopia\Query\Builder\Condition as BuilderCondition;

readonly class Condition
{
    public function __construct(
        public BuilderCondition $condition,
        public Placement $placement = Placement::Where,
    ) {
    }
}
