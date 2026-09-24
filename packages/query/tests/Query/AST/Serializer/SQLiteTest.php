<?php

namespace Tests\Query\AST\Serializer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer\SQLite;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Tokenizer\SQLite as SQLiteTokenizer;
use Utopia\Query\Tokenizer\Tokenizer;

class SQLiteTest extends TestCase
{
    private function parse(string $sql): Select
    {
        $tokenizer = new SQLiteTokenizer();
        $tokens = Tokenizer::filter($tokenizer->tokenize($sql));
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    private function serialize(Select $stmt): string
    {
        $serializer = new SQLite();
        return $serializer->serialize($stmt);
    }

    private function roundTrip(string $sql): string
    {
        return $this->serialize($this->parse($sql));
    }

    public function testDoubleQuoteIdentifiers(): void
    {
        $serializer = new SQLite();
        $stmt = $this->parse('SELECT name, email FROM users');
        $result = $serializer->serialize($stmt);

        $this->assertSame('SELECT "name", "email" FROM "users"', $result);
    }

    public function testDoubleQuoteEscaping(): void
    {
        $serializer = new SQLite();
        $stmt = $this->parse('SELECT col FROM t');
        $result = $serializer->serialize($stmt);

        $this->assertStringContainsString('"col"', $result);
        $this->assertStringContainsString('"t"', $result);
    }

    public function testRoundTrip(): void
    {
        $result = $this->roundTrip("SELECT u.name, COUNT(*) AS total FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE u.status = 'active' GROUP BY u.name ORDER BY total DESC LIMIT 10");

        $expected = "SELECT \"u\".\"name\", COUNT(*) AS \"total\" FROM \"users\" AS \"u\" LEFT JOIN \"orders\" AS \"o\" ON \"u\".\"id\" = \"o\".\"user_id\" WHERE \"u\".\"status\" = 'active' GROUP BY \"u\".\"name\" ORDER BY \"total\" DESC LIMIT 10";

        $this->assertSame($expected, $result);
    }

    public function testSimpleSelect(): void
    {
        $result = $this->roundTrip('SELECT id FROM users');

        $this->assertSame('SELECT "id" FROM "users"', $result);
    }

    public function testSelectWithAlias(): void
    {
        $result = $this->roundTrip('SELECT name AS n FROM users u');

        $this->assertSame('SELECT "name" AS "n" FROM "users" AS "u"', $result);
    }
}
