<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class Conditional implements Expression
{
    /**
     * @param CaseWhen[] $whens
     */
    public function __construct(
        public ?Expression $operand,
        public array $whens,
        public ?Expression $else = null,
    ) {
    }
}
