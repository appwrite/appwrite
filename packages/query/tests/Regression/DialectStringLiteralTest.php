<?php

namespace Utopia\Query\Tests\Regression;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Call\Func;
use Utopia\Query\AST\Expression\Aliased;
use Utopia\Query\AST\Literal;
use Utopia\Query\AST\Reference\Column;
use Utopia\Query\AST\Reference\Table;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\MariaDB as MariaDBBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Builder\SQLite as SQLiteBuilder;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\ClickHouse\Engine;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\MySQL as MySQLSchema;
use Utopia\Query\Schema\PostgreSQL as PostgreSQLSchema;
use Utopia\Query\Schema\SQLite as SQLiteSchema;

final class DialectStringLiteralTest extends TestCase
{
    /**
     * @return array<string, array{Builder, string, string}>
     */
    public static function rawSqlProvider(): array
    {
        return [
            'mysql backslash before quote' => [new MySQLBuilder(), "\\' OR 1=1 -- ", "SELECT * FROM `t` WHERE `name` IN ('\\\\'' OR 1=1 -- ')"],
            'mysql ascii backslashes' => [new MySQLBuilder(), 'C:\\dir\\file', "SELECT * FROM `t` WHERE `name` IN ('C:\\\\dir\\\\file')"],
            'postgresql backslash' => [new PostgreSQLBuilder(), 'a\\b', "SELECT * FROM \"t\" WHERE \"name\" IN (E'a\\\\b')"],
            'postgresql without backslash' => [new PostgreSQLBuilder(), "it's", "SELECT * FROM \"t\" WHERE \"name\" IN ('it''s')"],
            'sqlite multibyte before backslash' => [new SQLiteBuilder(), "Zo\u{EB}\\x'", "SELECT * FROM `t` WHERE `name` IN ('Zo\u{EB}\\x''')"],
            'clickhouse multibyte before backslash' => [new ClickHouseBuilder(), "Zo\u{EB}\\x", "SELECT * FROM `t` WHERE `name` IN ('Zo\u{EB}\\\\x')"],
        ];
    }

    #[DataProvider('rawSqlProvider')]
    public function testToRawSqlQuotesPerDialect(Builder $builder, string $value, string $expected): void
    {
        $raw = $builder
            ->from('t')
            ->filter([Query::equal('name', [$value])])
            ->toRawSql();

        $this->assertSame($expected, $raw);
    }

    /**
     * @return array<string, array{Builder, string}>
     */
    public static function refusedRawSqlProvider(): array
    {
        return [
            'mysql gbk lead byte' => [new MySQLBuilder(), "\xbf\\' OR 1=1 -- "],
            'mariadb gbk lead byte' => [new MariaDBBuilder(), "\xbf\\' OR 1=1 -- "],
            'mysql utf-8 continuation byte' => [new MySQLBuilder(), "Zo\u{EB}\\x"],
            'postgresql utf-8 continuation byte' => [new PostgreSQLBuilder(), "Zo\u{EB}\\x"],
        ];
    }

    #[DataProvider('refusedRawSqlProvider')]
    public function testToRawSqlRefusesBackslashAfterHighByte(Builder $builder, string $value): void
    {
        $builder->from('t')->filter([Query::equal('name', [$value])]);

        $this->expectException(ValidationException::class);

        $builder->toRawSql();
    }

    /**
     * @return array<string, array{Statement, string}>
     */
    public static function postgresqlSchemaProvider(): array
    {
        $schema = new PostgreSQLSchema();

        return [
            'table comment' => [$schema->commentOnTable('t', 'a\\b'), "COMMENT ON TABLE \"t\" IS E'a\\\\b'"],
            'column comment' => [$schema->commentOnColumn('t', 'c', "it's"), "COMMENT ON COLUMN \"t\".\"c\" IS 'it''s'"],
            'type values' => [$schema->createType('mood', ['a\\b', "it's"]), "CREATE TYPE \"mood\" AS ENUM (E'a\\\\b', 'it''s')"],
            'sequence name' => [$schema->nextVal('s\\q'), "SELECT nextval(E's\\\\q')"],
        ];
    }

