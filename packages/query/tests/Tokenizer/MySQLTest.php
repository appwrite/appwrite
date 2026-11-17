<?php

namespace Utopia\Query\Tests\Tokenizer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception\ValidationException;
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
        return array_map(fn (Token $t): TokenType => $t->type, $tokens);
    }

    /**
     * @param Token[] $tokens
     * @return string[]
     */
    private function values(array $tokens): array
    {
        return array_map(fn (Token $t): string => $t->value, $tokens);
    }

    public function testHashComment(): void
    {
        $all = $this->tokenizer->tokenize("SELECT * # comment\nFROM users");

        $comments = array_values(array_filter(
            $all,
            fn (Token $t): bool => $t->type === TokenType::LineComment
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
        $stringToken = array_find($tokens, fn ($t): bool => $t->type === TokenType::String);
        $this->assertNotNull($stringToken);
        $this->assertSame("'#not-a-comment'", $stringToken->value);
    }

    public function testHashCommentInsideBacktickIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT `col#name` FROM t');
        $quotedId = array_find($tokens, fn ($t): bool => $t->type === TokenType::QuotedIdentifier);
        $this->assertNotNull($quotedId);
        $this->assertSame('`col#name`', $quotedId->value);
    }

    public function testHashCommentInsideDoubleQuotedIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT "col#name" FROM t');
        $found = array_any($tokens, fn (Token $t): bool => $t->value === '"col#name"');
        $this->assertTrue($found, 'Double-quoted identifier with # should be preserved');
    }

    public function testHashCommentWithEscapedQuote(): void
    {
        $tokens = $this->meaningful("SELECT 'it''s a #test' FROM t");
        $stringToken = array_find($tokens, fn ($t): bool => $t->type === TokenType::String);
        $this->assertNotNull($stringToken);
        $this->assertSame("'it''s a #test'", $stringToken->value);
    }

    public function testHashCommentWithBackslashEscape(): void
    {
        $tokens = $this->meaningful("SELECT 'it\\'s a #test' FROM t");
        $stringToken = array_find($tokens, fn ($t): bool => $t->type === TokenType::String);
        $this->assertNotNull($stringToken);
        $this->assertStringContainsString('#test', $stringToken->value);
    }

    public function testMultipleHashComments(): void
    {
        $all = $this->tokenizer->tokenize("SELECT 1 #first\nSELECT 2 #second\nSELECT 3");

        $comments = array_values(array_filter(
            $all,
            fn (Token $t): bool => $t->type === TokenType::LineComment
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
            fn (Token $t): bool => $t->type === TokenType::LineComment
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
        $quotedId = array_find($tokens, fn ($t): bool => $t->type === TokenType::QuotedIdentifier);
        $this->assertNotNull($quotedId);
        $this->assertSame('`col``#name`', $quotedId->value);
    }

    public function testHashCommentInsideEscapedDoubleQuotedIdentifier(): void
    {
        $tokens = $this->meaningful('SELECT "col""#name" FROM t');
        $found = array_any($tokens, fn (Token $t): bool => str_contains($t->value, '#name'));
        $this->assertTrue($found, 'Escaped double-quoted identifier with # should be preserved');
    }

    /**
     * The SQL the tokenizer read, with `#` comments rewritten: its tokens cover every byte, so
     * their values joined are that text.
     */
    private function rewritten(string $sql): string
    {
        return \implode('', \array_map(fn (Token $token): string => $token->value, $this->tokenizer->tokenize($sql)));
    }

    public function testHashInsideDoubleQuotedStringWithBackslashEscapeIsNotRewritten(): void
    {
        // MySQL default mode (no ANSI_QUOTES): " opens a string literal and
        // \" is an escaped quote. The # inside must not be rewritten to --.
        $sql = 'SELECT "a\\"# not a comment" FROM t';

        // The scanner itself does not read \" as an escape, so it closes the quote there and
        // then meets the last " with nothing to close it. Had the # been rewritten to a
        // comment, that " would be inside the comment and tokenizing would succeed.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unterminated quoted identifier');

        $this->tokenizer->tokenize($sql);
    }

    public function testHashInsidePlainDoubleQuotedIdentifierIsNotRewritten(): void
    {
        // With no backslash-escaped quotes, a plain "col#name" is still a
        // double-quoted identifier under ANSI_QUOTES. The # inside must not
        // be rewritten to a -- comment.
        $sql = 'SELECT "col#name" FROM t';

        $rewritten = $this->rewritten($sql);

        $this->assertStringContainsString('#name', $rewritten);
        $this->assertStringNotContainsString('--name', $rewritten);
        $this->assertSame($sql, $rewritten);
    }

    public function testHashAfterDoubleQuotedIdentifierIsRewritten(): void
    {
        // A # that appears after a closed double-quoted identifier is a real
        // line comment and must be rewritten to --.
        $sql = "SELECT \"a\" # trailing comment\nFROM t";

        $rewritten = $this->rewritten($sql);

        $this->assertStringContainsString('--  trailing comment', $rewritten);
        $this->assertStringNotContainsString('# trailing comment', $rewritten);

        // End-to-end: the tokenizer should drop the comment after filtering.
        $filtered = Tokenizer::filter($this->tokenizer->tokenize($sql));
        $types = array_map(fn (Token $t): TokenType => $t->type, $filtered);
        $this->assertNotContains(TokenType::LineComment, $types);
    }

    public function testHashReplacementEmitsTrailingSpaceForReTokenization(): void
    {
        // Regression: MySQL's `--` line-comment form requires a whitespace or
        // control character after the two dashes. Replacing `#` with just `--`
        // (no trailing space) emits SQL that is not re-tokenizable as a line
        // comment. The replacement must be `-- ` (with trailing space).
        $sql = 'SELECT 1 #comment';

        $rewritten = $this->rewritten($sql);

        $this->assertStringContainsString('-- comment', $rewritten);
        $this->assertStringNotContainsString('#comment', $rewritten);
    }

    public function testHashInsideSingleQuotedStringIsNotRewritten(): void
    {
        $sql = "SELECT 'a#b' FROM t";

        $rewritten = $this->rewritten($sql);

        $this->assertSame($sql, $rewritten);
        $this->assertStringContainsString("'a#b'", $rewritten);
        $this->assertStringNotContainsString("'a--b'", $rewritten);
    }
}
