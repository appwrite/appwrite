<?php

namespace Utopia\Query\AST\Reference;

readonly class Table
{
    public function __construct(
        public string $name,
        public ?string $alias = null,
        public ?string $schema = null,
    ) {
    }
}
