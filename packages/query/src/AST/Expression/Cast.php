<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Grammar;
use Utopia\Query\Exception\ValidationException;

readonly class Cast implements Expression
{
    public function __construct(
        public Expression $expression,
        public string $type,
    ) {
        if (!Grammar::isType($type)) {
            throw new ValidationException('Invalid cast type: ' . $type);
        }
    }
}
