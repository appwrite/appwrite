<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST\Serializer;

use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\Cast;
use Utopia\Query\AST\Expression\Conditional;
use Utopia\Query\AST\Expression\Exists;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Expression\Subquery;
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Serializer as BaseSerializer;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Exception;
use Utopia\Query\StringLiteral;
class PostgreSQL extends \Utopia\Query\AST\Serializer
{
    public function serialize(Select $stmt): string
    {
    }
    public function serializeExpression(Expression $expression): string
    {
    }
    #[\Override]
    protected function quoteIdentifier(string $name): string
    {
    }
    public function __construct(StringLiteral $literal = StringLiteral::Extended)
    {
    }
}
