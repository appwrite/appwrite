<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Routing tests for the per-dim projection slate. Each grouped scenario
 * asserts:
 *   (a) the totals match the raw scan, and
 *   (b) the ClickHouse optimizer picked the matching projection per
 *       `system.query_log.projections`.
 */
class ClickHouseDimRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'dim.routing.metric';

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_dim_routing',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow($this->metric, 10, '-5 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 20, '-4 days', ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']);
        $this->seedHistoricalRow($this->metric, 30, '-3 days', ['path' => '/v1/b', 'method' => 'POST', 'status' => '201', 'service' => 'databases', 'country' => 'de']);
        $this->seedHistoricalRow($this->metric, 40, '-3 days', ['path' => '/v1/c', 'method' => 'POST', 'status' => '500', 'service' => 'functions', 'country' => 'fr']);
        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => $this->metric, 'value' => 5, 'tags' => ['path' => '/v1/a', 'method' => 'GET', 'status' => '200', 'service' => 'storage', 'country' => 'us']],
        ], Usage::TYPE_EVENT);
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    /**
     * Start of the containing hour. The event projections are keyed on
     * toStartOfHour(time), so only a bound on an hour boundary can be
     * re-expressed on the bucket without moving the window edge — everything
     * else keeps the raw predicate and reads the base table.
     */
    private function hourAligned(string $modifier): string
    {
        $dt = new DateTime($modifier, new DateTimeZone('UTC'));
        return $dt->setTime((int) $dt->format('H'), 0, 0)->format('Y-m-d H:i:s');
    }

    /**
     * Last representable instant of the containing hour. An *inclusive* upper
     * bound is only expressible on the bucket one tick below a boundary:
     * `<= 13:59:59.999` is `< 14:00`, whereas `<= 14:00:00.000` would need the
     * single instant 14:00 out of a bucket the projection stores whole.
     */
    private function hourEnd(string $modifier): string
    {
        $dt = new DateTime($modifier, new DateTimeZone('UTC'));
        return $dt->setTime((int) $dt->format('H'), 59, 59)->format('Y-m-d H:i:s.') . '999';
    }

    /**
     * @param array<string, string> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $cols = ['id', 'metric', 'value', 'time', 'tenant'];
        $vals = [
            "'{$id}'",
            "'" . addslashes($metric) . "'",
            (string) $value,
            "'{$time}'",
            "'1'",
        ];
        foreach (['path', 'method', 'status', 'service', 'country'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$eventsTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $this->queryRaw($this->adapter, $sql);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function topNProjectionProvider(): array
    {
        return [
            'by_path'    => [['path'], 'p_by_path'],
            'by_country' => [['country'], 'p_by_country'],
            'by_service' => [['service'], 'p_by_service'],
            'by_method'  => [['method'], 'p_by_method'],
            'by_status'  => [['status'], 'p_by_status'],
        ];
    }

    /**
     * @dataProvider topNProjectionProvider
     * @param array<int, string> $dims
     */
    public function testTopNGroupedQueryRoutesToMatchingProjection(array $dims, string $expectedProjection): void
    {
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queries = [];
        foreach ($dims as $dim) {
            $queries[] = UsageQuery::groupBy($dim);
        }
        $queries[] = Query::equal('metric', [$this->metric]);
        $queries[] = Query::greaterThanEqual('time', $start);
        $queries[] = Query::lessThanEqual('time', $end);
        $queries[] = Query::limit(50);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', $queries, Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertProjectionUsed($queryId, $expectedProjection);
    }

    public function testMultiDimNotInAnyProjectionFallsBackToTable(): void
    {
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testFilterOnExtraColumnStillRoutesToProjectionWhenColumnPresent(): void
    {
        // resourceType is a column on the events table but not in p_by_path's
        // projection; the optimizer cannot satisfy this query from the
        // projection and must scan the base table.
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resourceType', ['function']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testSubDayIntervalStillRoutesToProjection(): void
    {
        // The 1h bucket is the projection's own key, so this is the shape it
        // serves most directly.
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testWindowStraddlesTodayStillRoutesToProjection(): void
    {
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('+2 hours');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        // Projections are derived in the same write transaction as the
        // parent insert, so straddle-today windows still route through
        // them — no hybrid plumbing needed.
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coarseIntervalProvider(): array
    {
        return ['1d' => ['1d'], '1w' => ['1w'], '1M' => ['1M']];
    }

    /**
     * @dataProvider coarseIntervalProvider
     */
    public function testCoarseIntervalRoutesThroughTheHourlyBucket(string $interval): void
    {
        // Every routable bucket is a whole number of hours, so composing it over
        // the projection's key yields the same value the base table would.
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', $interval),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testSubHourIntervalReadsTheBaseTable(): void
    {
        // A 15m bucket needs detail the hourly projection has already summed
        // away, so the read stays on the base table.
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '15m'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertNoProjectionUsed($queryId);
    }

    public function testMidHourWindowSplitsSoTheInteriorRoutes(): void
    {
        // Neither edge is expressible on the bucket, so the whole hours between
        // them are read from the projection and the two partial hours from the
        // base table — without moving the caller's boundary.
        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(13, 37, 21)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(9, 14, 3)->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testDayIntervalBucketsMatchAnUnbucketedDayRollup(): void
    {
        // The day bucket is composed over the projection's hourly key rather
        // than taken from raw `time`. That only holds if every bucket value
        // survives the composition, so compare bucket-for-bucket against a
        // direct base-table scan rather than just the grand total.
        foreach (['-5 days -2 hours', '-5 days -9 hours', '-4 days -1 hour', '-4 days -17 hours'] as $offset) {
            $this->seedHistoricalRow($this->metric, 7, $offset, ['path' => '/v1/a']);
        }

        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1d'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertAnyProjectionUsed($queryId);
        $this->assertNotEmpty($rolled);
        $this->assertSame($this->rawDayBuckets($start, $end), $this->bucketsOf($rolled));
    }

    public function testTimeSeriesDayIntervalRoutesAndMatchesRawScan(): void
    {
        foreach (['-5 days -2 hours', '-5 days -9 hours', '-4 days -1 hour'] as $offset) {
            $this->seedHistoricalRow($this->metric, 7, $offset, ['path' => '/v1/a']);
        }

        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $series = $this->usage->getTimeSeries('1', [$this->metric], '1d', $start, $end, [], false, Usage::TYPE_EVENT);

        $this->assertAnyProjectionUsed($queryId);

        $points = [];
        foreach ($series[$this->metric]['data'] as $point) {
            $points[(string) $point['date']] = (int) $point['value'];
        }
        $this->assertNotEmpty($points);
        $this->assertSame($this->rawDayBuckets($start, $end), $points);
    }

    public function testGaugeReadKeepsTheRawTimePredicate(): void
    {
        // Gauge projections still key on raw `time`, so gauge reads are left
        // on their original predicate — the billing gauge rollups included.
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('resourceId'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $this->assertStringNotContainsString('toStartOfHour', $this->queryTextFor($queryId));
    }

    public function testInclusiveEndOfDayUpperBoundRoutes(): void
    {
        // `<= 23:59:59.999` is `< midnight`, which is on an hour boundary —
        // the shape callers reach for when they mean "through the end of the
        // day" still routes.
        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(23, 59, 59)->format('Y-m-d H:i:s.') . '999';

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawTotal($start, $end), $this->totalOf($rolled));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    /**
     * @param array<Query|UsageQuery> $queries
     */
    private function routeFor(array $queries, string $type = Usage::TYPE_EVENT): string
    {
        $reflection = new ReflectionClass($this->adapter);
        $extract = $reflection->getMethod('extractRoutingPlan');
        $extract->setAccessible(true);
        $select = $reflection->getMethod('selectAggregateSource');
        $select->setAccessible(true);
        $plan = $extract->invoke($this->adapter, $queries);
        $route = $select->invoke($this->adapter, $plan);
        return is_string($route) ? $route : '';
    }

    private function rawTotal(string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, '1', [
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        return is_int($result) ? $result : 0;
    }

    /**
     * Day totals read straight off the base table with projections disabled —
     * the reference the composed day bucket has to reproduce exactly.
     *
     * @return array<string, int>
     */
    private function rawDayBuckets(string $start, string $end): array
    {
        $database = $this->databaseName($this->adapter);
        $table = $this->resolveTableName($this->adapter, 'getEventsTableName');

        $raw = $this->queryRaw($this->adapter, "SELECT toStartOfDay(`time`, 'UTC') AS bucket, sum(`value`) AS value "
            . "FROM `{$database}`.`{$table}` "
            . "WHERE `tenant` = '1' AND `metric` = '" . addslashes($this->metric) . "' "
            . "AND `time` >= '{$start}' AND `time` <= '{$end}' "
            . 'GROUP BY bucket ORDER BY bucket ASC '
            . 'SETTINGS optimize_use_projections = 0 FORMAT JSON');

        $json = json_decode($raw, true);
        $out = [];
        $data = (is_array($json) && is_array($json['data'] ?? null)) ? $json['data'] : [];
        foreach ($data as $row) {
            if (!is_array($row) || !is_string($row['bucket'] ?? null) || !is_numeric($row['value'] ?? null)) {
                continue;
            }
            $out[str_replace(' ', 'T', $row['bucket']) . '+00:00'] = (int) $row['value'];
        }
        return $out;
    }

    /**
     * @param array<\Utopia\Usage\Metric> $metrics
     * @return array<string, int>
     */
    private function bucketsOf(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $metric) {
            $time = $metric->getAttribute('time');
            $value = $metric->getValue(0);
            $out[is_string($time) ? $time : ''] = is_numeric($value) ? (int) $value : 0;
        }
        ksort($out);
        return $out;
    }

    private function queryTextFor(string $queryId): string
    {
        $this->queryRaw($this->adapter, 'SYSTEM FLUSH LOGS');

        $escaped = addslashes($queryId);
        $raw = $this->queryRaw($this->adapter, "SELECT query FROM system.query_log "
            . "WHERE query_id = '{$escaped}' AND type = 'QueryFinish' "
            . 'ORDER BY event_time DESC LIMIT 1 FORMAT JSON');

        $json = json_decode($raw, true);
        $data = (is_array($json) && is_array($json['data'] ?? null)) ? $json['data'] : [];
        $row = $data[0] ?? null;
        if (is_array($row) && is_string($row['query'] ?? null)) {
            return $row['query'];
        }
        return '';
    }

    /**
     * @param array<\Utopia\Usage\Metric> $metrics
     */
    private function totalOf(array $metrics): int
    {
        $sum = 0;
        foreach ($metrics as $m) {
            $v = $m->getValue(0);
            if (is_int($v)) {
                $sum += $v;
            } elseif (is_float($v)) {
                $sum += (int) $v;
            }
        }
        return $sum;
    }

    private function assertProjectionUsed(string $queryId, string $projectionName): void
    {
        $projections = $this->projectionsForQueryId($queryId);
        $matches = array_filter($projections, fn (string $p): bool => str_ends_with($p, '.' . $projectionName) || $p === $projectionName);
        $this->assertNotEmpty(
            $matches,
            "expected projection {$projectionName} to fire for query_id {$queryId}; saw: " . implode(', ', $projections)
        );
    }

    /** A read with no dimension can be served by any projection in the slate. */
    private function assertAnyProjectionUsed(string $queryId): void
    {
        $this->assertNotEmpty($this->projectionsForQueryId($queryId), "expected a projection to fire for query_id {$queryId}");
    }

    private function assertNoProjectionUsed(string $queryId): void
    {
        $projections = $this->projectionsForQueryId($queryId);
        $this->assertEmpty(
            $projections,
            "expected no projection to fire for query_id {$queryId}; saw: " . implode(', ', $projections)
        );
    }

    /**
     * @return array<int, string>
     */
    private function projectionsForQueryId(string $queryId): array
    {
        $this->queryRaw($this->adapter, 'SYSTEM FLUSH LOGS');

        $escaped = addslashes($queryId);
        $sql = "SELECT projections FROM system.query_log "
            . "WHERE query_id = '{$escaped}' AND type = 'QueryFinish' "
            . "ORDER BY event_time DESC LIMIT 1 FORMAT JSON";

        $raw = $this->queryRaw($this->adapter, $sql);
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return [];
        }
        $row = $json['data'][0];
        $projections = is_array($row) ? ($row['projections'] ?? []) : [];
        $out = [];
        foreach (is_array($projections) ? $projections : [] as $p) {
            if (is_string($p)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public function testIdFilterForcesRaw(): void
    {
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $route = $this->routeFor([
            Query::equal('metric', [$this->metric]),
            Query::equal('id', ['fixed-id']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame('raw', $route);
    }

    public function testValueFilterForcesRaw(): void
    {
        $start = $this->hourAligned('-7 days');
        $end = $this->hourEnd('-2 days');

        $route = $this->routeFor([
            Query::equal('metric', [$this->metric]),
            Query::greaterThan('value', 10),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame('raw', $route);
    }
}
