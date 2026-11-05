<?php

namespace Utopia\Query\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Expression\Binary;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer;
use Utopia\Query\AST\Serializer\MySQL as MySQLSerializer;
use Utopia\Query\AST\Serializer\PostgreSQL as PostgreSQLSerializer;
use Utopia\Query\AST\Serializer\SQLite as SQLiteSerializer;
use Utopia\Query\Builder;
use Utopia\Query\Builder\MariaDB;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Builder\SQLite;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;
use Utopia\Query\StringLiteral;
use Utopia\Query\Tokenizer\MySQL as MySQLTokenizer;
use Utopia\Query\Tokenizer\SQLite as SQLiteTokenizer;
use Utopia\Query\Tokenizer\Tokenizer;

final class AstLikeEscapeTest extends TestCase
{
    /**
     * @return array<string, array{Builder, Serializer, string, string}>
     */
    public static function cteDialects(): array
    {
        return [
            'mysql starts with' => [
                new MySQL(),
                new MySQLSerializer(),
                'startsWith',
                'WITH `x` AS (SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\') SELECT * FROM `x`',
            ],
            'mysql contains' => [
                new MySQL(),
                new MySQLSerializer(),
                'contains',
                'WITH `x` AS (SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\') SELECT * FROM `x`',
            ],
            'mariadb starts with' => [
                new MariaDB(),
                new MySQLSerializer(),
                'startsWith',
                'WITH `x` AS (SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\') SELECT * FROM `x`',
            ],
            'mariadb contains' => [
                new MariaDB(),
                new MySQLSerializer(),
                'contains',
                'WITH `x` AS (SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\') SELECT * FROM `x`',
            ],
            'sqlite starts with' => [
                new SQLite(),
                new SQLiteSerializer(),
                'startsWith',
                'WITH "x" AS (SELECT * FROM "t" WHERE "a" LIKE ? ESCAPE \'\\\') SELECT * FROM "x"',
            ],
            'sqlite contains' => [
                new SQLite(),
                new SQLiteSerializer(),
                'contains',
                'WITH "x" AS (SELECT * FROM "t" WHERE "a" LIKE ? ESCAPE \'\\\') SELECT * FROM "x"',
            ],
        ];
    }

    #[DataProvider('cteDialects')]
    public function testToAstKeepsCteLikeEscape(Builder $builder, Serializer $serializer, string $method, string $expected): void
    {
        $filter = $method === 'contains' ? Query::containsString('a', ['50%']) : Query::startsWith('a', '50%');
        $inner = new ($builder::class)()->from('t')->filter([$filter]);
        $outer = $builder->with('x', $inner)->from('x');

        $this->assertSame(\str_replace('"', '`', $expected), $outer->build()->query);

        $this->assertSame($expected, $serializer->serialize($outer->toAst()));
    }

    public function testMySQLCteAstMatchesBuiltSql(): void
    {
        $builder = new MySQL()->with('x', new MySQL()->from('t')->filter([Query::startsWith('a', '50%')]))->from('x');

        $result = $builder->build();

        $this->assertSame($result->query, new MySQLSerializer()->serialize($builder->toAst()));
        $this->assertSame(['50!%%'], $result->bindings);
    }

    public function testMariaDBCteAstMatchesBuiltSql(): void
    {
        $builder = new MariaDB()->with('x', new MariaDB()->from('t')->filter([Query::containsString('a', ['5_0'])]))->from('x');

        $result = $builder->build();

        $this->assertSame($result->query, new MySQLSerializer()->serialize($builder->toAst()));
        $this->assertSame(['%5!_0%'], $result->bindings);
    }

    public function testPostgreSQLCteAstHasNoEscape(): void
    {
        $builder = new PostgreSQL()->with('x', new PostgreSQL()->from('t')->filter([Query::startsWith('a', '50%')]))->from('x');

        $this->assertSame(
            'WITH "x" AS (SELECT * FROM "t" WHERE "a" ILIKE ?) SELECT * FROM "x"',
            $builder->build()->query,
        );
        $this->assertSame(
            'WITH "x" AS (SELECT * FROM "t" WHERE "a" ILIKE ?) SELECT * FROM "x"',
            new PostgreSQLSerializer()->serialize($builder->toAst()),
        );
    }

    public function testToAstEscapesTopLevelLikeValue(): void
    {
        $builder = new MySQL()->from('t')->filter([Query::startsWith('a', '50%')]);

        $this->assertSame(
            'SELECT * FROM `t` WHERE `a` LIKE \'50!%%\' ESCAPE \'!\'',
            new MySQLSerializer()->serialize($builder->toAst()),
        );
    }

    public function testFromAstDecodesEscapedPattern(): void
    {
        $result = MySQL::fromAst(new MySQL()->from('t')->filter([Query::startsWith('a', '50%')])->toAst())->build();

        $this->assertSame('SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\'', $result->query);
        $this->assertSame(['50!%%'], $result->bindings);
    }

