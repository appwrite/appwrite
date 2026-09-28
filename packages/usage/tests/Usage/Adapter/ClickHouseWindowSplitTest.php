<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Console windows never land on an hour boundary, so a grouped read is split
 * into an hour-aligned interior — which routes to a projection — plus its
 * partial edge hours off the base table. Every scenario asserts both halves:
 *   (a) the rows equal a projection-free scan of the same window, and
 *   (b) the interior actually routed, per `system.query_log.projections`.
 */
class ClickHouseWindowSplitTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'split.metric';

    /** Hour boundary the seeded window is laid out around. */
    private DateTime $origin;

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_window_split',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $origin = new DateTime('-9 days', new DateTimeZone('UTC'));
        $this->origin = $origin->setTime((int) $origin->format('H'), 0, 0);

        // Four readings an hour across three days, each on a different second and
        // millisecond so an edge bound that lands mid-hour has rows either side.
        $rows = [];
        for ($hour = -2; $hour < 74; $hour++) {
            for ($slot = 0; $slot < 4; $slot++) {
                $rows[] = $this->seedRow($hour, $slot);
            }
        }
        $this->insertRows($rows);
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    private function instant(int $hour, int $minute = 0, int $second = 0, int $milli = 0): string
    {
        $time = (clone $this->origin)
            ->modify("{$hour} hours")
            ->modify("{$minute} minutes")
            ->modify("{$second} seconds");

        return $time->format('Y-m-d H:i:s.') . str_pad((string) $milli, 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, string|int>
     */
    private function seedRow(int $hour, int $slot): array
    {
        // The first seeded hour is negative; shift it non-negative before any
        // modulo, which in PHP keeps the sign of the dividend.
        $n = $hour + 2;

        return [
            'time' => $this->instant($hour, $slot * 17 + 3, $slot * 13 + 1, $slot * 111 + 7),
            'value' => 1 + ($n * 7 + $slot * 3) % 23,
            'path' => '/v1/p' . ($n % 3),
            'country' => 'C' . ($slot % 2),
            'service' => ['storage', 'databases', 'functions'][($n + $slot) % 3],
            'method' => ['GET', 'POST', 'DELETE'][$slot % 3],
            'status' => ['200', '201', '404', '500'][($n + $slot) % 4],
            'clientType' => ['browser', 'server'][$n % 2],
            'clientName' => 'client' . (($n + $slot) % 4),
            'deviceName' => ['desktop', 'smartphone'][$slot % 2],
            'osName' => ['Linux', 'Mac', 'Windows'][($n + 1) % 3],
            'sdk' => ['web', 'flutter', 'node'][($slot + 1) % 3],
        ];
    }

    /**
     * @param array<int, array<string, string|int>> $rows
     */
    private function insertRows(array $rows): void
    {
        $table = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $columns = ['path', 'country', 'service', 'method', 'status', 'clientType', 'clientName', 'deviceName', 'osName', 'sdk'];

        $values = [];
        foreach ($rows as $row) {
            $cells = [
                "'" . bin2hex(random_bytes(16)) . "'",
                "'" . addslashes($this->metric) . "'",
                (string) $row['value'],
                "'" . $row['time'] . "'",
                "'1'",
            ];
            foreach ($columns as $column) {
                $cells[] = "'" . addslashes((string) $row[$column]) . "'";
            }
            $values[] = '(' . implode(', ', $cells) . ')';
        }

        $sql = "INSERT INTO `{$database}`.`{$table}` (id, metric, value, time, tenant, " . implode(', ', $columns) . ') VALUES '
            . implode(', ', $values);

        $this->queryRaw($this->adapter, $sql);
    }

    /**
     * The same grouped read taken straight off the base table with projections
     * disabled — the reference a split window has to reproduce exactly.
     *
     * @param array<int, string> $dims
     * @param array<int, string> $extraWhere
     * @return array<string, int>
     */
    private function rawGrouped(
        string $start,
        string $end,
        array $dims = [],
        ?string $interval = null,
        string $aggregate = 'sum',
        array $extraWhere = [],
    ): array {
        $database = $this->databaseName($this->adapter);
        $table = $this->resolveTableName($this->adapter, 'getEventsTableName');

        $select = [];
        if ($interval !== null) {
            $select[] = 'toStartOfInterval(`time`, ' . UsageQuery::VALID_INTERVALS[$interval] . ') AS `bucket`';
        }
        foreach ($dims as $dim) {
            $select[] = "`{$dim}`";
        }

        $keys = $select === [] ? [] : $select;
        $where = array_merge([
            "`tenant` = '1'",
            "`metric` = '" . addslashes($this->metric) . "'",
            "`time` >= '{$start}'",
            "`time` <= '{$end}'",
        ], $extraWhere);

        $sql = 'SELECT ' . implode(', ', array_merge($keys, ["{$aggregate}(`value`) AS `value`"]))
            . " FROM `{$database}`.`{$table}`"
            . ' WHERE ' . implode(' AND ', $where)
            . ($keys === [] ? '' : ' GROUP BY ' . implode(', ', $keys))
            . ' SETTINGS optimize_use_projections = 0 FORMAT JSONEachRow';

        $out = [];
        foreach (explode("\n", trim($this->queryRaw($this->adapter, $sql))) as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || !is_numeric($row['value'] ?? null)) {
                continue;
            }
            $out[$this->keyOf($row, $dims, $interval !== null)] = (int) $row['value'];
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<\Utopia\Usage\Metric> $metrics
     * @param array<int, string> $dims
     * @return array<string, int>
     */
    private function normalize(array $metrics, array $dims = [], bool $hasBucket = false): array
    {
        $out = [];
        foreach ($metrics as $metric) {
            $row = $metric->getArrayCopy();
            $out[$this->keyOf($row, $dims, $hasBucket)] = (int) $metric->getValue(0);
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<mixed, mixed> $row
     * @param array<int, string> $dims
     */
    private function keyOf(array $row, array $dims, bool $hasBucket): string
    {
        $parts = [];
        if ($hasBucket) {
            // find() renames the bucket to `time` and stamps the zone, while the raw
            // scan returns it bare — and coarser than a day it is a Date, not a
            // DateTime. Strip both back to one spelling so the keys line up.
            $bucket = $row['bucket'] ?? $row['time'] ?? '';
            $parts[] = is_string($bucket) ? str_replace(['T', '+00:00'], [' ', ''], $bucket) : '';
        }
        foreach ($dims as $dim) {
            $value = $row[$dim] ?? null;
            $parts[] = is_scalar($value) ? (string) $value : '';
        }

        return implode('|', $parts);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: ?string, 2: ?string}>
     */
    public static function splitShapeProvider(): array
    {
        return [
            'breakdown_path'      => [['path'], null, 'p_by_path'],
            'breakdown_country'   => [['country'], null, 'p_by_country'],
            'breakdown_method'    => [['method'], null, 'p_by_method'],
            'breakdown_status'    => [['status'], null, 'p_by_status'],
            'breakdown_clientType' => [['clientType'], null, 'p_by_clientType'],
            'breakdown_clientName' => [['clientName'], null, 'p_by_clientName'],
            'breakdown_deviceName' => [['deviceName'], null, 'p_by_deviceName'],
            'breakdown_osName'    => [['osName'], null, 'p_by_osName'],
            'breakdown_sdk'       => [['sdk'], null, 'p_by_sdk'],
            'breakdown_service'   => [['service'], null, 'p_by_service'],
            // A dimensionless chart can be served by any projection in the slate,
            // so it only asserts that one of them fired.
            'chart_1h'            => [[], '1h', null],
            'chart_1d'            => [[], '1d', null],
            'chart_1w'            => [[], '1w', null],
            'chart_1M'            => [[], '1M', null],
            'chart_1d_by_path'    => [['path'], '1d', 'p_by_path'],
            'chart_1h_by_country' => [['country'], '1h', 'p_by_country'],
        ];
    }

    /**
     * @dataProvider splitShapeProvider
     * @param array<int, string> $dims
     */
    public function testSplitWindowMatchesRawScanAndRoutes(array $dims, ?string $interval, ?string $projection): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queries = [];
        foreach ($dims as $dim) {
            $queries[] = UsageQuery::groupBy($dim);
        }
        if ($interval !== null) {
            $queries[] = UsageQuery::groupByInterval('time', $interval);
        }
        $queries[] = Query::equal('metric', [$this->metric]);
        $queries[] = Query::greaterThanEqual('time', $start);
        $queries[] = Query::lessThanEqual('time', $end);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', $queries, Usage::TYPE_EVENT);

        $this->assertNotEmpty($rolled);
        $this->assertSame(
            $this->rawGrouped($start, $end, $dims, $interval),
            $this->normalize($rolled, $dims, $interval !== null),
        );
        if ($projection === null) {
            $this->assertNotEmpty($this->projectionsForQueryId($queryId), 'expected the interior to route');
        } else {
            $this->assertProjectionUsed($queryId, $projection);
        }
    }

    public function testTopNLimitAppliesAfterTheBranchesAreRecombined(): void
    {
        // A LIMIT inside a branch would truncate before the edge hours were added
        // back, so it belongs to the outer query.
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('clientName'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(2),
        ], Usage::TYPE_EVENT);

        $expected = $this->rawGrouped($start, $end, ['clientName']);
        arsort($expected);
        $expected = array_slice($expected, 0, 2, true);
        ksort($expected);

        $this->assertCount(2, $rolled);
        $this->assertSame($expected, $this->normalize($rolled, ['clientName']));
        $this->assertProjectionUsed($queryId, 'p_by_clientName');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oneSidedEdgeProvider(): array
    {
        return ['upper_edge_only' => ['aligned', 'mid'], 'lower_edge_only' => ['mid', 'aligned']];
    }

    /**
     * @dataProvider oneSidedEdgeProvider
     */
    public function testOneSidedEdgeStillRoutes(string $lower, string $upper): void
    {
        $start = $lower === 'aligned' ? $this->instant(1) : $this->instant(0, 37, 21, 456);
        // An inclusive bound one tick below the boundary is the aligned spelling.
        $end = $upper === 'aligned' ? $this->instant(70, 59, 59, 999) : $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawGrouped($start, $end, ['path']), $this->normalize($rolled, ['path']));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testSplitCarriesEveryBranchesOwnBindings(): void
    {
        // Three branches each number their placeholders from param0. If the
        // renaming mismatched a value, a branch would filter on the wrong metric
        // or path and the totals would silently drift.
        $this->insertRows([['time' => $this->instant(3, 20), 'value' => 1000, 'path' => '/v1/other'] + $this->seedRow(3, 0)]);

        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('path', ['/v1/p0', '/v1/p2']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $end, ['path'], null, 'sum', ["`path` IN ('/v1/p0', '/v1/p2')"]),
            $this->normalize($rolled, ['path']),
        );
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testWindowInsideOneHourFallsBackToTheUnsplitQuery(): void
    {
        // Peeling edges off would leave no whole hour to route, so the read keeps
        // its single raw-time query.
        $start = $this->instant(5, 12, 30);
        $end = $this->instant(5, 47, 10, 500);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertNotEmpty($rolled);
        $this->assertSame($this->rawGrouped($start, $end, ['path']), $this->normalize($rolled, ['path']));
        $this->assertNoProjectionUsed($queryId);
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    public function testWindowSpanningOneBoundaryWithoutAWholeHourFallsBack(): void
    {
        $start = $this->instant(5, 31);
        $end = $this->instant(6, 29);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawGrouped($start, $end, ['path']), $this->normalize($rolled, ['path']));
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: bool}>
     */
    public static function deltaWindowProvider(): array
    {
        // The concurrency delta folder reads events on a 5-minute-aligned window
        // and writes the result back as a gauge, so its totals must not move
        // whether or not the window is wide enough to peel an hour out of.
        return [
            'five_minute_slice' => ['00:00', '04:59.999', null, false],
            'five_minute_bucket' => ['00:00', '04:59.999', '5m', false],
            'multi_hour_slice' => ['00:00', '3:04:59.999', null, true],
        ];
    }

    /**
     * @dataProvider deltaWindowProvider
     */
    public function testFiveMinuteAlignedDeltaWindowKeepsItsResults(string $from, string $to, ?string $interval, bool $splits): void
    {
        [$fromMinute, $fromSecond] = array_map('intval', explode(':', $from));
        $start = $this->instant(2, $fromMinute, $fromSecond);
        $end = $this->offsetInstant(2, $to);

        $queries = [
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ];
        if ($interval !== null) {
            $queries[] = UsageQuery::groupByInterval('time', $interval);
        }

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', $queries, Usage::TYPE_EVENT);

        $this->assertNotEmpty($rolled);
        $this->assertSame(
            $this->rawGrouped($start, $end, ['service'], $interval),
            $this->normalize($rolled, ['service'], $interval !== null),
        );
        $this->assertSame($splits, str_contains($this->queryTextFor($queryId), 'UNION ALL'));
    }

    /** Accepts `mm:ss[.mmm]` or `h:mm:ss[.mmm]` relative to the given hour. */
    private function offsetInstant(int $hour, string $offset): string
    {
        $parts = explode(':', $offset);
        $hour += count($parts) === 3 ? (int) array_shift($parts) : 0;
        [$second, $milli] = array_pad(explode('.', $parts[1]), 2, '0');

        return $this->instant($hour, (int) $parts[0], (int) $second, (int) $milli);
    }

    public function testSubHourIntervalKeepsReadingTheBaseTable(): void
    {
        // A 15m bucket needs detail the hourly interior has already summed away.
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '15m'),
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $end, ['path'], '15m'),
            $this->normalize($rolled, ['path'], true),
        );
        $this->assertNoProjectionUsed($queryId);
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    public function testDimsNoProjectionCoversAreNotSplit(): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            UsageQuery::groupBy('country'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $end, ['path', 'country']),
            $this->normalize($rolled, ['path', 'country']),
        );
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    public function testFilterOutsideTheProjectionIsNotSplit(): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::equal('country', ['C0']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $end, ['path'], null, 'sum', ["`country` = 'C0'"]),
            $this->normalize($rolled, ['path']),
        );
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    public function testAggregateMaxIsNotSplit(): void
    {
        // The projections store sum(value), so a max() read has nothing to route
        // to and would pay for the extra branches.
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            UsageQuery::aggregate('max'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $end, ['path'], null, 'max'),
            $this->normalize($rolled, ['path']),
        );
        $this->assertStringNotContainsString('UNION ALL', $this->queryTextFor($queryId));
    }

    public function testGaugeReadIsNeverSplit(): void
    {
        // Billing rolls gauges up through the same grouped path; it has to keep
        // reading raw `time`.
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('resourceId'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $text = $this->queryTextFor($queryId);
        $this->assertStringNotContainsString('UNION ALL', $text);
        $this->assertStringNotContainsString('toStartOfHour', $text);
    }

    public function testExclusiveBoundsSplitOnTheSameWindow(): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 790);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThan('time', $this->instant(0, 37, 21, 455)),
            Query::lessThan('time', $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame(
            $this->rawGrouped($start, $this->instant(71, 14, 3, 789), ['path']),
            $this->normalize($rolled, ['path']),
        );
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testBetweenBoundsSplitOnTheSameWindow(): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(71, 14, 3, 789);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::between('time', $start, $end),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawGrouped($start, $end, ['path']), $this->normalize($rolled, ['path']));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    public function testOpenEndedWindowSplitsOnTheBoundedSideOnly(): void
    {
        $start = $this->instant(0, 37, 21, 456);
        $end = $this->instant(80);

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $rolled = $this->usage->find('1', [
            UsageQuery::groupBy('path'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
        ], Usage::TYPE_EVENT);

        $this->assertSame($this->rawGrouped($start, $end, ['path']), $this->normalize($rolled, ['path']));
        $this->assertProjectionUsed($queryId, 'p_by_path');
    }

    private function queryTextFor(string $queryId): string
    {
        $this->queryRaw($this->adapter, 'SYSTEM FLUSH LOGS');

        $escaped = addslashes($queryId);
        $raw = $this->queryRaw($this->adapter, 'SELECT query FROM system.query_log '
            . "WHERE query_id = '{$escaped}' AND type = 'QueryFinish' "
            . 'ORDER BY event_time DESC LIMIT 1 FORMAT JSON');

        $json = json_decode($raw, true);
        $data = (is_array($json) && is_array($json['data'] ?? null)) ? $json['data'] : [];
        $row = $data[0] ?? null;

        return (is_array($row) && is_string($row['query'] ?? null)) ? $row['query'] : '';
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
        $raw = $this->queryRaw($this->adapter, 'SELECT projections FROM system.query_log '
            . "WHERE query_id = '{$escaped}' AND type = 'QueryFinish' "
            . 'ORDER BY event_time DESC LIMIT 1 FORMAT JSON');

        $json = json_decode($raw, true);
        if (!is_array($json) || !is_array($json['data'] ?? null) || $json['data'] === []) {
            return [];
        }
        $row = $json['data'][0];
        $projections = is_array($row) ? ($row['projections'] ?? []) : [];

        $out = [];
        foreach (is_array($projections) ? $projections : [] as $projection) {
            if (is_string($projection)) {
                $out[] = $projection;
            }
        }

        return $out;
    }
}
