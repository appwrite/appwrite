<?php

namespace Utopia\Query\AST\Reference;

use Utopia\Query\AST\Expression;

readonly class Column implements Expression
{
    public function __construct(
        public string $name,
        public ?string $table = null,
        public ?string $schema = null,
    ) {
    }
}
