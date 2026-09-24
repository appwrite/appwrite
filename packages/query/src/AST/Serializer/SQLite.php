<?php

namespace Utopia\Query\AST\Serializer;

use Utopia\Query\AST\Serializer as BaseSerializer;

class SQLite extends BaseSerializer
{
    #[\Override]
    protected function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
