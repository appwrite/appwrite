<?php

namespace Tests\Query\Tokenizer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Tokenizer\PostgreSQL;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

class PostgreSQLTest extends TestCase
{
    private PostgreSQL $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new PostgreSQL();
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

    public function testJsonbContainsOperator(): void
    {
        $tokens = $this->meaningful("WHERE tags @> '[\"php\"]'");

        $operators = array_values(array_filter(
            $tokens,
            fn (Token $t) => $t->type === TokenType::Operator
        ));

        $this->assertCount(1, $operators);
        $this->assertSame('@>', $operators[0]->value);
    }

    public function testJsonbContainedByOperator(): void
    {
        $tokens = $this->meaningful("WHERE '[\"php\"]' <@ tags");

        $operators = array_values(array_filter(
            $tokens,
            fn (Token $t) => $t->type === TokenType::Operator
        ));

        $this->assertCount(1, $operators);
        $this->assertSame('<@', $operators[0]->value);
    }

    public function testVectorOperators(): void
    {
        $tokens1 = $this->meaningful('embedding <=> query_vec');
        $ops1 = array_values(array_filter(
            $tokens1,
            fn (Token $t) => $t->type === TokenType::Operator
        ));
        $this->assertCount(1, $ops1);
        $this->assertSame('<=>', $ops1[0]->value);

        $tokens2 = $this->meaningful('embedding <-> query_vec');
        $ops2 = array_values(array_filter(
            $tokens2,
            fn (Token $t) => $t->type === TokenType::Operator
        ));
        $this->assertCount(1, $ops2);
        $this->assertSame('<->', $ops2[0]->value);

        $tokens3 = $this->meaningful('embedding <#> query_vec');
        $ops3 = array_values(array_filter(
            $tokens3,
            fn (Token $t) => $t->type === TokenType::Operator
        ));
        $this->assertCount(1, $ops3);
        $this->assertSame('<#>', $ops3[0]->value);
    }

    public function testDoubleQuoteNotString(): void
    {
        $tokens = $this->meaningful('"col"');

        $this->assertSame(TokenType::QuotedIdentifier, $tokens[0]->type);
        $this->assertSame('"col"', $tokens[0]->value);
        $this->assertNotSame(TokenType::String, $tokens[0]->type);
    }
}
