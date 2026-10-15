<?php

namespace Utopia\Query\AST;

use Utopia\Query\AST\Reference\Table;

readonly class JoinClause
{
    public function __construct(
        public string $type,
        public Table|SubquerySource $table,
        public ?Expression $condition = null,
    ) {
    }
}
