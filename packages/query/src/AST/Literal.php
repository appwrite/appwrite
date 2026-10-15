<?php

namespace Utopia\Query\AST;

readonly class Literal implements Expression
{
    public function __construct(
        public string|int|float|bool|null $value,
    ) {
    }
}
