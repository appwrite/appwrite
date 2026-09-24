<?php

namespace Tests\Query\Tokenizer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Tokenizer\SQLite;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

class SQLiteTest extends TestCase
{
    private SQLite $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new SQLite();
    }

    /**
     * @return Token[]
     */
    private function meaningful(string $sql): array
    {
        return Tokenizer::filter($this->tokenizer->tokenize($sql));
    }

    /**
     * @param Token[] $tokens
     * @return TokenType[]
     */
    private function types(array $tokens): array
    {
        return array_map(fn (Token $t) => $t->type, $tokens);
    }

    public function testDoubleQuoteIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT "name" FROM "users"');

        $this->assertSame(
            [TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('"name"', $tokens[1]->value);
        $this->assertSame('"users"', $tokens[3]->value);
    }

    public function testDoubleQuoteNotString(): void
    {
        $tokens = $this->meaningful('"col"');

        $this->assertSame(TokenType::QuotedIdentifier, $tokens[0]->type);
        $this->assertSame('"col"', $tokens[0]->value);
        $this->assertNotSame(TokenType::String, $tokens[0]->type);
    }

    public function testDoubleQuoteWithEscapedQuote(): void
    {
        $tokens = $this->meaningful('SELECT "col""name" FROM t');

        $this->assertSame(TokenType::QuotedIdentifier, $tokens[1]->type);
        $this->assertSame('"col""name"', $tokens[1]->value);
    }

    public function testMixedIdentifiersAndStrings(): void
    {
        $tokens = $this->meaningful("SELECT \"name\" FROM \"users\" WHERE status = 'active'");

        $this->assertSame(TokenType::QuotedIdentifier, $tokens[1]->type);
        $this->assertSame('"name"', $tokens[1]->value);
        $this->assertSame(TokenType::QuotedIdentifier, $tokens[3]->type);
        $this->assertSame('"users"', $tokens[3]->value);
        $this->assertSame(TokenType::String, $tokens[7]->type);
        $this->assertSame("'active'", $tokens[7]->value);
    }

    public function testUnquotedIdentifiers(): void
    {
        $tokens = $this->meaningful('SELECT name FROM users');

        $this->assertSame(
            [TokenType::Keyword, TokenType::Identifier, TokenType::Keyword, TokenType::Identifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('name', $tokens[1]->value);
        $this->assertSame('users', $tokens[3]->value);
    }
}
