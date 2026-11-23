<?php

namespace Utopia\Query\Builder;

readonly class JoinOn
{
    public function __construct(
        public string $left,
        public string $operator,
        public string $right,
    ) {
    }
}
