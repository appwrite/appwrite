<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST\Serializer;

use Utopia\Query\AST\Statement\Select;
use Utopia\Query\StringLiteral;

class MySQL extends \Utopia\Query\AST\Serializer
{
    public function serialize(Select $stmt): string
    {
    }
    public function serializeExpression(Expression $expression): string
    {
    }
    protected function quoteIdentifier(string $name): string
    {
    }
    public function __construct(protected readonly StringLiteral $literal = StringLiteral::Backslash)
    {
    }
}
