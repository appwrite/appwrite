<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class Aliased implements Expression
{
    public function __construct(
        public Expression $expression,
        public string $alias,
    ) {
    }
}
