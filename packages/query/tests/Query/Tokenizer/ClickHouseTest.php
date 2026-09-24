<?php

namespace Tests\Query\Tokenizer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Tokenizer\ClickHouse;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

class ClickHouseTest extends TestCase
{
    private ClickHouse $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new ClickHouse();
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

    public function testBasicTokenization(): void
    {
        $tokens = $this->meaningful('SELECT * FROM users WHERE id = 1');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Star, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier,
                TokenType::Operator, TokenType::Integer, TokenType::Eof,
            ],
            $this->types($tokens)
        );
    }

    public function testBacktickQuoting(): void
    {
        $tokens = $this->meaningful('SELECT `name` FROM `events`');

        $this->assertSame(
            [TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('`name`', $tokens[1]->value);
        $this->assertSame('`events`', $tokens[3]->value);
    }
}