    #[DataProvider('postgresqlSchemaProvider')]
    public function testPostgreSQLSchemaUsesExtendedLiteralForBackslash(Statement $statement, string $expected): void
    {
        $this->assertSame($expected, $statement->query);
    }

    public function testSQLiteSchemaKeepsBackslashInDefault(): void
    {
        $result = new SQLiteSchema()
            ->table('t')
            ->string('name')->default("a\\b'c")
            ->create();

        $this->assertStringContainsString("DEFAULT 'a\\b''c'", $result->query);
    }

    public function testMySQLSchemaRefusesBackslashAfterHighByte(): void
    {
        $this->expectException(ValidationException::class);

        new MySQLSchema()->commentOnTable('t', "\xbf\\' OR 1=1 -- ");
    }

    public function testClickHouseColumnCommentCannotEscapeLiteral(): void
    {
        $result = new ClickHouseSchema()->commentOnColumn('t', 'c', "x\\'");

        $this->assertSame("ALTER TABLE `t` COMMENT COLUMN `c` 'x\\\\'''", $result->query);
    }

    public function testClickHouseReplicatedEngineArgumentCannotEscapeLiteral(): void
    {
        $result = new ClickHouseSchema()
            ->table('events')
            ->integer('id')->primary()
            ->engine(Engine::ReplicatedMergeTree, "x\\'", 'r')
            ->create();

        $this->assertSame(
            "CREATE TABLE `events` (`id` Int32) ENGINE = ReplicatedMergeTree('x\\\\''', 'r') ORDER BY (`id`)",
            $result->query,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function jsonKeyProvider(): array
    {
        return [
            'plain' => ['data.name', "\"data\"->>'name' IN (?)"],
            'quote' => ["data.it's", "\"data\"->>'it''s' IN (?)"],
            'backslash' => ['data.a\\b', "\"data\"->>E'a\\\\b' IN (?)"],
            'nested backslash' => ["data.a\\'.b", "\"data\"->E'a\\\\'''->>'b' IN (?)"],
        ];
    }

    #[DataProvider('jsonKeyProvider')]
    public function testPostgreSQLJsonKeyQuoting(string $attribute, string $expected): void
    {
        $result = new PostgreSQLBuilder()
            ->from('t')
            ->filter([$this->objectEqual($attribute)])
            ->build();

        $this->assertSame('SELECT * FROM "t" WHERE ' . $expected, $result->query);
    }

    public function testPostgreSQLJsonKeyRefusesNul(): void
    {
        $builder = new PostgreSQLBuilder()->from('t')->filter([$this->objectEqual("data.a\0b")]);

        $this->expectException(ValidationException::class);

        $builder->build();
    }

    /**
     * @return array<string, array{class-string<Builder>, string}>
     */
    public static function astLiteralProvider(): array
    {
        return [
            'mysql' => [MySQLBuilder::class, "SELECT CONCAT(`name`, 'a\\\\b''c') AS `x` FROM `t`"],
            'postgresql' => [PostgreSQLBuilder::class, "SELECT CONCAT(\"name\", E'a\\\\b''c') AS \"x\" FROM \"t\""],
            'sqlite' => [SQLiteBuilder::class, "SELECT CONCAT(\"name\", 'a\\b''c') AS \"x\" FROM `t`"],
            'clickhouse' => [ClickHouseBuilder::class, "SELECT CONCAT(`name`, 'a\\\\b''c') AS `x` FROM `t`"],
        ];
    }

    /**
     * @param  class-string<Builder>  $builder
     */
    #[DataProvider('astLiteralProvider')]
    public function testFromAstQuotesLiteralPerDialect(string $builder, string $expected): void
    {
        $ast = new Select(
            columns: [new Aliased(new Func('CONCAT', [new Column('name'), new Literal("a\\b'c")]), 'x')],
            from: new Table('t'),
        );

        $this->assertSame($expected, $builder::fromAst($ast)->build()->query);
    }

    private function objectEqual(string $attribute): Query
    {
        $query = Query::equal($attribute, ['v']);
        $query->setAttributeType(ColumnType::Object->value);

        return $query;
    }
}
