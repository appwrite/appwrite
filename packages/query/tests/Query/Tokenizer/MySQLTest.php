<?php

namespace Tests\Query\Tokenizer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Query\Tokenizer\MySQL;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

class MySQLTest extends TestCase
{
    private MySQL $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new MySQL();
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

    /**
     * @param Token[] $tokens
     * @return string[]
     */
    private function values(array $tokens): array
    {
        return array_map(fn (Token $t) => $t->value, $tokens);
    }

    public function testHashComment(): void
    {
        $all = $this->tokenizer->tokenize("SELECT * # comment\nFROM users");

        $comments = array_values(array_filter(
            $all,
            fn (Token $t) => $t->type === TokenType::LineComment
        ));

        $this->assertCount(1, $comments);
        $this->assertSame('--  comment', $comments[0]->value);
    }

    public function testHashCommentFilteredOut(): void
    {
        $all = $this->tokenizer->tokenize("SELECT * # this is a hash comment\nFROM users");
        $filtered = Tokenizer::filter($all);

        $types = $this->types($filtered);
        $this->assertNotContains(TokenType::LineComment, $types);

        $this->assertSame(
            [TokenType::Keyword, TokenType::Star, TokenType::Keyword, TokenType::Identifier, TokenType::Eof],
            $types
        );
    }

    public function testBacktickQuoting(): void
    {
        $tokens = $this->meaningful('SELECT `name` FROM `users`');

        $this->assertSame(
            [TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('`name`', $tokens[1]->value);
        $this->assertSame('`users`', $tokens[3]->value);
    }

    public function testHashCommentInsideSingleQuotedString(): void
    {
        $tokens = $this->meaningful("SELECT '#not-a-comment' FROM t");

        $values = $this->values($tokens);
        $this->assertContains("'#not-a-comment'", $values);

        $stringToken = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::String) {
                $stringToken = $t;
                break;
            }
        }
        $this->assertNotNull($stringToken);
        $this->assertSame("'#not-a-comment'", $stringToken->value);
    }

    public function testHashCommentInsideBacktickIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT `col#name` FROM t');

