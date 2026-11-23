<?php

namespace Utopia\Query\Tokenizer;

readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $position,
    ) {
    }
}
