<?php

namespace Utopia\Query\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Serializer\ClickHouse as ClickHouseSerializer;
use Utopia\Query\AST\Serializer\MariaDB as MariaDBSerializer;
use Utopia\Query\AST\Serializer\MySQL as MySQLSerializer;
use Utopia\Query\AST\Serializer\PostgreSQL as PostgreSQLSerializer;
use Utopia\Query\AST\Serializer\SQLite as SQLiteSerializer;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\Feature\RawSql;
use Utopia\Query\Builder\MariaDB as MariaDBBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Builder\SQLite as SQLiteBuilder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\StringLiteral;
use Utopia\Query\Tokenizer\ClickHouse as ClickHouseTokenizer;
use Utopia\Query\Tokenizer\MariaDB as MariaDBTokenizer;
use Utopia\Query\Tokenizer\MySQL as MySQLTokenizer;
use Utopia\Query\Tokenizer\PostgreSQL as PostgreSQLTokenizer;
use Utopia\Query\Tokenizer\SQLite as SQLiteTokenizer;
use Utopia\Query\Tokenizer\Tokenizer;
use Utopia\Query\Tokenizer\TokenType;

final class DialectStringLiteralParseTest extends TestCase
{
    /**
     * @return array<string, array{Tokenizer, Serializer, StringLiteral}>
     */
    private static function dialects(): array
    {
        return [
            'mysql' => [new MySQLTokenizer(), new MySQLSerializer(), StringLiteral::Backslash],
            'mariadb' => [new MariaDBTokenizer(), new MariaDBSerializer(), StringLiteral::Backslash],
            'postgresql' => [new PostgreSQLTokenizer(), new PostgreSQLSerializer(), StringLiteral::Extended],
            'sqlite' => [new SQLiteTokenizer(), new SQLiteSerializer(), StringLiteral::Standard],
            'clickhouse' => [new ClickHouseTokenizer(), new ClickHouseSerializer(), StringLiteral::BackslashUtf8],
        ];
    }

