<?php

namespace Tests\Query\AST\Serializer;

use PHPUnit\Framework\TestCase;
use Utopia\Query\AST\Parser;
use Utopia\Query\AST\Serializer\PostgreSQL;
use Utopia\Query\AST\Statement\Select;
use Utopia\Query\Tokenizer\PostgreSQL as PostgreSQLTokenizer;
use Utopia\Query\Tokenizer\Tokenizer;

class PostgreSQLTest extends TestCase
{
    private function parse(string $sql): Select
    {
        $tokenizer = new PostgreSQLTokenizer();
        $tokens = Tokenizer::filter($tokenizer->tokenize($sql));
        $parser = new Parser();
        return $parser->parse($tokens);
    }

    private function serialize(Select $stmt): string
    {
        $serializer = new PostgreSQL();
        return $serializer->serialize($stmt);
    }

    private function roundTrip(string $sql): string
    {
        return $this->serialize($this->parse($sql));
    }

    public function testDoubleQuoteIdentifiers(): void
    {
        $serializer = new PostgreSQL();
        $stmt = $this->parse('SELECT name, email FROM users');
        $result = $serializer->serialize($stmt);

        $this->assertSame('SELECT "name", "email" FROM "users"', $result);
    }

    public function testRoundTrip(): void
    {
        $result = $this->roundTrip("SELECT u.name, COUNT(*) AS total FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE u.status = 'active' GROUP BY u.name ORDER BY total DESC LIMIT 10");

        $expected = "SELECT \"u\".\"name\", COUNT(*) AS \"total\" FROM \"users\" AS \"u\" LEFT JOIN \"orders\" AS \"o\" ON \"u\".\"id\" = \"o\".\"user_id\" WHERE \"u\".\"status\" = 'active' GROUP BY \"u\".\"name\" ORDER BY \"total\" DESC LIMIT 10";

        $this->assertSame($expected, $result);
    }
}
