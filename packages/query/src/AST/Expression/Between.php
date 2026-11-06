<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class Between implements Expression
{
    public function __construct(
        public Expression $expression,
        public Expression $low,
        public Expression $high,
        public bool $negated = false,
    ) {
    }
}
