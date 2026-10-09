<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\Queries;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Query\Exception as QueryLibraryException;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;

final class QueriesTest extends TestCase
{
    /**
     * @return iterable<string, array{QueryException|QueryLibraryException}>
     */
    public static function refusals(): iterable
    {
        yield 'a database library rejection' => [new QueryException('Invalid query: Attribute not found in schema: missing')];
        yield 'a query library validation error' => [new ValidationException('Invalid join operator: LIKE')];
        yield 'a query library compiler error' => [new QueryLibraryException('Expected ROW or ROWS at position 12')];
        yield 'a dialect gap' => [new UnsupportedException('Full-text search is not supported by this dialect.')];
    }

    #[DataProvider('refusals')]
    public function testEveryRefusedQueryIsTheCallersInvalidQueryAsOnMain(QueryException|QueryLibraryException $refusal): void
    {
        $failure = Queries::failure($refusal);

        $this->assertSame(Exception::GENERAL_QUERY_INVALID, $failure->getType(), 'Main answered every query exception with a 400, never a 5xx');
        $this->assertSame(400, $failure->getCode());
        $this->assertSame($refusal->getMessage(), $failure->getMessage());
    }

    public function testAnUnparsableQueryIsInvalid(): void
    {
        try {
            Queries::parse(['{"method":"missing"}']);
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $error->getType());
            $this->assertSame(400, $error->getCode());

            return;
        }

        $this->fail('An unparsable query must be a 400 ' . Exception::GENERAL_QUERY_INVALID);
    }

    public function testParseReturnsQueries(): void
    {
        $queries = Queries::parse([Query::equal('title', ['Spider-Man'])->toString()]);

        $this->assertCount(1, $queries);
        $this->assertInstanceOf(Query::class, $queries[0]);
        $this->assertSame('title', $queries[0]->getAttribute());
        $this->assertSame(['Spider-Man'], $queries[0]->getValues());
    }
}
