<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST\Serializer;

class SQLite extends \Utopia\Query\AST\Serializer
{
    public function serialize(\Utopia\Query\AST\Statement\Select $stmt): string
    {
    }
    public function serializeExpression(\Utopia\Query\AST\Expression $expression): string
    {
    }
    #[\Override]
    protected function quoteIdentifier(string $name): string
    {
    }
    public function __construct(\Utopia\Query\StringLiteral $literal = \Utopia\Query\StringLiteral::Standard)
    {
    }
}
