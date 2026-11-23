<?php

namespace Utopia\Query\AST\Definition;

use Utopia\Query\AST\Specification\Window as WindowSpecification;

readonly class Window
{
    public function __construct(
        public string $name,
        public WindowSpecification $specification,
    ) {
    }
}
