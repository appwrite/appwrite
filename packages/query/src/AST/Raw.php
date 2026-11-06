<?php

namespace Utopia\Query\AST;

readonly class Raw implements Expression
{
    public function __construct(
        public string $sql,
    ) {
    }
}
