<?php

namespace Utopia\Query\AST;

use Utopia\Query\AST\Statement\Select;

readonly class SubquerySource
{
    public function __construct(
        public Select $query,
        public string $alias,
    ) {
    }
}
