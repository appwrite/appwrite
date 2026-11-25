<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST\Serializer;

class MariaDB extends \Utopia\Query\AST\Serializer\MySQL
{
    public function serialize(\Utopia\Query\AST\Statement\Select $stmt): string
    {
    }
    public function serializeExpression(\Utopia\Query\AST\Expression $expression): string
    {
    }
    protected function quoteIdentifier(string $name): string
    {
    }
    public function __construct(protected readonly \Utopia\Query\StringLiteral $literal = \Utopia\Query\StringLiteral::Backslash)
    {
    }
}
