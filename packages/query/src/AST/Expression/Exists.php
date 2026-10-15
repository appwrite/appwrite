<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Statement\Select;

readonly class Exists implements Expression
{
    public function __construct(
        public Select $subquery,
        public bool $negated = false,
    ) {
    }
}
