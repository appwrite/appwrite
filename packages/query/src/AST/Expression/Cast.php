<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\Exception\ValidationException;

readonly class Cast implements Expression
{
    public function __construct(
        public Expression $expression,
        public string $type,
    ) {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$/', $type)) {
            throw new ValidationException('Invalid cast type: ' . $type);
        }
    }
}
