<?php

namespace Utopia\Query\Tokenizer;

use Utopia\Query\StringLiteral;

class ClickHouse extends Tokenizer
{
    public function __construct(StringLiteral $literal = StringLiteral::BackslashUtf8)
    {
        parent::__construct($literal);
    }
}
