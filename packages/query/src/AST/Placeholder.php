<?php

namespace Utopia\Query\AST;

readonly class Placeholder implements Expression
{
    public function __construct(
        public string $value,
    ) {
    }
}
