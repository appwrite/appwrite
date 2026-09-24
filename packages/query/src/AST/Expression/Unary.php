<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class Unary implements Expression
{
    public function __construct(
        public string $operator,
        public Expression $operand,
        public bool $prefix = true,
    ) {
    }
}
