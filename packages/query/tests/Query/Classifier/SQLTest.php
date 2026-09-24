<?php

namespace Tests\Query\Classifier;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Type;

/**
 * Tests for the shared SQL classification logic in Parser\SQL.
 * Uses PostgreSQL classifier as a concrete implementation since
 * classifySQL and extractKeyword are protocol-agnostic.
 */
class SQLTest extends TestCase
{
    protected PostgreSQL $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PostgreSQL();
    }

    // -- classifySQL Edge Cases --

    public function testClassifyLeadingWhitespace(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL("   \t\n  SELECT * FROM users"));
    }

    public function testClassifyLeadingLineComment(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL("-- this is a comment\nSELECT * FROM users"));
    }

    public function testClassifyLeadingBlockComment(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL("/* block comment */ SELECT * FROM users"));
    }

    public function testClassifyMultipleComments(): void
    {
        $sql = "-- line comment\n/* block comment */\n  -- another line\n  SELECT 1";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyNestedBlockComment(): void
    {
        $sql = "/* outer /* inner */ SELECT 1";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyEmptyQuery(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classifySQL(''));
    }

    public function testClassifyWhitespaceOnly(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classifySQL("   \t\n  "));
    }

    public function testClassifyCommentOnly(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classifySQL('-- just a comment'));
    }

    public function testClassifySelectWithParenthesis(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL('SELECT(1)'));
    }

    public function testClassifySelectWithSemicolon(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL('SELECT;'));
    }

    // -- COPY Direction --

    public function testClassifyCopyTo(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classifySQL('COPY users TO STDOUT'));
    }

    public function testClassifyCopyFrom(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classifySQL("COPY users FROM '/tmp/data.csv'"));
    }

    public function testClassifyCopyAmbiguous(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classifySQL('COPY users'));
    }

    // -- CTE (WITH) --

    public function testClassifyCteWithSelect(): void
    {
        $sql = 'WITH active_users AS (SELECT * FROM users WHERE active = true) SELECT * FROM active_users';
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteWithInsert(): void
    {
        $sql = 'WITH new_data AS (SELECT 1 AS id) INSERT INTO users SELECT * FROM new_data';
        $this->assertSame(Type::Write, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteWithUpdate(): void
    {
        $sql = 'WITH src AS (SELECT id FROM staging) UPDATE users SET active = true FROM src WHERE users.id = src.id';
        $this->assertSame(Type::Write, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteWithDelete(): void
    {
        $sql = 'WITH old AS (SELECT id FROM users WHERE created_at < now()) DELETE FROM users WHERE id IN (SELECT id FROM old)';
        $this->assertSame(Type::Write, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteRecursiveSelect(): void
    {
        $sql = 'WITH RECURSIVE tree AS (SELECT id, parent_id FROM categories WHERE parent_id IS NULL UNION ALL SELECT c.id, c.parent_id FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT * FROM tree';
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteNoFinalKeyword(): void
    {
        $sql = 'WITH x AS (SELECT 1)';
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteInsertKeywordInsideStringLiteralTreatedAsRead(): void
    {
        // The inner INSERT is inside a string literal and must not influence classification.
        $sql = "WITH foo AS (SELECT 'INSERT INTO dangerous VALUES (1)' AS payload FROM t) SELECT * FROM foo";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteCloseParenInsideStringLiteralDoesNotBreakDepth(): void
    {
        // The ')' inside the string literal must not drop the depth counter.
        // If literals were ignored, the parser would see depth go to 0 early and
        // mis-classify on the trailing 'DELETE' token inside the literal.
        $sql = "WITH foo AS (SELECT ') DELETE FROM users' AS payload FROM t) SELECT * FROM foo";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteDeleteKeywordInsideBlockCommentIsIgnored(): void
    {
        $sql = "WITH foo AS (SELECT 1 FROM t) /* DELETE FROM users */ SELECT * FROM foo";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteInsertKeywordInsideLineCommentIsIgnored(): void
    {
        $sql = "WITH foo AS (SELECT 1 FROM t)\n-- INSERT INTO dangerous\nSELECT * FROM foo";
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteKeywordInsideDoubleQuotedIdentifierIsIgnored(): void
    {
        // A quoted identifier literally named "DELETE FROM x" is a valid identifier.
        $sql = 'WITH foo AS (SELECT 1 FROM "DELETE FROM x") SELECT * FROM foo';
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    public function testClassifyCteKeywordInsideDollarQuotedStringIsIgnored(): void
    {
        // Dollar-quoted strings must be skipped end-to-end.
        $sql = 'WITH foo AS (SELECT $body$INSERT INTO dangerous$body$ FROM t) SELECT * FROM foo';
        $this->assertSame(Type::Read, $this->classifier->classifySQL($sql));
    }

    // -- extractKeyword --

    public function testExtractKeywordSimple(): void
    {
        $this->assertSame('SELECT', $this->classifier->extractKeyword('SELECT * FROM users'));
    }

    public function testExtractKeywordLowercase(): void
    {
        $this->assertSame('INSERT', $this->classifier->extractKeyword('insert into users'));
    }

    public function testExtractKeywordWithWhitespace(): void
    {
        $this->assertSame('DELETE', $this->classifier->extractKeyword("  \t\n  DELETE FROM users"));
    }

    public function testExtractKeywordWithComments(): void
    {
        $this->assertSame('UPDATE', $this->classifier->extractKeyword("-- comment\nUPDATE users SET x = 1"));
    }

    public function testExtractKeywordEmpty(): void
    {
        $this->assertSame('', $this->classifier->extractKeyword(''));
    }

    public function testExtractKeywordParenthesized(): void
    {
        $this->assertSame('SELECT', $this->classifier->extractKeyword('SELECT(1)'));
    }

    // -- Performance --

    #[Group('performance')]
    public function testClassifySqlPerformance(): void
    {
        if (\getenv('CI') !== false) {
            $this->markTestSkipped('Performance targets assume dedicated hardware; CI runners are too variable.');
        }

        $queries = [
            'SELECT * FROM users WHERE id = 1',
            "INSERT INTO logs (msg) VALUES ('test')",
            'BEGIN',
            '   /* comment */ SELECT 1',
            'WITH cte AS (SELECT 1) SELECT * FROM cte',
        ];

        $iterations = 100_000;

        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->classifier->classifySQL($queries[$i % \count($queries)]);
        }
        $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $perQuery = ($elapsed / $iterations) * 1_000_000;

        $this->assertLessThan(
            2.0,
            $perQuery,
            \sprintf('classifySQL took %.3f us/query (target: < 2.0 us)', $perQuery)
        );
    }
}
