<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;

class FullTextSearchTest extends TestCase
{
    use AssertsBindingCount;

    public function testMySQLFilterSearchEmitsBooleanMatchAgainst(): void
    {
        $result = (new MySQLBuilder())
            ->from('articles')
            ->filterSearch('content', 'tutorial')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `articles` WHERE MATCH(`content`) AGAINST(? IN BOOLEAN MODE)', $result->query);
        // MySQL boolean-mode adds a '*' suffix to enable prefix matching.
        $this->assertSame(['tutorial*'], $result->bindings);
    }

    public function testMySQLFilterNotSearchWrapsWithNot(): void
    {
        $result = (new MySQLBuilder())
            ->from('articles')
            ->filterNotSearch('content', 'spam')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `articles` WHERE NOT (MATCH(`content`) AGAINST(? IN BOOLEAN MODE))', $result->query);
        $this->assertSame(['spam*'], $result->bindings);
    }

    public function testPostgreSQLFilterSearchEmitsTsVectorMatchOperator(): void
    {
        $result = (new PostgreSQLBuilder())
            ->from('articles')
            ->filterSearch('content', 'tutorial')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame("SELECT * FROM \"articles\" WHERE to_tsvector(regexp_replace(\"content\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?)", $result->query);
        $this->assertSame(['tutorial'], $result->bindings);
    }

    public function testPostgreSQLFilterNotSearchNegatesMatchOperator(): void
    {
        $result = (new PostgreSQLBuilder())
            ->from('articles')
            ->filterNotSearch('content', 'spam')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame("SELECT * FROM \"articles\" WHERE NOT (to_tsvector(regexp_replace(\"content\", '[^\\w]+', ' ', 'g')) @@ websearch_to_tsquery(?))", $result->query);
    }

    public function testFilterSearchAndFilterNotSearchBindInOrder(): void
    {
        $result = (new MySQLBuilder())
            ->from('articles')
            ->filterSearch('title', 'good')
            ->filterNotSearch('body', 'bad')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(['good*', 'bad*'], $result->bindings);
    }

    public function testMySQLFilterSearchEmptyValueEmitsNeverMatch(): void
    {
        // An empty search term is degenerate; MySQL boolean-mode rewrites it
        // to a tautology-never so no binding is added.
        $result = (new MySQLBuilder())
            ->from('articles')
            ->filterSearch('content', '')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `articles` WHERE 1 = 0', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testChainableReturnsSameInstance(): void
    {
        $builder = new MySQLBuilder();
        $returned = $builder->from('articles')->filterSearch('content', 'x');

        $this->assertSame($builder, $returned);
    }
}
