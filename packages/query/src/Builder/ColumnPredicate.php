<?php

namespace Utopia\Query\Builder;

readonly class ColumnPredicate
{
    public function __construct(
        public string $left,
        public string $operator,
        public string $right,
    ) {
    }
}
