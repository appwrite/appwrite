<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Statement\Select;

readonly class In implements Expression
{
    /**
     * @param Expression[]|Select $list
     */
    public function __construct(
        public Expression $expression,
        public array|Select $list,
        public bool $negated = false,
    ) {
    }
}
