<?php

namespace Utopia\Query\AST\Serializer;

use Utopia\Query\AST\Serializer as BaseSerializer;
use Utopia\Query\StringLiteral;

class PostgreSQL extends BaseSerializer
{
    public function __construct(StringLiteral $literal = StringLiteral::Extended)
    {
        parent::__construct($literal);
    }

    #[\Override]
    protected function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
