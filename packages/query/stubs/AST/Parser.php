<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\AST;

use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Tokenizer\Token;

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
    public function parse(array $tokens): \Utopia\Query\AST\Statement\Select
    {
    }
    public function __construct(private readonly \Utopia\Query\StringLiteral $literal = \Utopia\Query\StringLiteral::Backslash)
    {
    }
}