        $quotedId = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::QuotedIdentifier) {
                $quotedId = $t;
                break;
            }
        }
        $this->assertNotNull($quotedId);
        $this->assertSame('`col#name`', $quotedId->value);
    }

    public function testHashCommentInsideDoubleQuotedIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT "col#name" FROM t');

        $found = false;
        foreach ($tokens as $t) {
            if ($t->value === '"col#name"') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Double-quoted identifier with # should be preserved');
    }

    public function testHashCommentWithEscapedQuote(): void
    {
        $tokens = $this->meaningful("SELECT 'it''s a #test' FROM t");

        $stringToken = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::String) {
                $stringToken = $t;
                break;
            }
        }
        $this->assertNotNull($stringToken);
        $this->assertSame("'it''s a #test'", $stringToken->value);
    }

    public function testHashCommentWithBackslashEscape(): void
    {
        $tokens = $this->meaningful("SELECT 'it\\'s a #test' FROM t");

        $stringToken = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::String) {
                $stringToken = $t;
                break;
            }
        }
        $this->assertNotNull($stringToken);
        $this->assertStringContainsString('#test', $stringToken->value);
    }

    public function testMultipleHashComments(): void
    {
        $all = $this->tokenizer->tokenize("SELECT 1 #first\nSELECT 2 #second\nSELECT 3");

        $comments = array_values(array_filter(
            $all,
            fn (Token $t) => $t->type === TokenType::LineComment
        ));

        $this->assertCount(2, $comments);
        $this->assertSame('-- first', $comments[0]->value);
        $this->assertSame('-- second', $comments[1]->value);
    }

    public function testHashCommentAtEndOfInput(): void
    {
        $all = $this->tokenizer->tokenize('SELECT 1 #comment');

        $comments = array_values(array_filter(
            $all,
            fn (Token $t) => $t->type === TokenType::LineComment
        ));

        $this->assertCount(1, $comments);
        $this->assertSame('-- comment', $comments[0]->value);

        $filtered = Tokenizer::filter($all);
        $this->assertSame(
            [TokenType::Keyword, TokenType::Integer, TokenType::Eof],
            $this->types($filtered)
        );
    }

    public function testHashCommentInsideEscapedBacktickIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT `col``#name` FROM t');

        $quotedId = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::QuotedIdentifier) {
                $quotedId = $t;
                break;
            }
        }
        $this->assertNotNull($quotedId);
        $this->assertSame('`col``#name`', $quotedId->value);
    }

    public function testHashCommentInsideEscapedDoubleQuotedIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT "col""#name" FROM t');

        $found = false;
        foreach ($tokens as $t) {
            if (str_contains($t->value, '#name')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Escaped double-quoted identifier with # should be preserved');
    }

    public function testHashInsideDoubleQuotedStringWithBackslashEscapeIsNotRewritten(): void
    {
        // MySQL default mode (no ANSI_QUOTES): " opens a string literal and
        // \" is an escaped quote. The # inside must not be rewritten to --.
        $sql = 'SELECT "a\\"# not a comment" FROM t';

        $reflection = new ReflectionClass(MySQL::class);
        $method = $reflection->getMethod('replaceHashComments');
        $rewritten = $method->invoke($this->tokenizer, $sql);

        $this->assertIsString($rewritten);
        $this->assertStringContainsString('# not a comment', $rewritten);
        $this->assertStringNotContainsString('-- not a comment', $rewritten);
        $this->assertSame($sql, $rewritten);
    }

    public function testHashInsidePlainDoubleQuotedIdentifierIsNotRewritten(): void
    {
        // With no backslash-escaped quotes, a plain "col#name" is still a
        // double-quoted identifier under ANSI_QUOTES. The # inside must not
        // be rewritten to a -- comment.
        $sql = 'SELECT "col#name" FROM t';

        $reflection = new ReflectionClass(MySQL::class);
        $method = $reflection->getMethod('replaceHashComments');
        $rewritten = $method->invoke($this->tokenizer, $sql);

        $this->assertIsString($rewritten);
        $this->assertStringContainsString('#name', $rewritten);
        $this->assertStringNotContainsString('--name', $rewritten);
        $this->assertSame($sql, $rewritten);
    }

    public function testHashAfterDoubleQuotedIdentifierIsRewritten(): void
    {
        // A # that appears after a closed double-quoted identifier is a real
        // line comment and must be rewritten to --.
        $sql = "SELECT \"a\" # trailing comment\nFROM t";

        $reflection = new ReflectionClass(MySQL::class);
        $method = $reflection->getMethod('replaceHashComments');
        $rewritten = $method->invoke($this->tokenizer, $sql);

        $this->assertIsString($rewritten);
        $this->assertStringContainsString('--  trailing comment', $rewritten);
        $this->assertStringNotContainsString('# trailing comment', $rewritten);

        // End-to-end: the tokenizer should drop the comment after filtering.
        $filtered = Tokenizer::filter($this->tokenizer->tokenize($sql));
        $types = array_map(fn (Token $t) => $t->type, $filtered);
        $this->assertNotContains(TokenType::LineComment, $types);
    }

    public function testHashReplacementEmitsTrailingSpaceForReTokenization(): void
    {
        // Regression: MySQL's `--` line-comment form requires a whitespace or
        // control character after the two dashes. Replacing `#` with just `--`
        // (no trailing space) emits SQL that is not re-tokenizable as a line
        // comment. The replacement must be `-- ` (with trailing space).
        $sql = 'SELECT 1 #comment';

        $reflection = new ReflectionClass(MySQL::class);
        $method = $reflection->getMethod('replaceHashComments');
        $rewritten = $method->invoke($this->tokenizer, $sql);

        $this->assertIsString($rewritten);
        $this->assertStringContainsString('-- comment', $rewritten);
        $this->assertStringNotContainsString('#comment', $rewritten);
    }

    public function testHashInsideSingleQuotedStringIsNotRewritten(): void
    {
        $sql = "SELECT 'a#b' FROM t";

        $reflection = new ReflectionClass(MySQL::class);
        $method = $reflection->getMethod('replaceHashComments');
        $rewritten = $method->invoke($this->tokenizer, $sql);

        $this->assertIsString($rewritten);
        $this->assertSame($sql, $rewritten);
        $this->assertStringContainsString("'a#b'", $rewritten);
        $this->assertStringNotContainsString("'a--b'", $rewritten);
    }
}
