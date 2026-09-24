<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;

readonly class CaseWhen implements Expression
{
    public function __construct(
        public Expression $condition,
        public Expression $result,
    ) {
    }
}
