<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class Binary implements Expression
{
    public function __construct(
        public Expression $left,
        public string $operator,
        public Expression $right,
    ) {
    }
}