    /**
     * @return array<string, array{Tokenizer, Serializer, StringLiteral, string}>
     */
    public static function roundTripProvider(): array
    {
        $values = [
            'backslash' => 'C:\\dir\\file',
            'trailing backslash' => 'C:\\',
            'quote' => "it's",
            'backslash before quote' => "\\' OR 1=1 -- ",
            'quote before backslash' => "'\\",
            'doubled backslash' => 'a\\\\b',
            'escape lookalike' => 'a\\nb\\x41\\101',
        ];

        $cases = [];
        foreach (self::dialects() as $dialect => [$tokenizer, $serializer, $literal]) {
            foreach ($values as $name => $value) {
                $cases[$dialect . ' ' . $name] = [$tokenizer, $serializer, $literal, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('roundTripProvider')]
    public function testParseOfSerializedLiteralRoundTrips(Tokenizer $tokenizer, Serializer $serializer, StringLiteral $literal, string $value): void
    {
        $sql = $serializer->serialize(new Select(columns: [new Literal($value)]));

        $parsed = $this->parse($tokenizer, $literal, $sql);

        $this->assertSame($sql, $serializer->serialize($parsed));
        $this->assertSame($value, $this->literalValue($parsed));
    }

    /**
     * @return array<string, array{Tokenizer, StringLiteral, string, string}>
     */
    public static function parsedValueProvider(): array
    {
        return [
            'sqlite trailing backslash' => [new SQLiteTokenizer(), StringLiteral::Standard, "SELECT 'C:\\'", 'C:\\'],
            'sqlite backslash before quote' => [new SQLiteTokenizer(), StringLiteral::Standard, "SELECT 'a\\''b'", "a\\'b"],
            'postgresql extended backslash' => [new PostgreSQLTokenizer(), StringLiteral::Extended, "SELECT E'a\\\\b'", 'a\\b'],
            'postgresql lowercase prefix' => [new PostgreSQLTokenizer(), StringLiteral::Extended, "SELECT e'it\\'s'", "it's"],
            'postgresql plain literal keeps backslash' => [new PostgreSQLTokenizer(), StringLiteral::Extended, "SELECT 'C:\\'", 'C:\\'],
            'postgresql numeric escapes' => [new PostgreSQLTokenizer(), StringLiteral::Extended, "SELECT E'\\x41\\101\\u00e9\\n'", "AA\u{E9}\n"],
            'mysql doubled backslash' => [new MySQLTokenizer(), StringLiteral::Backslash, "SELECT 'a\\\\b'", 'a\\b'],
            'mysql control escapes' => [new MySQLTokenizer(), StringLiteral::Backslash, "SELECT 'a\\nb\\tc\\0'", "a\nb\tc\0"],
            'mysql like wildcard escapes keep backslash' => [new MySQLTokenizer(), StringLiteral::Backslash, "SELECT '\\%\\_'", '\\%\\_'],
            'clickhouse hex escape' => [new ClickHouseTokenizer(), StringLiteral::BackslashUtf8, "SELECT 'a\\x41\\\\'", 'aA\\'],
        ];
    }

    #[DataProvider('parsedValueProvider')]
    public function testParsesLiteralPerDialect(Tokenizer $tokenizer, StringLiteral $literal, string $sql, string $expected): void
    {
        $this->assertSame($expected, $this->literalValue($this->parse($tokenizer, $literal, $sql)));
    }

    public function testSqliteTokenizesBackslashBeforeClosingQuoteAsOneString(): void
    {
        $tokens = Tokenizer::filter(new SQLiteTokenizer()->tokenize("SELECT 'C:\\', 1"));

        $this->assertSame(TokenType::String, $tokens[1]->type);
        $this->assertSame("'C:\\'", $tokens[1]->value);
        $this->assertSame(TokenType::Comma, $tokens[2]->type);
    }

    public function testPostgresqlTokenizesExtendedLiteralAsOneString(): void
    {
        $tokens = Tokenizer::filter(new PostgreSQLTokenizer()->tokenize("SELECT E'a\\'b' AS x"));

        $this->assertSame(TokenType::String, $tokens[1]->type);
        $this->assertSame("E'a\\'b'", $tokens[1]->value);
        $this->assertSame(TokenType::Keyword, $tokens[2]->type);
    }

    public function testPostgresqlPlainLiteralEndsAtFirstUndoubledQuote(): void
    {
        $tokens = Tokenizer::filter(new PostgreSQLTokenizer()->tokenize("SELECT 'a\\', 'b'"));

        $this->assertSame("'a\\'", $tokens[1]->value);
        $this->assertSame(TokenType::Comma, $tokens[2]->type);
        $this->assertSame("'b'", $tokens[3]->value);
    }

    public function testPostgresqlRefusesNulEscape(): void
    {
        $this->expectException(ValidationException::class);

        $this->parse(new PostgreSQLTokenizer(), StringLiteral::Extended, "SELECT E'a\\0b'");
    }

    /**
     * @return array<string, array{Builder, Builder&RawSql, string, string}>
     */
    public static function cteProvider(): array
    {
        return [
            'mysql' => [new MySQLBuilder(), new MySQLBuilder(), "'C:\\\\'", "WITH `c` AS (SELECT 'C:\\\\' FROM `t`) SELECT * FROM `c`"],
            'mariadb' => [new MariaDBBuilder(), new MariaDBBuilder(), "'C:\\\\'", "WITH `c` AS (SELECT 'C:\\\\' FROM `t`) SELECT * FROM `c`"],
            'postgresql' => [new PostgreSQLBuilder(), new PostgreSQLBuilder(), "E'C:\\\\'", "WITH \"c\" AS (SELECT E'C:\\\\' FROM \"t\") SELECT * FROM \"c\""],
            'sqlite' => [new SQLiteBuilder(), new SQLiteBuilder(), "'C:\\'", "WITH \"c\" AS (SELECT 'C:\\' FROM \"t\") SELECT * FROM \"c\""],
            'clickhouse' => [new ClickHouseBuilder(), new ClickHouseBuilder(), "'C:\\\\'", "WITH `c` AS (SELECT 'C:\\\\' FROM `t`) SELECT * FROM `c`"],
        ];
    }

    #[DataProvider('cteProvider')]
    public function testToAstParsesCteWithDialectLiteral(Builder $outer, Builder&RawSql $inner, string $literal, string $expected): void
    {
        $inner->from('t')->selectRaw($literal);
        $outer->with('c', $inner)->from('c');

        $ast = $outer->toAst();

        $this->assertSame($expected, $this->serializerFor($outer)->serialize($ast));
    }

    private function serializerFor(Builder $builder): Serializer
    {
        return match (true) {
            $builder instanceof PostgreSQLBuilder => new PostgreSQLSerializer(),
            $builder instanceof SQLiteBuilder => new SQLiteSerializer(),
            $builder instanceof ClickHouseBuilder => new ClickHouseSerializer(),
            default => new MySQLSerializer(),
        };
    }

    private function literalValue(Select $select): mixed
    {
        $this->assertCount(1, $select->columns);
        $column = $select->columns[0];
        $this->assertInstanceOf(Literal::class, $column);

        return $column->value;
    }

    private function parse(Tokenizer $tokenizer, StringLiteral $literal, string $sql): Select
    {
        return new Parser($literal)->parse(Tokenizer::filter($tokenizer->tokenize($sql)));
    }
}