    public function testFromAstKeepsForeignEscapeRaw(): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE a LIKE '5#_0%' ESCAPE '#'"));
        $query = MySQL::fromAst(new Parser()->parse($tokens))->build();

        $this->assertSame('SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\'', $query->query);
        $this->assertSame(['5!_0%'], $query->bindings);
    }

    public function testFromAstKeepsUndecodablePatternWithEscape(): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE a LIKE 'a_b%' ESCAPE '#'"));
        $query = MySQL::fromAst(new Parser()->parse($tokens))->build();

        $this->assertSame('SELECT * FROM `t` WHERE (a LIKE ? ESCAPE \'#\')', $query->query);
        $this->assertSame(['a_b%'], $query->bindings);
    }

    public function testParseRejectsTrailingTokens(): void
    {
        $tokens = Tokenizer::filter(new Tokenizer()->tokenize('SELECT * FROM t WHERE a = 1 garbage'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Unexpected token 'garbage'");

        new Parser()->parse($tokens);
    }

    public function testParseAcceptsSingleTrailingSemicolon(): void
    {
        $tokens = Tokenizer::filter(new Tokenizer()->tokenize('SELECT * FROM t;'));

        $this->assertSame('SELECT * FROM `t`', new MySQLSerializer()->serialize(new Parser()->parse($tokens)));
    }

    public function testParseRejectsSecondStatement(): void
    {
        $tokens = Tokenizer::filter(new Tokenizer()->tokenize('SELECT * FROM t; SELECT 1'));

        $this->expectException(ValidationException::class);

        new Parser()->parse($tokens);
    }

    public function testParseSerializeRoundTripsLikeEscape(): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE a LIKE 'x' ESCAPE '!'"));
        $ast = new Parser()->parse($tokens);

        $this->assertInstanceOf(Binary::class, $ast->where);
        $this->assertSame('LIKE', $ast->where->operator);
        $this->assertInstanceOf(Literal::class, $ast->where->escape);
        $this->assertSame('!', $ast->where->escape->value);
        $this->assertSame("SELECT * FROM `t` WHERE `a` LIKE 'x' ESCAPE '!'", new MySQLSerializer()->serialize($ast));
    }

    public function testParseSerializeRoundTripsNegatedLikeEscape(): void
    {
        $tokens = Tokenizer::filter(new SQLiteTokenizer()->tokenize("SELECT * FROM t WHERE a NOT LIKE 'x\\%' ESCAPE '\\' AND b = 1"));
        $ast = new Parser(StringLiteral::Standard)->parse($tokens);

        $this->assertSame(
            "SELECT * FROM \"t\" WHERE \"a\" NOT LIKE 'x\\%' ESCAPE '\\' AND \"b\" = 1",
            new SQLiteSerializer()->serialize($ast),
        );
    }

    public function testEscapeRemainsUsableAsColumnName(): void
    {
        $tokens = Tokenizer::filter(new Tokenizer()->tokenize('SELECT escape FROM t WHERE escape = 1'));

        $this->assertSame(
            'SELECT `escape` FROM `t` WHERE `escape` = 1',
            new MySQLSerializer()->serialize(new Parser()->parse($tokens)),
        );
    }

    public function testFromAstReadsDialectEscape(): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE a LIKE '50!%%' ESCAPE '!'"));
        $result = MySQL::fromAst(new Parser()->parse($tokens))->build();

        $this->assertSame('SELECT * FROM `t` WHERE `a` LIKE ? ESCAPE \'!\'', $result->query);
        $this->assertSame(['50!%%'], $result->bindings);
    }

    public function testFromAstKeepsUnmappedLikeInsideOr(): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE name LIKE 'a%' ESCAPE '' OR id = 1"));
        $result = MySQL::fromAst(new Parser()->parse($tokens))->build();

        $this->assertSame('SELECT * FROM `t` WHERE ((`name` LIKE \'a%\' ESCAPE \'\') OR `id` IN (?))', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function wildcardEscapes(): array
    {
        return [
            'percent' => ['%%abc', '%'],
            'underscore' => ['__abc%', '_'],
        ];
    }

    #[DataProvider('wildcardEscapes')]
    public function testFromAstKeepsWildcardEscapeRaw(string $pattern, string $escape): void
    {
        $tokens = Tokenizer::filter(new MySQLTokenizer()->tokenize("SELECT * FROM t WHERE a LIKE '{$pattern}' ESCAPE '{$escape}'"));
        $result = MySQL::fromAst(new Parser()->parse($tokens))->build();

        $this->assertSame("SELECT * FROM `t` WHERE (`a` LIKE '{$pattern}' ESCAPE '{$escape}')", $result->query);
        $this->assertSame([], $result->bindings);
    }
}
