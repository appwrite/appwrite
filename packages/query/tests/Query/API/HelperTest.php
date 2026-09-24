<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\CursorDirection;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;

class HelperTest extends TestCase
{
    public function testIsMethodValid(): void
    {
        $this->assertTrue(Query::isMethod('equal'));
        $this->assertTrue(Query::isMethod('notEqual'));
        $this->assertTrue(Query::isMethod('lessThan'));
        $this->assertTrue(Query::isMethod('greaterThan'));
        $this->assertTrue(Query::isMethod('contains'));
        $this->assertTrue(Query::isMethod('search'));
        $this->assertTrue(Query::isMethod('orderAsc'));
        $this->assertTrue(Query::isMethod('orderDesc'));
        $this->assertTrue(Query::isMethod('orderRandom'));
        $this->assertTrue(Query::isMethod('limit'));
        $this->assertTrue(Query::isMethod('offset'));
        $this->assertTrue(Query::isMethod('cursorAfter'));
        $this->assertTrue(Query::isMethod('cursorBefore'));
        $this->assertTrue(Query::isMethod('isNull'));
        $this->assertTrue(Query::isMethod('isNotNull'));
        $this->assertTrue(Query::isMethod('between'));
        $this->assertTrue(Query::isMethod('select'));
        $this->assertTrue(Query::isMethod('or'));
        $this->assertTrue(Query::isMethod('and'));
        $this->assertTrue(Query::isMethod('crosses'));
        $this->assertTrue(Query::isMethod('vectorDot'));
        $this->assertTrue(Query::isMethod('exists'));
        $this->assertTrue(Query::isMethod('notExists'));
        $this->assertTrue(Query::isMethod('containsAll'));
        $this->assertTrue(Query::isMethod('elemMatch'));
        $this->assertTrue(Query::isMethod('regex'));
        $this->assertTrue(Query::isMethod('count'));
        $this->assertTrue(Query::isMethod('sum'));
        $this->assertTrue(Query::isMethod('avg'));
        $this->assertTrue(Query::isMethod('min'));
        $this->assertTrue(Query::isMethod('max'));
        $this->assertTrue(Query::isMethod('groupBy'));
        $this->assertTrue(Query::isMethod('having'));
        $this->assertTrue(Query::isMethod('distinct'));
        $this->assertTrue(Query::isMethod('join'));
        $this->assertTrue(Query::isMethod('leftJoin'));
        $this->assertTrue(Query::isMethod('rightJoin'));
        $this->assertTrue(Query::isMethod('crossJoin'));
        $this->assertTrue(Query::isMethod('on'));
        $this->assertTrue(Query::isMethod('union'));
        $this->assertTrue(Query::isMethod('unionAll'));
        $this->assertTrue(Query::isMethod('raw'));
    }

    public function testIsMethodInvalid(): void
    {
        $this->assertFalse(Query::isMethod('invalid'));
        $this->assertFalse(Query::isMethod(''));
        $this->assertFalse(Query::isMethod('EQUAL'));
        $this->assertFalse(Query::isMethod('foobar'));
    }

