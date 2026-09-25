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
     * @return iterable<string, array{QueryLibraryException}>
     */
    public static function faults(): iterable
    {
        yield 'a compiler fault' => [new QueryLibraryException('Expected ROW or ROWS at position 12')];
        yield 'a dialect gap' => [new UnsupportedException('Full-text search is not supported by this dialect.')];
    }

    /**
     * @return iterable<string, array{QueryException|ValidationException}>
     */
    public static function rejections(): iterable
    {
        yield 'a database library rejection' => [new QueryException('Invalid query: Attribute not found in schema: missing')];
        yield 'a query library validation error' => [new ValidationException('Invalid join operator: LIKE')];
    }

    #[DataProvider('faults')]
    public function testAFaultIsNotTheCallersInvalidQuery(QueryLibraryException $fault): void
    {
        $failure = Queries::failure($fault);

        $this->assertSame($fault, $failure, 'a query-library fault must reach the error handler unchanged, which answers it with a 5xx');
    }

    #[DataProvider('rejections')]
    public function testARejectedQueryIsTheCallersInvalidQuery(QueryException|ValidationException $rejection): void
    {
        $failure = Queries::failure($rejection);

        $this->assertInstanceOf(Exception::class, $failure);
        $this->assertSame(Exception::GENERAL_QUERY_INVALID, $failure->getType());
        $this->assertSame(400, $failure->getCode());
        $this->assertSame($rejection->getMessage(), $failure->getMessage());
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
