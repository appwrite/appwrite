<?php

namespace Utopia\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Usage\UsageQuery;

class UsageQueryTest extends TestCase
{
    public function testGroupByIntervalCreation(): void
    {
        $query = UsageQuery::groupByInterval('time', '1h');

        $this->assertInstanceOf(UsageQuery::class, $query);
        $this->assertEquals(Method::GroupByTimeBucket, $query->getMethod());
        $this->assertEquals('time', $query->getAttribute());
        $this->assertEquals(['1h'], $query->getValues());
        $this->assertEquals('1h', $query->getValue());
    }

    public function testGroupByIntervalAllValidIntervals(): void
    {
        $validIntervals = ['1m', '5m', '15m', '30m', '1h', '1d', '1w', '1M'];

        foreach ($validIntervals as $interval) {
            $query = UsageQuery::groupByInterval('time', $interval);
            $this->assertEquals($interval, $query->getValue());
        }
    }

    public function testGroupByIntervalInvalidInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid interval '2h'");
        UsageQuery::groupByInterval('time', '2h');
    }

    public function testGroupByIntervalInvalidIntervalEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UsageQuery::groupByInterval('time', '');
    }

    public function testValidIntervalsConstant(): void
    {
        $this->assertArrayHasKey('1m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('5m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('15m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('30m', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1h', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1d', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1w', UsageQuery::VALID_INTERVALS);
        $this->assertArrayHasKey('1M', UsageQuery::VALID_INTERVALS);

        // Verify interval SQL values
        $this->assertEquals('INTERVAL 1 HOUR', UsageQuery::VALID_INTERVALS['1h']);
        $this->assertEquals('INTERVAL 1 DAY', UsageQuery::VALID_INTERVALS['1d']);
        $this->assertEquals('INTERVAL 1 MINUTE', UsageQuery::VALID_INTERVALS['1m']);
        $this->assertEquals('INTERVAL 1 MONTH', UsageQuery::VALID_INTERVALS['1M']);
    }

    public function testUsageQueryExtendsQuery(): void
    {
        $query = UsageQuery::groupByInterval('time', '1h');
        $this->assertInstanceOf(Query::class, $query);
    }

    public function testGroupByCreation(): void
    {
        $query = UsageQuery::groupBy('service');

        $this->assertInstanceOf(UsageQuery::class, $query);
        $this->assertEquals(Method::GroupBy, $query->getMethod());
        $this->assertEquals('service', $query->getAttribute());
        $this->assertEquals([], $query->getValues());
    }

    public function testGroupByParseRoundTrip(): void
    {
        $json = json_encode([
            'method' => Method::GroupBy->value,
            'attribute' => 'service',
            'values' => [],
        ]);
        $this->assertIsString($json);

        $parsed = UsageQuery::parse($json);

        $this->assertEquals(Method::GroupBy, $parsed->getMethod());
        $this->assertEquals('service', $parsed->getAttribute());
        $this->assertEquals([], $parsed->getValues());
    }

    public function testAggregateCreation(): void
    {
        $query = UsageQuery::aggregate('max');

        $this->assertInstanceOf(UsageQuery::class, $query);
        // Since query 0.3 the function is the method itself rather than a
        // TYPE_AGGREGATE string with the name in the values. Values stay empty:
        // base Query::max() reserves them for an alias.
        $this->assertSame(Method::Max, $query->getMethod());
        $this->assertSame('value', $query->getAttribute());
        $this->assertSame([], $query->getValues());
        $this->assertSame('max', UsageQuery::extractAggregate([$query]));
    }

    public function testAggregateAcceptsAllValidFunctions(): void
    {
        foreach (UsageQuery::VALID_AGGREGATES as $function) {
            $query = UsageQuery::aggregate($function);
            $this->assertSame($function, UsageQuery::extractAggregate([$query]));
        }
    }

    public function testAggregateRejectsInvalidFunction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid aggregate 'peak'");
        UsageQuery::aggregate('peak');
    }

    public function testExtractAggregate(): void
    {
        $queries = [
            Query::equal('metric', ['realtime.connections']),
            UsageQuery::aggregate('max'),
        ];

        $this->assertEquals('max', UsageQuery::extractAggregate($queries));
    }

    public function testExtractAggregateFromParsedQuery(): void
    {
        // Queries created via Query::parse() are base Query objects, not UsageQuery.
        $parsedAggregate = new Query(Method::Max, 'value', []);
        $equal = Query::equal('metric', ['realtime.connections']);

        $this->assertEquals('max', UsageQuery::extractAggregate([$equal, $parsedAggregate]));
    }

    public function testExtractAggregateReturnsNullWhenMissing(): void
    {
        $queries = [
            Query::equal('metric', ['realtime.connections']),
            UsageQuery::groupByInterval('time', '1h'),
        ];

        $this->assertNull(UsageQuery::extractAggregate($queries));
    }

    public function testValidAggregatesConstant(): void
    {
        // `max` is the only selectable aggregate: it overrides the per-type
        // default. `sum` is absent on purpose - already the default for events,
        // and on gauges it would total point-in-time snapshots.
        $this->assertSame(['max'], UsageQuery::VALID_AGGREGATES);
    }
}
