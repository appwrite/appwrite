<?php

namespace Utopia\Tests\Usage;

use Utopia\Query\Query;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

trait UsageBase
{
    protected Usage $usage;

    abstract protected function initializeUsage(): void;

    public function setUp(): void
    {
        $this->initializeUsage();
        $this->createUsageMetrics();
    }

    public function tearDown(): void
    {
        $this->usage->purge('1');
    }

    public function createUsageMetrics(): void
    {
        // Events: additive metrics
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'requests', 'value' => 100, 'tags' => ['region' => 'us-east', 'path' => '/v1/storage', 'method' => 'GET', 'status' => '200', 'resourceType' => 'project', 'resourceId' => 'p1']],
            ['tenant' => '1', 'metric' => 'requests', 'value' => 150, 'tags' => ['region' => 'us-west', 'path' => '/v1/databases', 'method' => 'POST', 'status' => '201', 'resourceType' => 'database', 'resourceId' => 'db1']],
            ['tenant' => '1', 'metric' => 'bandwidth', 'value' => 5000, 'tags' => ['region' => 'us-east', 'path' => '/v1/storage/files', 'method' => 'POST', 'status' => '201', 'resourceType' => 'bucket', 'resourceId' => 'b1']],
        ], Usage::TYPE_EVENT));

        // Gauges: point-in-time snapshots
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'storage', 'value' => 10000, 'tags' => ['resourceId' => 'p1']],
        ], Usage::TYPE_GAUGE));
    }

    public function testAddBatchEvent(): void
    {
        $this->usage->purge('1');

        // addBatch with event type -- values should sum
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'add-metric', 'value' => 10, 'tags' => []],
            ['tenant' => '1', 'metric' => 'add-metric', 'value' => 5, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['add-metric']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(15, $sum);
    }

    public function testAddBatchGauge(): void
    {
        $this->usage->purge('1');

        // addBatch with gauge type
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'gauge-metric', 'value' => 100, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gauge-metric', 'value' => 200, 'tags' => []],
        ], Usage::TYPE_GAUGE));

        // getTotal for gauge returns latest value (argMax)
        $total = $this->usage->getTotal('1', 'gauge-metric', [], Usage::TYPE_GAUGE);
        $this->assertGreaterThanOrEqual(100, $total);
    }

    public function testAddBatchWithBatchSize(): void
    {
        $this->usage->purge('1');

        $metrics = [
            ['tenant' => '1', 'metric' => 'batch-requests', 'value' => 100, 'tags' => ['region' => 'eu-west']],
            ['tenant' => '1', 'metric' => 'batch-requests', 'value' => 150, 'tags' => ['region' => 'eu-east']],
            ['tenant' => '1', 'metric' => 'batch-bandwidth', 'value' => 3000, 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT, 2));

        $results = $this->usage->find('1', [
            Query::equal('metric', ['batch-requests']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testFind(): void
    {
        $results = $this->usage->find('1', [
            Query::equal('metric', ['requests']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testFindWithTimeRange(): void
    {
        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->format('Y-m-d\TH:i:s');

        $results = $this->usage->find('1', [
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testCount(): void
    {
        $count = $this->usage->count('1', [
            Query::equal('metric', ['requests']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testSum(): void
    {
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['requests']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(250, $sum); // 100 + 150
    }

    public function testGetTotal(): void
    {
        $total = $this->usage->getTotal('1', 'requests', [], Usage::TYPE_EVENT);
        $this->assertEquals(250, $total); // event: SUM

        $total = $this->usage->getTotal('1', 'storage', [], Usage::TYPE_GAUGE);
        $this->assertEquals(10000, $total); // gauge: argMax (latest)
    }

    public function testGetTotalBatch(): void
    {
        // Event metrics batch
        $totals = $this->usage->getTotalBatch('1', ['requests', 'bandwidth'], [], Usage::TYPE_EVENT);

        $this->assertArrayHasKey('requests', $totals);
        $this->assertArrayHasKey('bandwidth', $totals);

        $this->assertEquals(250, $totals['requests']);
        $this->assertEquals(5000, $totals['bandwidth']);

        // Gauge metrics batch
        $gaugeTotals = $this->usage->getTotalBatch('1', ['storage'], [], Usage::TYPE_GAUGE);
        $this->assertEquals(10000, $gaugeTotals['storage']);
    }

    public function testGetTotalBatchWithMissingMetric(): void
    {
        $totals = $this->usage->getTotalBatch('1', ['requests', 'nonexistent-metric'], [], Usage::TYPE_EVENT);

        $this->assertEquals(250, $totals['requests']);
        $this->assertEquals(0, $totals['nonexistent-metric']);
    }

    public function testGetTotalBatchEmpty(): void
    {
        $totals = $this->usage->getTotalBatch('1', []);
        $this->assertEmpty($totals);
    }

    public function testGetTimeSeries(): void
    {
        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d H:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d H:i:s');

        $results = $this->usage->getTimeSeries(
            '1',
            ['requests'],
            '1h',
            $start,
            $end,
            [],
            true,
            Usage::TYPE_EVENT,
        );

        $this->assertArrayHasKey('requests', $results);
        $this->assertArrayHasKey('total', $results['requests']);
        $this->assertArrayHasKey('data', $results['requests']);
        $this->assertGreaterThanOrEqual(0, $results['requests']['total']);
    }

    public function testGetTimeSeriesMultipleMetrics(): void
    {
        $start = (new \DateTime())->modify('-1 day')->format('Y-m-d H:i:s');
        $end = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i:s');

        $results = $this->usage->getTimeSeries(
            '1',
            ['requests', 'bandwidth'],
            '1d',
            $start,
            $end,
            [],
            true,
            Usage::TYPE_EVENT,
        );

        $this->assertArrayHasKey('requests', $results);
        $this->assertArrayHasKey('bandwidth', $results);
    }

    public function testEqualWithArrayValues(): void
    {
        // Test equal query with array of values (IN clause)
        $results = $this->usage->find('1', [
            Query::equal('metric', ['requests', 'bandwidth']),
        ], Usage::TYPE_EVENT);

        // Should find all metrics matching either 'requests' or 'bandwidth'
        $this->assertGreaterThanOrEqual(2, count($results));
    }

    public function testContainsQuery(): void
    {
        // Test contains query with multiple values from events
        $results = $this->usage->find('1', [
            Query::contains('metric', ['requests', 'bandwidth']),
        ], Usage::TYPE_EVENT);

        // Should find all metrics matching either 'requests' or 'bandwidth'
        $this->assertGreaterThanOrEqual(2, count($results));

        // Contains is a substring match (like utopia-php/database), not an
        // exact IN match — 'band' should match 'bandwidth'
        $results = $this->usage->find('1', [
            Query::contains('metric', ['band']),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertStringContainsString('band', $result->getMetric());
        }
    }

    /**
     * `containsAny` has to reach the adapter, not fall out of it.
     *
     * The Database adapter's switch had no arm for Method::ContainsAny and no
     * default, so the predicate contributed no WHERE fragment: the query still
     * succeeded and returned every row, which is a wrong answer wearing the
     * shape of a working one. The ClickHouse adapter had handled it all along,
     * so the two backends disagreed on the same query.
     */
    public function testContainsAnyFiltersRatherThanBeingDropped(): void
    {
        $matching = $this->usage->find('1', [
            Query::containsAny('metric', ['requests']),
        ], Usage::TYPE_EVENT);

        $everything = $this->usage->find('1', [], Usage::TYPE_EVENT);

        $this->assertNotEmpty($matching, 'the filter must still match its own rows');
        $this->assertLessThan(
            \count($everything),
            \count($matching),
            'a dropped predicate returns the unfiltered set, which is the failure this guards',
        );

        foreach ($matching as $row) {
            $this->assertSame('requests', $row->getMetric());
        }
    }

    public function testNotContainsQuery(): void
    {
        // notContains is a negated substring match — excludes any metric
        // containing 'request', keeps 'bandwidth'
        $results = $this->usage->find('1', [
            Query::notContains('metric', ['request']),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(1, count($results));
        foreach ($results as $result) {
            $this->assertStringNotContainsString('request', $result->getMetric());
        }
    }

    public function testContainsEscapesLikeWildcards(): void
    {
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'cache%hit', 'value' => 1, 'tags' => []],
        ], Usage::TYPE_EVENT));

        // '%' in a needle is a literal, not a LIKE wildcard — an unescaped
        // '%' would match every metric
        $results = $this->usage->find('1', [
            Query::contains('metric', ['%']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(1, $results);
        $this->assertEquals('cache%hit', $results[0]->getMetric());

        // '_' is a literal too — as a wildcard 'cache_hit' would match
        // 'cache%hit'
        $results = $this->usage->find('1', [
            Query::contains('metric', ['cache_hit']),
        ], Usage::TYPE_EVENT);
        $this->assertCount(0, $results);
    }

    public function testLessThanEqualQuery(): void
    {
        $now = (new \DateTime())->format('Y-m-d\TH:i:s');
        $results = $this->usage->find('1', [
            Query::lessThanEqual('time', $now),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testGreaterThanEqualQuery(): void
    {
        $past = (new \DateTime())->modify('-24 hours')->format('Y-m-d\TH:i:s');
        $results = $this->usage->find('1', [
            Query::greaterThanEqual('time', $past),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(0, count($results));
    }

    public function testPurge(): void
    {
        sleep(2);

        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'purge-test', 'value' => 999, 'tags' => []],
        ], Usage::TYPE_EVENT);

        sleep(2);

        $status = $this->usage->purge('1', [], Usage::TYPE_EVENT);
        $this->assertTrue($status);

        $results = $this->usage->find('1', [
            Query::equal('metric', ['purge-test']),
        ], Usage::TYPE_EVENT);
        $this->assertEquals(0, count($results));
    }

    public function testPurgeWithQueries(): void
    {
        $this->usage->purge('1');

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'purge-keep', 'value' => 10, 'tags' => []],
            ['tenant' => '1', 'metric' => 'purge-remove', 'value' => 20, 'tags' => []],
        ], Usage::TYPE_EVENT));

        // Purge only the 'purge-remove' metric
        $status = $this->usage->purge('1', [
            Query::equal('metric', ['purge-remove']),
        ], Usage::TYPE_EVENT);
        $this->assertTrue($status);

        // 'purge-remove' should be gone
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['purge-remove']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(0, $sum);

        // 'purge-keep' should still exist
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['purge-keep']),
        ], 'value', Usage::TYPE_EVENT);
        $this->assertEquals(10, $sum);
    }

    public function testWithQueries(): void
    {
        $results = $this->usage->find('1', [
            Query::equal('metric', ['requests']),
            Query::limit(1),
        ], Usage::TYPE_EVENT);

        $this->assertEquals(1, count($results));

        $results2 = $this->usage->find('1', [
            Query::equal('metric', ['requests']),
            Query::limit(1),
            Query::offset(1),
        ], Usage::TYPE_EVENT);

        $this->assertLessThanOrEqual(1, count($results2));
    }

    public function testEmptyBatch(): void
    {
        $this->assertTrue($this->usage->addBatch([], Usage::TYPE_EVENT));
    }

    public function testAddBatchWithTags(): void
    {
        $metrics = [
            ['tenant' => '1', 'metric' => 'tagged', 'value' => 10, 'tags' => ['region' => 'us-east']],
            ['tenant' => '1', 'metric' => 'tagged', 'value' => 20, 'tags' => ['region' => 'us-west']],
            ['tenant' => '1', 'metric' => 'tagged', 'value' => 15, 'tags' => ['region' => 'eu-west']],
        ];

        $this->assertTrue($this->usage->addBatch($metrics, Usage::TYPE_EVENT));

        $results = $this->usage->find('1', [
            Query::equal('metric', ['tagged']),
        ], Usage::TYPE_EVENT);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testGroupByIntervalHourly(): void
    {
        $this->usage->purge('1');

        // Insert metrics spread across the current hour
        $now = new \DateTime();

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'gbi-requests', 'value' => 100, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-requests', 'value' => 50, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-bandwidth', 'value' => 3000, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $start = (clone $now)->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (clone $now)->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', ['gbi-requests']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(1, count($results));

        // All results should have bucketed time values and summed values
        $totalValue = 0;
        foreach ($results as $metric) {
            $this->assertEquals('gbi-requests', $metric->getMetric());
            $this->assertNotNull($metric->getTime());
            $totalValue += $metric->getValue();
        }

        // SUM should be 100 + 50 = 150
        $this->assertEquals(150, $totalValue);
    }

    public function testGroupByIntervalDaily(): void
    {
        $this->usage->purge('1');

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'gbi-daily', 'value' => 200, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-daily', 'value' => 300, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $start = (new \DateTime())->modify('-1 day')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 day')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1d'),
            Query::equal('metric', ['gbi-daily']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(1, count($results));

        $totalValue = 0;
        foreach ($results as $metric) {
            $this->assertEquals('gbi-daily', $metric->getMetric());
            $totalValue += $metric->getValue();
        }

        $this->assertEquals(500, $totalValue);
    }

    public function testGroupByIntervalGauge(): void
    {
        $this->usage->purge('1');

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'gbi-storage', 'value' => 1000, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-storage', 'value' => 2000, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-storage', 'value' => 3000, 'tags' => []],
        ], Usage::TYPE_GAUGE));

        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', ['gbi-storage']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $this->assertGreaterThanOrEqual(1, count($results));

        // Gauge uses argMax — should return the latest value per bucket
        foreach ($results as $metric) {
            $this->assertEquals('gbi-storage', $metric->getMetric());
            $this->assertGreaterThanOrEqual(1000, $metric->getValue());
        }
    }

    public function testGroupByIntervalInvalidInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UsageQuery::groupByInterval('time', '2h');
    }

    public function testGroupByIntervalWithLimitOffset(): void
    {
        $this->usage->purge('1');

        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'gbi-limit', 'value' => 10, 'tags' => []],
            ['tenant' => '1', 'metric' => 'gbi-limit', 'value' => 20, 'tags' => []],
        ], Usage::TYPE_EVENT));

        $start = (new \DateTime())->modify('-1 hour')->format('Y-m-d\TH:i:s');
        $end = (new \DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s');

        $results = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', ['gbi-limit']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(10),
        ], Usage::TYPE_EVENT);

        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testGroupByUnknownAttributeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches("/groupBy attribute 'not_a_column'/");

        $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('not_a_column'),
            Query::equal('metric', ['gbi-requests']),
        ], Usage::TYPE_EVENT);
    }

    public function testGroupByWithoutGroupByIntervalReturnsDimOnlyAggregate(): void
    {
        // Without groupByInterval the result is a flat aggregate per
        // (metric, …dims) — no time bucketing, ordered by value DESC by
        // default (top-N table semantics).
        $rows = $this->usage->find('1', [
            UsageQuery::groupBy('service'),
            Query::equal('metric', ['gbi-requests']),
        ], Usage::TYPE_EVENT);

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('time', $row->getArrayCopy());
            $this->assertArrayHasKey('service', $row->getArrayCopy());
        }
    }
}