    public function testIsSpatialQueryTrue(): void
    {
        $this->assertTrue(Query::crosses('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::notCrosses('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::distanceEqual('geo', [0, 0], 10)->isSpatialQuery());
        $this->assertTrue(Query::distanceNotEqual('geo', [0, 0], 10)->isSpatialQuery());
        $this->assertTrue(Query::distanceGreaterThan('geo', [0, 0], 10)->isSpatialQuery());
        $this->assertTrue(Query::distanceLessThan('geo', [0, 0], 10)->isSpatialQuery());
        $this->assertTrue(Query::intersects('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::notIntersects('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::overlaps('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::notOverlaps('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::touches('geo', [[0, 0]])->isSpatialQuery());
        $this->assertTrue(Query::notTouches('geo', [[0, 0]])->isSpatialQuery());
    }

    public function testIsSpatialQueryFalse(): void
    {
        $this->assertFalse(Query::equal('name', ['x'])->isSpatialQuery());
        $this->assertFalse(Query::limit(10)->isSpatialQuery());
        $this->assertFalse(Query::orderAsc('name')->isSpatialQuery());
    }

    public function testIsNestedTrue(): void
    {
        $this->assertTrue(Query::or([Query::equal('x', [1])])->isNested());
        $this->assertTrue(Query::and([Query::equal('x', [1])])->isNested());
        $this->assertTrue(Query::elemMatch('items', [Query::equal('x', [1])])->isNested());
    }

    public function testIsNestedFalse(): void
    {
        $this->assertFalse(Query::equal('x', [1])->isNested());
        $this->assertFalse(Query::limit(10)->isNested());
        $this->assertFalse(Query::orderAsc()->isNested());
    }

    public function testCloneDeepCopiesNestedQueries(): void
    {
        $inner = Query::equal('name', ['John']);
        $outer = Query::or([$inner]);
        $cloned = clone $outer;

        $clonedValues = $cloned->getValues();
        $this->assertInstanceOf(Query::class, $clonedValues[0]);
        $this->assertNotSame($inner, $clonedValues[0]);
        $this->assertSame(Method::Equal, $clonedValues[0]->getMethod());
    }

    public function testClonePreservesNonQueryValues(): void
    {
        $query = Query::equal('name', ['John', 42, true]);
        $cloned = clone $query;
        $this->assertSame(['John', 42, true], $cloned->getValues());
    }

    public function testGetByType(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::limit(10),
            Query::greaterThan('age', 18),
            Query::offset(5),
        ];

        $filters = Query::getByType($queries, [Method::Equal, Method::GreaterThan]);
        $this->assertCount(2, $filters);
        $this->assertSame(Method::Equal, $filters[0]->getMethod());
        $this->assertSame(Method::GreaterThan, $filters[1]->getMethod());
    }

    public function testGetByTypeClone(): void
    {
        $original = Query::equal('name', ['John']);
        $queries = [$original];

        $result = Query::getByType($queries, [Method::Equal], true);
        $this->assertNotSame($original, $result[0]);
    }

    public function testGetByTypeNoClone(): void
    {
        $original = Query::equal('name', ['John']);
        $queries = [$original];

        $result = Query::getByType($queries, [Method::Equal], false);
        $this->assertSame($original, $result[0]);
    }

    public function testGetByTypeEmpty(): void
    {
        $queries = [Query::equal('x', [1])];
        $result = Query::getByType($queries, [Method::Limit]);
        $this->assertCount(0, $result);
    }

    public function testGetCursorQueries(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::cursorAfter('abc'),
            Query::limit(10),
            Query::cursorBefore('xyz'),
        ];

        $cursors = Query::getCursorQueries($queries);
        $this->assertCount(2, $cursors);
        $this->assertSame(Method::CursorAfter, $cursors[0]->getMethod());
        $this->assertSame(Method::CursorBefore, $cursors[1]->getMethod());
    }

    public function testGetCursorQueriesNone(): void
    {
        $queries = [Query::equal('name', ['John']), Query::limit(10)];
        $cursors = Query::getCursorQueries($queries);
        $this->assertCount(0, $cursors);
    }

    public function testGroupByType(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::greaterThan('age', 18),
            Query::select(['name', 'email']),
            Query::limit(25),
            Query::offset(10),
            Query::orderAsc('name'),
            Query::orderDesc('age'),
            Query::cursorAfter('doc123'),
        ];

        $grouped = Query::groupByType($queries);

        $this->assertCount(2, $grouped->filters);
        $this->assertSame(Method::Equal, $grouped->filters[0]->getMethod());
        $this->assertSame(Method::GreaterThan, $grouped->filters[1]->getMethod());

        $this->assertCount(1, $grouped->selections);
        $this->assertSame(Method::Select, $grouped->selections[0]->getMethod());

        $this->assertSame(25, $grouped->limit);
        $this->assertSame(10, $grouped->offset);

        $this->assertSame('doc123', $grouped->cursor);
        $this->assertSame(CursorDirection::After, $grouped->cursorDirection);
    }

