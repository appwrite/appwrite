<?php

namespace Utopia\Query\AST\Expression;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\Specification\Window as WindowSpecification;

readonly class Window implements Expression
{
    public function __construct(
        public Expression $function,
        public ?string $windowName = null,
        public ?WindowSpecification $specification = null,
    ) {
    }
}
