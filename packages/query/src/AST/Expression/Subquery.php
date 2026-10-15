<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Statement\Select;

readonly class Subquery implements Expression
{
    public function __construct(
        public Select $query,
    ) {
    }
}
