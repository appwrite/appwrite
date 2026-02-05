<?php

namespace Tests\Unit\Utopia\Database\Query;

use Appwrite\Utopia\Database\RuntimeQuery;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Query;

class RuntimeQueryTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /**
     * Helper to compile and filter queries in one step for tests.
     */
    private function compileAndFilter(array $queries, array $payload): ?array
    {
        $compiled = RuntimeQuery::compile($queries);
        return RuntimeQuery::filter($compiled, $payload);
    }

    public function testFilterEmptyQueries(): void
    {
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testFilterWithNoMatchingQuery(): void
    {
        $queries = [Query::equal('name', ['Jane'])];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        $this->assertNull($result);
    }

    public function testFilterWithMatchingQuery(): void
    {
        $queries = [Query::equal('name', ['John'])];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_EQUAL tests
    public function testEqualMatch(): void
    {
        $query = Query::equal('name', ['John']);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualNoMatch(): void
    {
        $query = Query::equal('name', ['Jane']);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testEqualMultipleValuesMatch(): void
    {
        $query = Query::equal('status', ['active', 'pending', 'approved']);
        $payload = ['status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualMultipleValuesNoMatch(): void
    {
        $query = Query::equal('status', ['active', 'pending', 'approved']);
        $payload = ['status' => 'rejected'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testEqualNumericValues(): void
    {
        $query = Query::equal('age', [30, 25, 35]);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualBooleanValues(): void
    {
        $query = Query::equal('active', [true]);
        $payload = ['active' => true];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualMissingAttribute(): void
    {
        $query = Query::equal('missing', ['value']);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    // TYPE_NOT_EQUAL tests
    public function testNotEqualMatch(): void
    {
        $query = Query::notEqual('name', ['Jane']);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testNotEqualNoMatch(): void
    {
        $query = Query::notEqual('name', ['John']);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testNotEqualMultipleValues(): void
    {
        // generally from the client side they will pass query strings via the realtime
        // and Query::parse will be done first and parse doesn't allow multiple notEqual values
        $query = Query::notEqual('status', ['rejected', 'cancelled']);
        $payload = ['status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);

        $query = Query::notEqual('status', ['active', 'pending']);
        $payload = ['status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    // TYPE_LESSER tests
    public function testLesserMatch(): void
    {
        $query = Query::lessThan('age', 30);
        $payload = ['age' => 25];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testLesserNoMatch(): void
    {
        $query = Query::lessThan('age', 30);
        $payload = ['age' => 35];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testLesserEqualValue(): void
    {
        $query = Query::lessThan('age', 30);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testLesserMultipleValues(): void
    {
        // Note: Query::lessThan only accepts single value, but RuntimeQuery's anyMatch supports arrays
        // This test uses a single value as Query class requires
        $query = Query::lessThan('age', 30);
        $payload = ['age' => 25];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testLesserStringComparison(): void
    {
        $query = Query::lessThan('name', 'M');
        $payload = ['name' => 'A'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_LESSER_EQUAL tests
    public function testLesserEqualMatch(): void
    {
        $query = Query::lessThanEqual('age', 30);
        $payload = ['age' => 25];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testLesserEqualExactMatch(): void
    {
        $query = Query::lessThanEqual('age', 30);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testLesserEqualNoMatch(): void
    {
        $query = Query::lessThanEqual('age', 30);
        $payload = ['age' => 35];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testLesserEqualMultipleValues(): void
    {
        // Note: Query::lessThanEqual only accepts single value
        $query = Query::lessThanEqual('age', 30);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_GREATER tests
    public function testGreaterMatch(): void
    {
        $query = Query::greaterThan('age', 30);
        $payload = ['age' => 35];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testGreaterNoMatch(): void
    {
        $query = Query::greaterThan('age', 30);
        $payload = ['age' => 25];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testGreaterEqualValue(): void
    {
        $query = Query::greaterThan('age', 30);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testGreaterMultipleValues(): void
    {
        // Note: Query::greaterThan only accepts single value
        $query = Query::greaterThan('age', 20);
        $payload = ['age' => 35];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_GREATER_EQUAL tests
    public function testGreaterEqualMatch(): void
    {
        $query = Query::greaterThanEqual('age', 30);
        $payload = ['age' => 35];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testGreaterEqualExactMatch(): void
    {
        $query = Query::greaterThanEqual('age', 30);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testGreaterEqualNoMatch(): void
    {
        $query = Query::greaterThanEqual('age', 30);
        $payload = ['age' => 25];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testGreaterEqualMultipleValues(): void
    {
        // Note: Query::greaterThanEqual only accepts single value
        $query = Query::greaterThanEqual('age', 20);
        $payload = ['age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_IS_NULL tests
    public function testIsNullMatch(): void
    {
        $query = Query::isNull('description');
        $payload = ['description' => null];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testIsNullNoMatch(): void
    {
        $query = Query::isNull('description');
        $payload = ['description' => 'Some text'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testIsNullMissingAttribute(): void
    {
        $query = Query::isNull('missing');
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    // TYPE_IS_NOT_NULL tests
    public function testIsNotNullMatch(): void
    {
        $query = Query::isNotNull('description');
        $payload = ['description' => 'Some text'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testIsNotNullNoMatch(): void
    {
        $query = Query::isNotNull('description');
        $payload = ['description' => null];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testIsNotNullMissingAttribute(): void
    {
        $query = Query::isNotNull('missing');
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    // TYPE_AND tests
    public function testAndAllMatch(): void
    {
        $query = Query::and([
            Query::equal('name', ['John']),
            Query::equal('age', [30])
        ]);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testAndOneFails(): void
    {
        $query = Query::and([
            Query::equal('name', ['John']),
            Query::equal('age', [25])
        ]);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testAndAllFail(): void
    {
        $query = Query::and([
            Query::equal('name', ['Jane']),
            Query::equal('age', [25])
        ]);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testAndMultipleConditions(): void
    {
        $query = Query::and([
            Query::equal('status', ['active']),
            Query::greaterThan('age', 18),
            Query::isNotNull('email')
        ]);
        $payload = ['status' => 'active', 'age' => 25, 'email' => 'test@example.com'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testAndNestedAnd(): void
    {
        $query = Query::and([
            Query::equal('name', ['John']),
            Query::and([
                Query::equal('age', [30]),
                Query::equal('status', ['active'])
            ])
        ]);
        $payload = ['name' => 'John', 'age' => 30, 'status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_OR tests
    public function testOrOneMatch(): void
    {
        $query = Query::or([
            Query::equal('name', ['John']),
            Query::equal('name', ['Jane'])
        ]);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testOrAllMatch(): void
    {
        $query = Query::or([
            Query::equal('status', ['active']),
            Query::equal('status', ['pending'])
        ]);
        $payload = ['status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testOrAllFail(): void
    {
        $query = Query::or([
            Query::equal('name', ['Jane']),
            Query::equal('age', [25])
        ]);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testOrMultipleConditions(): void
    {
        $query = Query::or([
            Query::equal('status', ['active']),
            Query::equal('status', ['pending']),
            Query::equal('status', ['approved'])
        ]);
        $payload = ['status' => 'pending'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testOrNestedOr(): void
    {
        $query = Query::or([
            Query::equal('name', ['John']),
            Query::or([
                Query::equal('name', ['Jane']),
                Query::equal('name', ['Bob'])
            ])
        ]);
        $payload = ['name' => 'Bob'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testOrWithDifferentAttributes(): void
    {
        $query = Query::or([
            Query::equal('name', ['John']),
            Query::equal('email', ['john@example.com'])
        ]);
        $payload = ['name' => 'Jane', 'email' => 'john@example.com'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // Complex combinations
    public function testAndOrCombination(): void
    {
        $query = Query::and([
            Query::equal('type', ['user']),
            Query::or([
                Query::equal('status', ['active']),
                Query::equal('status', ['pending'])
            ])
        ]);
        $payload = ['type' => 'user', 'status' => 'active'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testOrAndCombination(): void
    {
        $query = Query::or([
            Query::and([
                Query::equal('name', ['John']),
                Query::equal('age', [30])
            ]),
            Query::and([
                Query::equal('name', ['Jane']),
                Query::equal('age', [25])
            ])
        ]);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // Edge cases
    public function testMultipleQueriesAllMatch(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::equal('age', [30])
        ];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        $this->assertEquals($payload, $result);
    }

    public function testMultipleQueriesFirstMatches(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::equal('age', [25])
        ];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        // With AND logic, if first matches but second doesn't, should return empty
        $this->assertNull($result);
    }

    public function testMultipleQueriesSecondMatches(): void
    {
        $queries = [
            Query::equal('name', ['Jane']),
            Query::equal('age', [30])
        ];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        // With AND logic, if second matches but first doesn't, should return empty
        $this->assertNull($result);
    }

    public function testMultipleQueriesNoneMatch(): void
    {
        $queries = [
            Query::equal('name', ['Jane']),
            Query::equal('age', [25])
        ];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        $this->assertNull($result);
    }

    public function testEmptyPayload(): void
    {
        $query = Query::equal('name', ['John']);
        $payload = [];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertNull($result);
    }

    public function testEmptyAndQuery(): void
    {
        $query = Query::and([]);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        // Empty AND should return true (all conditions pass vacuously)
        $this->assertEquals($payload, $result);
    }

    public function testEmptyOrQuery(): void
    {
        $query = Query::or([]);
        $payload = ['name' => 'John'];
        $result = $this->compileAndFilter([$query], $payload);
        // Empty OR should return false (no conditions match)
        $this->assertNull($result);
    }

    // Type-specific edge cases
    public function testEqualWithZero(): void
    {
        $query = Query::equal('count', [0]);
        $payload = ['count' => 0];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualWithEmptyString(): void
    {
        $query = Query::equal('name', ['']);
        $payload = ['name' => ''];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testEqualWithFalse(): void
    {
        $query = Query::equal('active', [false]);
        $payload = ['active' => false];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testComparisonWithFloat(): void
    {
        $query = Query::greaterThan('score', 8.5);
        $payload = ['score' => 9.2];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testComparisonWithStringNumbers(): void
    {
        $query = Query::lessThan('version', '10');
        $payload = ['version' => '9'];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    // TYPE_SELECT tests - select("*") means "listen to all events"
    public function testSelectAllIsAllowed(): void
    {
        $query = Query::select(['*']);
        $this->assertTrue(RuntimeQuery::isSelectAll($query));
    }

    public function testSelectSpecificFieldsNotAllowed(): void
    {
        $query = Query::select(['name', 'age']);
        $this->assertFalse(RuntimeQuery::isSelectAll($query));
    }

    public function testSelectSingleFieldNotAllowed(): void
    {
        $query = Query::select(['name']);
        $this->assertFalse(RuntimeQuery::isSelectAll($query));
    }

    public function testValidateSelectQueryWithWildcard(): void
    {
        $query = Query::select(['*']);
        // Should not throw
        RuntimeQuery::validateSelectQuery($query);
        $this->assertTrue(true);
    }

    public function testValidateSelectQueryWithSpecificFields(): void
    {
        $query = Query::select(['name', 'age']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only select("*") is allowed in Realtime queries');
        RuntimeQuery::validateSelectQuery($query);
    }

    public function testValidateSelectQueryWithSingleField(): void
    {
        $query = Query::select(['name']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only select("*") is allowed in Realtime queries');
        RuntimeQuery::validateSelectQuery($query);
    }

    public function testSelectInAllowedQueries(): void
    {
        $this->assertContains(Query::TYPE_SELECT, RuntimeQuery::ALLOWED_QUERIES);
    }

    public function testIsSelectAllWithNonSelectQuery(): void
    {
        $query = Query::equal('name', ['John']);
        $this->assertFalse(RuntimeQuery::isSelectAll($query));
    }

    public function testValidateSelectQueryWithNonSelectQuery(): void
    {
        $query = Query::equal('name', ['John']);
        // Should not throw for non-select queries
        RuntimeQuery::validateSelectQuery($query);
        $this->assertTrue(true);
    }

    // Filter tests with select("*")
    public function testFilterWithSelectAllReturnsPayload(): void
    {
        $query = Query::select(['*']);
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }

    public function testFilterWithSelectAllAndOtherQueriesReturnsPayload(): void
    {
        // If select("*") is present, it should return payload regardless of other queries
        $queries = [
            Query::select(['*']),
            Query::equal('name', ['Jane']), // This would normally fail
        ];
        $payload = ['name' => 'John', 'age' => 30];
        $result = $this->compileAndFilter($queries, $payload);
        // select("*") takes precedence - returns payload
        $this->assertEquals($payload, $result);
    }

    public function testFilterWithSelectAllOnEmptyPayload(): void
    {
        $query = Query::select(['*']);
        $payload = [];
        $result = $this->compileAndFilter([$query], $payload);
        $this->assertEquals($payload, $result);
    }
}
