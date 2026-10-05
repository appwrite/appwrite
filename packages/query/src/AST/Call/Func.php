<?php

namespace Utopia\Query\AST\Call;

use Utopia\Query\AST\Expression;

readonly class Func implements Expression
{
    /**
     * @param Expression[] $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public bool $distinct = false,
        public ?Expression $filter = null,
    ) {
    }
}
