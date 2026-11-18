<?php

namespace Utopia\Query\AST\Call;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Grammar;
use Utopia\Query\Exception\ValidationException;

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
        if (!Grammar::isIdentifier($name)) {
            throw new ValidationException('Invalid function name: ' . $name);
        }
    }
}
