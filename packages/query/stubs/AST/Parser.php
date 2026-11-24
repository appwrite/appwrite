<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST;

use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Definition\Cte;
use Utopia\Query\AST\Definition\Window as WindowDefinition;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Expression\Between;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Expression\CaseWhen;
use Utopia\Query\AST\Expression\Cast;
use Utopia\Query\AST\Expression\Conditional;
use Utopia\Query\AST\Expression\Exists;
use Utopia\Query\AST\Expression\In;
use Utopia\Query\AST\Expression\Subquery;
use Utopia\Query\AST\Expression\Unary;
use Utopia\Query\AST\Expression\Window;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Specification\Window as WindowSpecification;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Exception;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;
use Utopia\Query\StringLiteral;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\TokenType;
/**
 * Recursive-descent SQL parser.
 *
 * Consumes filtered {@see Token}s from a {@see \Utopia\Query\Tokenizer\Tokenizer}
 * and builds a {@see Select} tree that can be inspected, rewritten via
 * {@see Walker} and {@see Visitor}, and re-serialized by {@see Serializer}.
 *
 * Not to be confused with {@see \Utopia\Query\Classifier}, which only reads a
 * message's leading keyword to route it to a primary or replica.
 */
class Parser
{
    /**
     * Parse tokens into a Select.
     * @param Token[] $tokens filtered tokens (no whitespace/comments), must end with Eof
     */
    public function parse(array $tokens): Select
    {
    }
    public function __construct(private readonly StringLiteral $literal = StringLiteral::Backslash)
    {
    }
}