    public function testGroupByTypeFirstLimitWins(): void
    {
        $queries = [
            Query::limit(10),
            Query::limit(50),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertSame(10, $grouped->limit);
    }

    public function testGroupByTypeFirstOffsetWins(): void
    {
        $queries = [
            Query::offset(5),
            Query::offset(100),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertSame(5, $grouped->offset);
    }

    public function testGroupByTypeFirstCursorWins(): void
    {
        $queries = [
            Query::cursorAfter('first'),
            Query::cursorBefore('second'),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertSame('first', $grouped->cursor);
        $this->assertSame(CursorDirection::After, $grouped->cursorDirection);
    }

    public function testGroupByTypeCursorBefore(): void
    {
        $queries = [
            Query::cursorBefore('doc456'),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertSame('doc456', $grouped->cursor);
        $this->assertSame(CursorDirection::Before, $grouped->cursorDirection);
    }

    /**
     * Regression: previously `cursor = $values[0] ?? $limit`, which coerced a
     * null cursor into the row-count limit. A missing cursor value must stay
     * null, never fall back to an unrelated numeric limit.
     */
    public function testGroupByTypeCursorFallsBackToNullNotLimit(): void
    {
        $queries = [
            Query::limit(25),
            Query::cursorAfter(null),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertSame(25, $grouped->limit);
        $this->assertNull($grouped->cursor);
        $this->assertSame(CursorDirection::After, $grouped->cursorDirection);
    }

    public function testGroupByTypeEmpty(): void
    {
        $grouped = Query::groupByType([]);
        $this->assertSame([], $grouped->filters);
        $this->assertSame([], $grouped->selections);
        $this->assertNull($grouped->limit);
        $this->assertNull($grouped->offset);
        $this->assertNull($grouped->cursor);
        $this->assertNull($grouped->cursorDirection);
    }

    public function testGroupByTypeSkipsNonQueryInstances(): void
    {
        $grouped = Query::groupByType(['not a query', null, 42]);
        $this->assertSame([], $grouped->filters);
    }

    public function testGroupByTypeAggregations(): void
    {
        $queries = [
            Query::count('*', 'total'),
            Query::sum('price'),
            Query::avg('score'),
            Query::min('age'),
            Query::max('salary'),
        ];

        $grouped = Query::groupByType($queries);
        $this->assertCount(5, $grouped->aggregations);
        $this->assertSame(Method::Count, $grouped->aggregations[0]->getMethod());
        $this->assertSame(Method::Max, $grouped->aggregations[4]->getMethod());
    }

    public function testGroupByTypeGroupBy(): void
    {
        $queries = [Query::groupBy(['status', 'country'])];
        $grouped = Query::groupByType($queries);
        $this->assertSame(['status', 'country'], $grouped->groupBy);
    }

    public function testGroupByTypeHaving(): void
    {
        $queries = [Query::having([Query::greaterThan('total', 5)])];
        $grouped = Query::groupByType($queries);
        $this->assertCount(1, $grouped->having);
        $this->assertSame(Method::Having, $grouped->having[0]->getMethod());
    }

    public function testGroupByTypeDistinct(): void
    {
        $queries = [Query::distinct()];
        $grouped = Query::groupByType($queries);
        $this->assertTrue($grouped->distinct);
    }

    public function testGroupByTypeDistinctDefaultFalse(): void
    {
        $grouped = Query::groupByType([]);
        $this->assertFalse($grouped->distinct);
    }

    public function testGroupByTypeJoins(): void
    {
        $queries = [
            Query::join('orders', 'users.id', 'orders.user_id'),
            Query::leftJoin('profiles', 'users.id', 'profiles.user_id'),
            Query::crossJoin('colors'),
        ];
        $grouped = Query::groupByType($queries);
        $this->assertCount(3, $grouped->joins);
        $this->assertSame(Method::Join, $grouped->joins[0]->getMethod());
        $this->assertSame(Method::CrossJoin, $grouped->joins[2]->getMethod());
    }

    public function testGroupByTypeUnions(): void
    {
        $queries = [
            Query::union([Query::equal('x', [1])]),
            Query::unionAll([Query::equal('y', [2])]),
        ];
        $grouped = Query::groupByType($queries);
        $this->assertCount(2, $grouped->unions);
    }

    public function testMergeConcatenates(): void
    {
        $a = [Query::equal('name', ['John'])];
        $b = [Query::greaterThan('age', 18)];

        $result = Query::merge($a, $b);
        $this->assertCount(2, $result);
        $this->assertSame(Method::Equal, $result[0]->getMethod());
        $this->assertSame(Method::GreaterThan, $result[1]->getMethod());
    }

    public function testMergeLimitOverrides(): void
    {
        $a = [Query::limit(10)];
        $b = [Query::limit(50)];

        $result = Query::merge($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame(50, $result[0]->getValue());
    }

    public function testMergeOffsetOverrides(): void
    {
        $a = [Query::offset(5), Query::equal('x', [1])];
        $b = [Query::offset(100)];

        $result = Query::merge($a, $b);
        $this->assertCount(2, $result);
        // equal stays, offset replaced
        $this->assertSame(Method::Equal, $result[0]->getMethod());
        $this->assertSame(100, $result[1]->getValue());
    }

    public function testMergeCursorOverrides(): void
    {
        $a = [Query::cursorAfter('abc')];
        $b = [Query::cursorAfter('xyz')];

        $result = Query::merge($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame('xyz', $result[0]->getValue());
    }

    public function testDiffReturnsUnique(): void
    {
        $shared = Query::equal('name', ['John']);
        $a = [$shared, Query::greaterThan('age', 18)];
        $b = [$shared];

        $result = Query::diff($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame(Method::GreaterThan, $result[0]->getMethod());
    }

    public function testDiffEmpty(): void
    {
        $q = Query::equal('x', [1]);
        $result = Query::diff([$q], [$q]);
        $this->assertCount(0, $result);
    }

    public function testDiffNoOverlap(): void
    {
        $a = [Query::equal('x', [1])];
        $b = [Query::equal('y', [2])];
        $result = Query::diff($a, $b);
        $this->assertCount(1, $result);
    }

    public function testValidatePassesAllowed(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::greaterThan('age', 18),
        ];
        $errors = Query::validate($queries, ['name', 'age']);
        $this->assertCount(0, $errors);
    }

    public function testValidateFailsInvalid(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::greaterThan('secret', 42),
        ];
        $errors = Query::validate($queries, ['name', 'age']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('secret', $errors[0]);
    }

    public function testValidateSkipsNoAttribute(): void
    {
        $queries = [
            Query::limit(10),
            Query::offset(5),
            Query::distinct(),
            Query::orderRandom(),
        ];
        $errors = Query::validate($queries, []);
        $this->assertCount(0, $errors);
    }

    public function testValidateRecursesNested(): void
    {
        $queries = [
            Query::or([
                Query::equal('name', ['John']),
                Query::equal('invalid', ['x']),
            ]),
        ];
        $errors = Query::validate($queries, ['name']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('invalid', $errors[0]);
    }

    public function testValidateGroupByColumns(): void
    {
        $queries = [Query::groupBy(['status', 'bad_col'])];
        $errors = Query::validate($queries, ['status']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bad_col', $errors[0]);
    }

    public function testValidateSkipsStar(): void
    {
        $queries = [Query::count()]; // attribute = '*'
        $errors = Query::validate($queries, []);
        $this->assertCount(0, $errors);
    }

    public function testPageStaticHelper(): void
    {
        $result = Query::page(3, 10);
        $this->assertCount(2, $result);
        $this->assertSame(Method::Limit, $result[0]->getMethod());
        $this->assertSame(10, $result[0]->getValue());
        $this->assertSame(Method::Offset, $result[1]->getMethod());
        $this->assertSame(20, $result[1]->getValue());
    }

    public function testPageStaticHelperFirstPage(): void
    {
        $result = Query::page(1);
        $this->assertSame(25, $result[0]->getValue());
        $this->assertSame(0, $result[1]->getValue());
    }

    public function testPageStaticHelperZero(): void
    {
        $this->expectException(ValidationException::class);
        Query::page(0, 10);
    }

    public function testPageStaticHelperLarge(): void
    {
        $result = Query::page(500, 50);
        $this->assertSame(50, $result[0]->getValue());
        $this->assertSame(24950, $result[1]->getValue());
    }
    //  ADDITIONAL EDGE CASES


    public function testGroupByTypeAllNewTypes(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::count('*', 'total'),
            Query::sum('price'),
            Query::groupBy(['status']),
            Query::having([Query::greaterThan('total', 5)]),
            Query::distinct(),
            Query::join('orders', 'u.id', 'o.uid'),
            Query::union([Query::equal('x', [1])]),
            Query::select(['name']),
            Query::orderAsc('name'),
            Query::limit(10),
            Query::offset(5),
        ];

        $grouped = Query::groupByType($queries);

        $this->assertCount(1, $grouped->filters);
        $this->assertCount(1, $grouped->selections);
        $this->assertCount(2, $grouped->aggregations);
        $this->assertSame(['status'], $grouped->groupBy);
        $this->assertCount(1, $grouped->having);
        $this->assertTrue($grouped->distinct);
        $this->assertCount(1, $grouped->joins);
        $this->assertCount(1, $grouped->unions);
        $this->assertSame(10, $grouped->limit);
        $this->assertSame(5, $grouped->offset);
    }

    public function testGroupByTypeMultipleGroupByMerges(): void
    {
        $queries = [
            Query::groupBy(['a', 'b']),
            Query::groupBy(['c']),
        ];
        $grouped = Query::groupByType($queries);
        $this->assertSame(['a', 'b', 'c'], $grouped->groupBy);
    }

    public function testGroupByTypeMultipleDistinct(): void
    {
        $queries = [
            Query::distinct(),
            Query::distinct(),
        ];
        $grouped = Query::groupByType($queries);
        $this->assertTrue($grouped->distinct);
    }

    public function testGroupByTypeMultipleHaving(): void
    {
        $queries = [
            Query::having([Query::greaterThan('x', 1)]),
            Query::having([Query::lessThan('y', 100)]),
        ];
        $grouped = Query::groupByType($queries);
        $this->assertCount(2, $grouped->having);
    }

    public function testGroupByTypeRawGoesToFilters(): void
    {
        $queries = [Query::raw('1 = 1')];
        $grouped = Query::groupByType($queries);
        $this->assertCount(1, $grouped->filters);
        $this->assertSame(Method::Raw, $grouped->filters[0]->getMethod());
    }

    public function testGroupByTypeEmptyNewKeys(): void
    {
        $grouped = Query::groupByType([]);
        $this->assertSame([], $grouped->aggregations);
        $this->assertSame([], $grouped->groupBy);
        $this->assertSame([], $grouped->having);
        $this->assertFalse($grouped->distinct);
        $this->assertSame([], $grouped->joins);
        $this->assertSame([], $grouped->unions);
    }

    public function testMergeEmptyA(): void
    {
        $b = [Query::equal('x', [1])];
        $result = Query::merge([], $b);
        $this->assertCount(1, $result);
    }

    public function testMergeEmptyB(): void
    {
        $a = [Query::equal('x', [1])];
        $result = Query::merge($a, []);
        $this->assertCount(1, $result);
    }

    public function testMergeBothEmpty(): void
    {
        $result = Query::merge([], []);
        $this->assertCount(0, $result);
    }

    public function testMergePreservesNonSingularFromBoth(): void
    {
        $a = [Query::equal('a', [1]), Query::greaterThan('b', 2)];
        $b = [Query::lessThan('c', 3), Query::equal('d', [4])];
        $result = Query::merge($a, $b);
        $this->assertCount(4, $result);
    }

    public function testMergeBothLimitAndOffset(): void
    {
        $a = [Query::limit(10), Query::offset(5)];
        $b = [Query::limit(50), Query::offset(100)];
        $result = Query::merge($a, $b);
        // Both should be overridden
        $this->assertCount(2, $result);
        $limits = array_filter($result, fn (Query $q) => $q->getMethod() === Method::Limit);
        $offsets = array_filter($result, fn (Query $q) => $q->getMethod() === Method::Offset);
        $this->assertSame(50, array_values($limits)[0]->getValue());
        $this->assertSame(100, array_values($offsets)[0]->getValue());
    }

    public function testMergeCursorTypesIndependent(): void
    {
        $a = [Query::cursorAfter('abc')];
        $b = [Query::cursorBefore('xyz')];
        $result = Query::merge($a, $b);
        // cursorAfter and cursorBefore are different types, both should exist
        $this->assertCount(2, $result);
    }

    public function testMergeMixedWithFilters(): void
    {
        $a = [Query::equal('x', [1]), Query::limit(10), Query::offset(0)];
        $b = [Query::greaterThan('y', 5), Query::limit(50)];
        $result = Query::merge($a, $b);
        // equal stays, old limit removed, offset stays, greaterThan added, new limit added
        $this->assertCount(4, $result);
    }

    public function testDiffEmptyA(): void
    {
        $result = Query::diff([], [Query::equal('x', [1])]);
        $this->assertCount(0, $result);
    }

    public function testDiffEmptyB(): void
    {
        $a = [Query::equal('x', [1]), Query::limit(10)];
        $result = Query::diff($a, []);
        $this->assertCount(2, $result);
    }

    public function testDiffBothEmpty(): void
    {
        $result = Query::diff([], []);
        $this->assertCount(0, $result);
    }

    public function testDiffPartialOverlap(): void
    {
        $shared1 = Query::equal('a', [1]);
        $shared2 = Query::equal('b', [2]);
        $unique = Query::greaterThan('c', 3);

        $a = [$shared1, $shared2, $unique];
        $b = [$shared1, $shared2];
        $result = Query::diff($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame(Method::GreaterThan, $result[0]->getMethod());
    }

    public function testDiffByValueNotReference(): void
    {
        $a = [Query::equal('x', [1])];
        $b = [Query::equal('x', [1])]; // Different objects, same content
        $result = Query::diff($a, $b);
        $this->assertCount(0, $result); // Should match by value
    }

    public function testDiffDoesNotRemoveDuplicatesInA(): void
    {
        $a = [Query::equal('x', [1]), Query::equal('x', [1])];
        $b = [];
        $result = Query::diff($a, $b);
        $this->assertCount(2, $result);
    }

    public function testDiffComplexNested(): void
    {
        $nested = Query::or([Query::equal('a', [1]), Query::equal('b', [2])]);
        $a = [$nested, Query::limit(10)];
        $b = [$nested];
        $result = Query::diff($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame(Method::Limit, $result[0]->getMethod());
    }

    public function testValidateEmptyQueries(): void
    {
        $errors = Query::validate([], ['name', 'age']);
        $this->assertCount(0, $errors);
    }

    public function testValidateEmptyAllowedAttributes(): void
    {
        $queries = [Query::equal('name', ['John'])];
        $errors = Query::validate($queries, []);
        $this->assertCount(1, $errors);
    }

    public function testValidateMixedValidAndInvalid(): void
    {
        $queries = [
            Query::equal('name', ['John']),
            Query::greaterThan('age', 18),
            Query::equal('secret', ['x']),
            Query::lessThan('forbidden', 5),
        ];
        $errors = Query::validate($queries, ['name', 'age']);
        $this->assertCount(2, $errors);
    }

    public function testValidateNestedMultipleLevels(): void
    {
        $queries = [
            Query::or([
                Query::and([
                    Query::equal('name', ['John']),
                    Query::equal('bad', ['x']),
                ]),
                Query::equal('also_bad', ['y']),
            ]),
        ];
        $errors = Query::validate($queries, ['name']);
        $this->assertCount(2, $errors);
    }

    public function testValidateHavingInnerQueries(): void
    {
        $queries = [
            Query::having([
                Query::greaterThan('total', 5),
                Query::lessThan('bad_col', 100),
            ]),
        ];
        $errors = Query::validate($queries, ['total']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('bad_col', $errors[0]);
    }

    public function testValidateGroupByAllValid(): void
    {
        $queries = [Query::groupBy(['status', 'country'])];
        $errors = Query::validate($queries, ['status', 'country']);
        $this->assertCount(0, $errors);
    }

    public function testValidateGroupByMultipleInvalid(): void
    {
        $queries = [Query::groupBy(['status', 'bad1', 'bad2'])];
        $errors = Query::validate($queries, ['status']);
        $this->assertCount(2, $errors);
    }

    public function testValidateAggregateWithAttribute(): void
    {
        $queries = [Query::sum('forbidden_col')];
        $errors = Query::validate($queries, ['allowed_col']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('forbidden_col', $errors[0]);
    }

    public function testValidateAggregateWithAllowedAttribute(): void
    {
        $queries = [Query::sum('price')];
        $errors = Query::validate($queries, ['price']);
        $this->assertCount(0, $errors);
    }

    public function testValidateDollarSignAttributes(): void
    {
        $queries = [
            Query::equal('$id', ['abc']),
            Query::greaterThan('$createdAt', '2024-01-01'),
        ];
        $errors = Query::validate($queries, ['$id', '$createdAt']);
        $this->assertCount(0, $errors);
    }

    public function testValidateJoinAttributeIsTableName(): void
    {
        // Join's attribute is the table name, not a column, so it gets validated
        $queries = [Query::join('orders', 'u.id', 'o.uid')];
        $errors = Query::validate($queries, ['name']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('orders', $errors[0]);
    }

    public function testValidateSelectSkipped(): void
    {
        $queries = [Query::select(['any_col', 'other_col'])];
        $errors = Query::validate($queries, []);
        $this->assertCount(0, $errors);
    }

    public function testValidateExistsSkipped(): void
    {
        $queries = [Query::exists(['any_col'])];
        $errors = Query::validate($queries, []);
        $this->assertCount(0, $errors);
    }

    public function testValidateOrderAscAttribute(): void
    {
        $queries = [Query::orderAsc('forbidden')];
        $errors = Query::validate($queries, ['name']);
        $this->assertCount(1, $errors);
    }

    public function testValidateOrderDescAttribute(): void
    {
        $queries = [Query::orderDesc('allowed')];
        $errors = Query::validate($queries, ['allowed']);
        $this->assertCount(0, $errors);
    }

    public function testValidateEmptyAttributeSkipped(): void
    {
        // Queries with empty string attribute should be skipped
        $queries = [Query::orderAsc('')];
        $errors = Query::validate($queries, []);
        $this->assertCount(0, $errors);
    }

    public function testGetByTypeWithNewTypes(): void
    {
        $queries = [
            Query::count('*', 'total'),
            Query::sum('price'),
            Query::join('t', 'a', 'b'),
            Query::distinct(),
            Query::groupBy(['status']),
        ];

        $aggTypes = array_values(array_filter(Method::cases(), fn (Method $m) => $m->isAggregate()));
        $aggs = Query::getByType($queries, $aggTypes);
        $this->assertCount(2, $aggs);

        $joinTypes = array_values(array_filter(Method::cases(), fn (Method $m) => $m->isJoin()));
        $joins = Query::getByType($queries, $joinTypes);
        $this->assertCount(1, $joins);

        $distinct = Query::getByType($queries, [Method::Distinct]);
        $this->assertCount(1, $distinct);
    }

    // ── Query::diff() edge cases (exercises array_any) ─────────

    public function testDiffIdenticalArraysReturnEmpty(): void
    {
        $queries = [Query::equal('a', [1]), Query::limit(10), Query::orderAsc('name')];
        $result = Query::diff($queries, $queries);
        $this->assertCount(0, $result);
    }

    public function testDiffLargeArrayUsesArrayAny(): void
    {
        $a = [];
        $b = [];
        for ($i = 0; $i < 100; $i++) {
            $a[] = Query::equal('col', [$i]);
            if ($i % 2 === 0) {
                $b[] = Query::equal('col', [$i]);
            }
        }
        $result = Query::diff($a, $b);
        $this->assertCount(50, $result);
    }

    public function testDiffPreservesOrder(): void
    {
        $a = [Query::equal('x', [3]), Query::equal('x', [1]), Query::equal('x', [2])];
        $b = [Query::equal('x', [1])];
        $result = Query::diff($a, $b);
        $this->assertCount(2, $result);
        $this->assertSame([3], $result[0]->getValues());
        $this->assertSame([2], $result[1]->getValues());
    }

    public function testDiffWithDifferentMethodsSameAttribute(): void
    {
        $a = [Query::equal('name', ['John']), Query::notEqual('name', 'John')];
        $b = [Query::equal('name', ['John'])];
        $result = Query::diff($a, $b);
        $this->assertCount(1, $result);
        $this->assertSame(Method::NotEqual, $result[0]->getMethod());
    }

    public function testDiffSingleElementArrays(): void
    {
        $a = [Query::limit(10)];
        $b = [Query::limit(10)];
        $this->assertCount(0, Query::diff($a, $b));

        $b = [Query::limit(20)];
        $this->assertCount(1, Query::diff($a, $b));
    }

    // ── #[\Deprecated] on Query::containsString() ────────────────────

    public function testContainsHasDeprecatedAttribute(): void
    {
        $ref = new \ReflectionMethod(Query::class, 'contains');
        $attrs = $ref->getAttributes(\Deprecated::class);
        $this->assertCount(1, $attrs);

        /** @var \Deprecated $instance */
        $instance = $attrs[0]->newInstance();
        $this->assertNotNull($instance->message);
        $this->assertStringContainsString('containsAny', $instance->message);
    }

    public function testContainsStillFunctions(): void
    {
        $query = @Query::containsString('tags', ['a', 'b']);
        $this->assertSame(Method::Contains, $query->getMethod());
        $this->assertSame('tags', $query->getAttribute());
        $this->assertSame(['a', 'b'], $query->getValues());
    }

    // ══════════════════════════════════════════════════════════════
    // Coverage: Query.php uncovered lines
    // ══════════════════════════════════════════════════════════════

    // ── Query::page() with perPage < 1 (line 1152) ──────────────

    public function testPageThrowsOnZeroPerPage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Per page must be >= 1');
        Query::page(1, 0);
    }

    public function testPageThrowsOnNegativePerPage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Per page must be >= 1');
        Query::page(1, -5);
    }
}
