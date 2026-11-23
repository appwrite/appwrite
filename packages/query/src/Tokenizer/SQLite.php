<?php

namespace Utopia\Query\Tokenizer;

class SQLite extends Tokenizer
{
    protected function getIdentifierQuoteChar(): string
    {
        return '"';
    }
}
