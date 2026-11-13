<?php

namespace Utopia\Query\Tokenizer;

use Utopia\Query\StringLiteral;

class SQLite extends Tokenizer
{
    public function __construct(StringLiteral $literal = StringLiteral::Standard)
    {
        parent::__construct($literal);
    }
}
