<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Tokenizer;

class MySQL extends \Utopia\Query\Tokenizer\Tokenizer
{
    /**
     * @return Token[]
     */
    public function tokenize(string $sql): array
    {
    }
    /**
     * @param Token[] $tokens
     * @return Token[]
     */
    public static function filter(array $tokens): array
    {
    }
    protected function getIdentifierQuoteChar(): string
    {
    }
    public function __construct(protected readonly \Utopia\Query\StringLiteral $literal = \Utopia\Query\StringLiteral::Backslash)
    {
    }
}
