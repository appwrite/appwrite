<?php

namespace Utopia\Query\AST;

readonly class Star implements Expression
{
    public function __construct(
        public ?string $table = null,
        public ?string $schema = null,
    ) {
    }
}
