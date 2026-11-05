<?php

namespace Utopia\Query\AST\Definition;

use Utopia\Query\AST\Statement\Select;

readonly class Cte
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        public string $name,
        public Select $query,
        public array $columns = [],
        public bool $recursive = false,
    ) {
    }
}
