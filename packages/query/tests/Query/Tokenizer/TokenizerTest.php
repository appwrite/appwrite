<?php

namespace Tests\Query\Tokenizer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

class TokenizerTest extends TestCase
{
    protected Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new Tokenizer();
    }

    /**
     * Helper: tokenize and filter to meaningful tokens.
     *
     * @return Token[]
     */
    private function meaningful(string $sql): array
    {
        return Tokenizer::filter($this->tokenizer->tokenize($sql));
    }

    /**
     * Helper: extract token types from an array of tokens.
     *
     * @param Token[] $tokens
     * @return TokenType[]
     */
    private function types(array $tokens): array
    {
        return array_map(fn (Token $t) => $t->type, $tokens);
    }

    /**
     * Helper: extract token values from an array of tokens.
     *
     * @param Token[] $tokens
     * @return string[]
     */
    private function values(array $tokens): array
    {
        return array_map(fn (Token $t) => $t->value, $tokens);
    }

    public function testSelectStar(): void
    {
        $tokens = $this->meaningful('SELECT * FROM users');

        $this->assertSame(
            [TokenType::Keyword, TokenType::Star, TokenType::Keyword, TokenType::Identifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame(
            ['SELECT', '*', 'FROM', 'users', ''],
            $this->values($tokens)
        );
    }

    public function testSelectColumns(): void
    {
        $tokens = $this->meaningful('SELECT name, email FROM users');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Comma,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame(
            ['SELECT', 'name', ',', 'email', 'FROM', 'users', ''],
            $this->values($tokens)
        );
    }

    public function testWhereClause(): void
    {
        $tokens = $this->meaningful('SELECT * FROM users WHERE age > 18');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Star, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier,
                TokenType::Operator, TokenType::Integer, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame(
            ['SELECT', '*', 'FROM', 'users', 'WHERE', 'age', '>', '18', ''],
            $this->values($tokens)
        );
    }

    public function testStringLiteral(): void
    {
        $tokens = $this->meaningful("SELECT * FROM users WHERE name = 'Alice'");

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Star, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier,
                TokenType::Operator, TokenType::String, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame("'Alice'", $tokens[7]->value);
    }

    public function testStringLiteralWithEscapedQuote(): void
    {
        $tokens = $this->meaningful("WHERE name = 'O''Brien'");

        $stringToken = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::String) {
                $stringToken = $t;
                break;
            }
        }

        $this->assertNotNull($stringToken);
        $this->assertSame("'O''Brien'", $stringToken->value);
    }

    public function testNumericLiterals(): void
    {
        $tokens = $this->meaningful('WHERE id = 42 AND score = 3.14');

        $types = $this->types($tokens);
        $values = $this->values($tokens);

        $this->assertSame(TokenType::Integer, $types[3]);
        $this->assertSame('42', $values[3]);

        $this->assertSame(TokenType::Float, $types[7]);
        $this->assertSame('3.14', $values[7]);
    }

    public function testOperators(): void
    {
        $tokens = $this->meaningful('a = b != c <> d < e > f <= g >= h');

        $operators = array_values(array_filter(
            $tokens,
            fn (Token $t) => $t->type === TokenType::Operator
        ));

        $this->assertSame(
            ['=', '!=', '<>', '<', '>', '<=', '>='],
            array_map(fn (Token $t) => $t->value, $operators)
        );
    }

    public function testLogicalOperators(): void
    {
        $tokens = $this->meaningful('WHERE a AND b OR NOT c');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Keyword,
                TokenType::Identifier, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('AND', $tokens[2]->value);
        $this->assertSame('OR', $tokens[4]->value);
        $this->assertSame('NOT', $tokens[5]->value);
    }

    public function testInExpression(): void
    {
        $tokens = $this->meaningful("WHERE status IN ('active', 'pending')");

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::LeftParen, TokenType::String, TokenType::Comma,
                TokenType::String, TokenType::RightParen, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('IN', $tokens[2]->value);
    }

    public function testBetweenExpression(): void
    {
        $tokens = $this->meaningful('WHERE age BETWEEN 18 AND 65');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::Integer, TokenType::Keyword, TokenType::Integer, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('BETWEEN', $tokens[2]->value);
    }

    public function testLikeExpression(): void
    {
        $tokens = $this->meaningful("WHERE name LIKE 'A%'");

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::String, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('LIKE', $tokens[2]->value);
    }

    public function testIsNull(): void
    {
        $tokens = $this->meaningful('WHERE deleted_at IS NULL');
        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::Null, TokenType::Eof,
            ],
            $this->types($tokens)
        );

        $tokens2 = $this->meaningful('WHERE deleted_at IS NOT NULL');
        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::Keyword, TokenType::Null, TokenType::Eof,
            ],
            $this->types($tokens2)
        );
    }

    public function testJoin(): void
    {
        $tokens = $this->meaningful('SELECT * FROM users JOIN orders ON users.id = orders.user_id');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Star, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier,
                TokenType::Keyword, TokenType::Identifier, TokenType::Dot,
                TokenType::Identifier, TokenType::Operator, TokenType::Identifier,
                TokenType::Dot, TokenType::Identifier, TokenType::Eof,
            ],
            $this->types($tokens)
        );
    }

    public function testMultipleJoins(): void
    {
        $tokens = $this->meaningful('SELECT * FROM a LEFT JOIN b ON a.id = b.a_id RIGHT JOIN c ON a.id = c.a_id');

        $keywords = array_values(array_filter(
            $tokens,
            fn (Token $t) => $t->type === TokenType::Keyword
        ));
        $kwValues = array_map(fn (Token $t) => $t->value, $keywords);

        $this->assertContains('LEFT', $kwValues);
        $this->assertContains('RIGHT', $kwValues);
        $this->assertContains('JOIN', $kwValues);
        $this->assertContains('ON', $kwValues);
    }

    public function testOrderBy(): void
    {
        $tokens = $this->meaningful('ORDER BY name ASC, age DESC');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Keyword, TokenType::Identifier,
                TokenType::Keyword, TokenType::Comma, TokenType::Identifier,
                TokenType::Keyword, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('ASC', $tokens[3]->value);
        $this->assertSame('DESC', $tokens[6]->value);
    }

    public function testGroupByHaving(): void
    {
        $tokens = $this->meaningful('GROUP BY status HAVING COUNT(*) > 5');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Keyword, TokenType::Identifier,
                TokenType::Keyword, TokenType::Identifier, TokenType::LeftParen,
                TokenType::Star, TokenType::RightParen, TokenType::Operator,
                TokenType::Integer, TokenType::Eof,
            ],
            $this->types($tokens)
        );
    }

    public function testLimitOffset(): void
    {
        $tokens = $this->meaningful('LIMIT 10 OFFSET 20');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Integer, TokenType::Keyword,
                TokenType::Integer, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('10', $tokens[1]->value);
        $this->assertSame('20', $tokens[3]->value);
    }

    public function testFunctionCall(): void
    {
        $tokens = $this->meaningful('COUNT(*), SUM(amount), UPPER(name)');

        // Aggregate function names are identifiers, not keywords
        $this->assertSame(TokenType::Identifier, $tokens[0]->type);
        $this->assertSame(TokenType::LeftParen, $tokens[1]->type);
        $this->assertSame(TokenType::Star, $tokens[2]->type);
        $this->assertSame(TokenType::RightParen, $tokens[3]->type);
    }

    public function testNestedFunctionCall(): void
    {
        $tokens = $this->meaningful("COALESCE(UPPER(name), 'unknown')");

        $this->assertSame(TokenType::Identifier, $tokens[0]->type);
        $this->assertSame('COALESCE', $tokens[0]->value);
        $this->assertSame(TokenType::LeftParen, $tokens[1]->type);
        $this->assertSame(TokenType::Identifier, $tokens[2]->type);
        $this->assertSame('UPPER', $tokens[2]->value);
        $this->assertSame(TokenType::LeftParen, $tokens[3]->type);
        $this->assertSame(TokenType::Identifier, $tokens[4]->type);
        $this->assertSame('name', $tokens[4]->value);
        $this->assertSame(TokenType::RightParen, $tokens[5]->type);
        $this->assertSame(TokenType::Comma, $tokens[6]->type);
        $this->assertSame(TokenType::String, $tokens[7]->type);
        $this->assertSame(TokenType::RightParen, $tokens[8]->type);
    }

    public function testPlaceholders(): void
    {
        $tokens = $this->meaningful('WHERE id = ? AND name = :name AND seq = $1');

        $placeholder = null;
        $named = null;
        $numbered = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::Placeholder) {
                $placeholder = $t;
            }
            if ($t->type === TokenType::NamedPlaceholder) {
                $named = $t;
            }
            if ($t->type === TokenType::NumberedPlaceholder) {
                $numbered = $t;
            }
        }

        $this->assertNotNull($placeholder);
        $this->assertSame('?', $placeholder->value);
        $this->assertNotNull($named);
        $this->assertSame(':name', $named->value);
        $this->assertNotNull($numbered);
        $this->assertSame('$1', $numbered->value);
    }

    public function testQuotedIdentifiers(): void
    {
        $tokens = $this->meaningful('SELECT `user name` FROM `my table`');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::QuotedIdentifier, TokenType::Keyword,
                TokenType::QuotedIdentifier, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('`user name`', $tokens[1]->value);
        $this->assertSame('`my table`', $tokens[3]->value);
    }

    public function testLineComment(): void
    {
        $all = $this->tokenizer->tokenize("SELECT * -- this is a comment\nFROM users");

        $comments = array_values(array_filter(
            $all,
            fn (Token $t) => $t->type === TokenType::LineComment
        ));

        $this->assertCount(1, $comments);
        $this->assertSame('-- this is a comment', $comments[0]->value);

        $filtered = Tokenizer::filter($all);
        $types = $this->types($filtered);
        $this->assertNotContains(TokenType::LineComment, $types);
    }

    public function testBlockComment(): void
    {
        $all = $this->tokenizer->tokenize('SELECT /* columns */ * FROM users');

        $comments = array_values(array_filter(
            $all,
            fn (Token $t) => $t->type === TokenType::BlockComment
        ));

        $this->assertCount(1, $comments);
        $this->assertSame('/* columns */', $comments[0]->value);

        $filtered = Tokenizer::filter($all);
        $types = $this->types($filtered);
        $this->assertNotContains(TokenType::BlockComment, $types);
    }

    public function testFilterRemovesWhitespaceAndComments(): void
    {
        $all = $this->tokenizer->tokenize("SELECT  *  -- comment\n FROM  users");

        $hasWhitespace = false;
        $hasComment = false;
        foreach ($all as $t) {
            if ($t->type === TokenType::Whitespace) {
                $hasWhitespace = true;
            }
            if ($t->type === TokenType::LineComment) {
                $hasComment = true;
            }
        }
        $this->assertTrue($hasWhitespace);
        $this->assertTrue($hasComment);

        $filtered = Tokenizer::filter($all);
        foreach ($filtered as $t) {
            $this->assertNotSame(TokenType::Whitespace, $t->type);
            $this->assertNotSame(TokenType::LineComment, $t->type);
            $this->assertNotSame(TokenType::BlockComment, $t->type);
        }
    }

    public function testCaseExpression(): void
    {
        $tokens = $this->meaningful("CASE WHEN x > 0 THEN 'pos' ELSE 'neg' END");

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Keyword, TokenType::Identifier,
                TokenType::Operator, TokenType::Integer, TokenType::Keyword,
                TokenType::String, TokenType::Keyword, TokenType::String,
                TokenType::Keyword, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('CASE', $tokens[0]->value);
        $this->assertSame('WHEN', $tokens[1]->value);
        $this->assertSame('THEN', $tokens[5]->value);
        $this->assertSame('ELSE', $tokens[7]->value);
        $this->assertSame('END', $tokens[9]->value);
    }

    public function testSubquery(): void
    {
        $tokens = $this->meaningful('WHERE id IN (SELECT user_id FROM orders)');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::LeftParen, TokenType::Keyword, TokenType::Identifier,
                TokenType::Keyword, TokenType::Identifier, TokenType::RightParen,
                TokenType::Eof,
            ],
            $this->types($tokens)
        );
    }

    public function testAliases(): void
    {
        $tokens = $this->meaningful('SELECT name AS n FROM users u');

        $this->assertSame(
            [
                TokenType::Keyword, TokenType::Identifier, TokenType::Keyword,
                TokenType::Identifier, TokenType::Keyword, TokenType::Identifier,
                TokenType::Identifier, TokenType::Eof,
            ],
            $this->types($tokens)
        );
        $this->assertSame('AS', $tokens[2]->value);
        $this->assertSame('n', $tokens[3]->value);
        $this->assertSame('u', $tokens[6]->value);
    }

    public function testComplexQuery(): void
    {
        $sql = "SELECT u.name, COUNT(*) AS total FROM users u "
             . "LEFT JOIN orders o ON u.id = o.user_id "
             . "WHERE u.status = 'active' AND o.amount > 100 "
             . "GROUP BY u.name HAVING COUNT(*) > 5 "
             . "ORDER BY total DESC LIMIT 10 OFFSET 0";

        $tokens = $this->meaningful($sql);

        // Just verify it tokenizes without error and has expected start/end
        $this->assertSame(TokenType::Keyword, $tokens[0]->type);
        $this->assertSame('SELECT', $tokens[0]->value);
        $this->assertSame(TokenType::Eof, $tokens[count($tokens) - 1]->type);

        // Verify some key tokens exist
        $values = $this->values($tokens);
        $this->assertContains('LEFT', $values);
        $this->assertContains('JOIN', $values);
        $this->assertContains('GROUP', $values);
        $this->assertContains('HAVING', $values);
        $this->assertContains('ORDER', $values);
        $this->assertContains('LIMIT', $values);
        $this->assertContains('OFFSET', $values);
        $this->assertContains('DESC', $values);
    }

    public function testEmptyInput(): void
    {
        $tokens = $this->meaningful('');

        $this->assertCount(1, $tokens);
        $this->assertSame(TokenType::Eof, $tokens[0]->type);
    }

    public function testStarToken(): void
    {
        $tokens = $this->meaningful('SELECT *');

        $star = null;
        foreach ($tokens as $t) {
            if ($t->value === '*') {
                $star = $t;
                break;
            }
        }

        $this->assertNotNull($star);
        $this->assertSame(TokenType::Star, $star->type);
    }

    public function testDotNotation(): void
    {
        $tokens = $this->meaningful('users.id');

        $this->assertSame(
            [TokenType::Identifier, TokenType::Dot, TokenType::Identifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('users', $tokens[0]->value);
        $this->assertSame('.', $tokens[1]->value);
        $this->assertSame('id', $tokens[2]->value);
    }

    public function testCastOperator(): void
    {
        $tokens = $this->meaningful('value::integer');

        $this->assertSame(
            [TokenType::Identifier, TokenType::Operator, TokenType::Identifier, TokenType::Eof],
            $this->types($tokens)
        );
        $this->assertSame('::', $tokens[1]->value);
    }

    public function testConcatOperator(): void
    {
        $tokens = $this->meaningful("first_name || ' ' || last_name");

        $pipes = array_values(array_filter(
            $tokens,
            fn (Token $t) => $t->type === TokenType::Operator && $t->value === '||'
        ));

        $this->assertCount(2, $pipes);
    }

    public function testKeywordsCaseInsensitive(): void
    {
        $tokens1 = $this->meaningful('select * from users');
        $tokens2 = $this->meaningful('SELECT * FROM users');
        $tokens3 = $this->meaningful('Select * From Users');

        // All should produce keyword tokens with uppercase values
        $this->assertSame(TokenType::Keyword, $tokens1[0]->type);
        $this->assertSame('SELECT', $tokens1[0]->value);

        $this->assertSame(TokenType::Keyword, $tokens2[0]->type);
        $this->assertSame('SELECT', $tokens2[0]->value);

        $this->assertSame(TokenType::Keyword, $tokens3[0]->type);
        $this->assertSame('SELECT', $tokens3[0]->value);

        // "Users" is not a keyword (it's an identifier), but "From" is
        $this->assertSame(TokenType::Keyword, $tokens3[2]->type);
        $this->assertSame('FROM', $tokens3[2]->value);
    }

    public function testBackslashEscapeInString(): void
    {
        $tokens = $this->meaningful("WHERE name = 'hello\\'world'");

        $stringToken = null;
        foreach ($tokens as $t) {
            if ($t->type === TokenType::String) {
                $stringToken = $t;
                break;
            }
        }

        $this->assertNotNull($stringToken);
        $this->assertSame("'hello\\'world'", $stringToken->value);
    }

    public function testScientificNotation(): void
    {
        $tokens = $this->meaningful('1.5e10');
        $this->assertSame(TokenType::Float, $tokens[0]->type);
        $this->assertSame('1.5e10', $tokens[0]->value);

        $tokens = $this->meaningful('1e-3');
        $this->assertSame(TokenType::Float, $tokens[0]->type);
        $this->assertSame('1e-3', $tokens[0]->value);

        $tokens = $this->meaningful('2.5E+8');
        $this->assertSame(TokenType::Float, $tokens[0]->type);
        $this->assertSame('2.5E+8', $tokens[0]->value);
    }

    public function testEscapedQuotedIdentifiers(): void
    {
        $tokens = $this->meaningful('SELECT `col``name` FROM t');
        $this->assertSame(TokenType::QuotedIdentifier, $tokens[1]->type);
        $this->assertSame('`col``name`', $tokens[1]->value);
    }

    public function testUnterminatedBlockComment(): void
    {
        $tokens = $this->tokenizer->tokenize('/*/b');
        // Unterminated block comment should consume all remaining input
        // Only BlockComment + Eof tokens (no leaked 'b' identifier)
        $this->assertCount(2, $tokens);
        $this->assertSame(TokenType::BlockComment, $tokens[0]->type);
        $this->assertSame('/*/b', $tokens[0]->value);
        $this->assertSame(TokenType::Eof, $tokens[1]->type);
    }

    public function testUnknownCharactersEmittedAsOperators(): void
    {
        $tokens = $this->meaningful('a @ b');
        $this->assertSame(TokenType::Operator, $tokens[1]->type);
        $this->assertSame('@', $tokens[1]->value);
    }

    public function testDotPrefixedFloat(): void
    {
        $tokens = $this->meaningful('.5');
        $this->assertSame(TokenType::Float, $tokens[0]->type);
        $this->assertSame('.5', $tokens[0]->value);
    }

    public function testIdentifiersCasePreserved(): void
    {
        $tokens = $this->meaningful('SELECT myColumn FROM MyTable');
        $this->assertSame('myColumn', $tokens[1]->value);
        $this->assertSame('MyTable', $tokens[3]->value);
    }

    public function testEofAlwaysLast(): void
    {
        $inputs = [
            '',
            'SELECT 1',
            "SELECT * FROM users WHERE id = 1",
            "-- just a comment",
        ];

        foreach ($inputs as $sql) {
            $tokens = $this->meaningful($sql);
            $last = $tokens[count($tokens) - 1];
            $this->assertSame(TokenType::Eof, $last->type, "EOF should be last token for: $sql");
        }
    }

    public function testStringWithTrailingBackslashThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unterminated string literal');

        // 'abc\  — opening quote, three chars, backslash, then EOF.
        $this->tokenizer->tokenize("'abc\\");
    }

    public function testUnterminatedStringLiteralThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unterminated string literal');

        $this->tokenizer->tokenize("SELECT 'unclosed");
    }

    public function testUnterminatedQuotedIdentifierThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unterminated quoted identifier');

        $this->tokenizer->tokenize('SELECT `unclosed');
    }
}
