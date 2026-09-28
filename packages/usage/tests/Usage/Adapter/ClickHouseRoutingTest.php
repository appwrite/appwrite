<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Exception;
use ReflectionClass;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

class ClickHouseRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_routing',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow('routed.metric', 100, '-5 days', ['path' => '/v1/a']);
        $this->seedHistoricalRow('routed.metric', 200, '-3 days', ['path' => '/v1/b']);
        $this->assertTrue($this->usage->addBatch([
            ['tenant' => '1', 'metric' => 'routed.metric', 'value' => 50, 'tags' => ['path' => '/v1/c']],
        ], Usage::TYPE_EVENT));
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

        $id = bin2hex(random_bytes(16));
        $rawPath = $tags['path'] ?? null;
        $path = is_string($rawPath) ? $rawPath : null;

        $sql = sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, path, tenant) VALUES ('%s', '%s', %d, '%s', %s, '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $time,
            $path === null ? 'NULL' : "'" . addslashes($path) . "'"
        );

        $this->queryRaw($this->adapter, $sql);
    }

    public function testClosedDayWindowRoutesToDaily(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route'], 'an inclusive midnight bound needs the boundary instant read raw; the interior still comes from the rollup');
        $this->assertSame($rawSum, $sum, 'daily MV must re-aggregate to the same total as raw');
    }

    public function testInclusiveMidnightUpperBoundExcludesTheEndDayButKeepsTheMidnightInstant(): void
    {
        // Two rows on the end day: one at 14:00 (must be excluded — the bound
        // is midnight, not end-of-day) and one at exactly midnight (must be
        // included — the bound is inclusive). A day-granularity rollup row
        // cannot express that difference: routing this window to 'daily'
        // translated `<= midnight` into `< midnight` and silently dropped the
        // midnight row, under-billing by its value. It now routes 'split':
        // interior days from the rollup, the boundary instant from raw.
        $this->seedHistoricalRow('routed.metric', 9999, '-2 days +14 hours', ['path' => '/v1/late']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->seedHistoricalRowAt('routed.metric', 42, $end);

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $this->adapter->clearRouteLog();
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'the routed read must match raw exactly at an inclusive midnight bound');
        $this->assertSame(342, $sum, 'the 300 interior + the 42 row at exactly midnight, never the 9999 mid-end-day row');
    }

    public function testMidDayClosedWindowRoutesSplit(): void
    {
        // Daily rows are stored at midnight; a mid-day caller bound cannot be
        // forwarded to the daily MV wholesale. It routes 'split' instead: the
        // interior whole days from the rollup, the partial edge days from raw
        // — never over- or under-including the edges.
        $start = (new DateTime('-7 days 12:30:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days 12:30:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $this->adapter->clearRouteLog();
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'split must equal the raw scan over the same mid-day bounds');
    }

    public function testWindowStraddlesTodayRoutesHybrid(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'hybrid must equal full raw scan');
    }

    public function testHybridSumKeepsParamsDistinctWhenMetricFollowsTimeBounds(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $sum = $this->usage->sum('1', [
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::equal('metric', ['routed.metric']),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'hybrid sum must match the raw scan even when the metric filter is appended after the time bounds');
    }

    public function testDimensionPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::equal('path', ['/v1/a']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testFilterOnNonDailyColumnForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::equal('country', ['us']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testMidDayStartWithHybridWindowFallsBackToRaw(): void
    {
        $metric = 'routed.metric.hybrid_mid_day';
        $this->seedHistoricalRow($metric, 70, '-2 days 03:00:00', ['path' => '/v1/early']);

        $this->adapter->clearRouteLog();

        $start = (new DateTime('-2 days 14:00:00', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $sum = $this->usage->sum('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route'], 'a mid-day start with a straddling window routes split: rollup interior, raw edges');
        $this->assertSame(0, $sum, 'pre-start events on the same day must not be included in the result');
    }

    public function testIntervalPresentForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            UsageQuery::groupByInterval('time', '1h'),
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testDuplicateTimeFiltersTakeTightestBound(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $startTighter = (new DateTime('-3 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endLoose = (new DateTime('+5 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endTighter = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::greaterThanEqual('time', $startTighter),
            Query::lessThanEqual('time', $endLoose),
            Query::lessThanEqual('time', $endTighter),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route'], 'tightest bound is an inclusive midnight, which routes split');
        $this->assertSame($startTighter, $log[0]['start']);
        $this->assertSame($endTighter, $log[0]['end']);
    }

    public function testOpenEndedWindowForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    public function testMalformedTimeStringForcesRaw(): void
    {
        $this->adapter->clearRouteLog();

        try {
            $this->usage->sum('1', [
                Query::equal('metric', ['routed.metric']),
                Query::greaterThanEqual('time', 'not-a-date'),
                Query::lessThanEqual('time', 'not-a-date-either'),
            ], 'value', Usage::TYPE_EVENT);
        } catch (Exception $e) {
        }

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route']);
    }

    /**
     * A purge whose filters reference a column the daily MV does not
     * carry (e.g. `path`) AND has no `time` bound must not issue an
     * unbounded delete on the daily table: that would wipe rows for
     * unrelated metrics. The raw events table still gets the narrow
     * delete; the daily MV is left untouched until the next ingest
     * cycle overwrites it.
     */
    public function testNarrowPurgeWithNoTimeBoundDoesNotWipeDailyMv(): void
    {
        $this->usage->purge('1');

        // Seed two metrics on two different days; let the daily MV
        // capture both as fully closed-day rows.
        $this->seedHistoricalRow('purge.keep', 100, '-3 days', ['path' => '/v1/keep']);
        $this->seedHistoricalRow('purge.remove', 50, '-3 days', ['path' => '/v1/remove']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        // Purge by a daily-incompatible column (`path`) with no time
        // bound. The raw delete narrows on path; the daily side has
        // no path column so the legacy logic would fall through to
        // DELETE WHERE 1=1 and wipe both metrics' daily rows.
        $this->usage->purge('1', [
            Query::equal('path', ['/v1/remove']),
        ], Usage::TYPE_EVENT);

        $this->adapter->clearRouteLog();
        $keepSum = $this->usage->sum('1', [
            Query::equal('metric', ['purge.keep']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);

        $this->assertSame(100, $keepSum, 'unrelated daily rows must survive a narrow purge');
    }

    /**
     * Purging by `value` against the daily MV applies the predicate to
     * the SUMmed daily value, not the raw per-event value. Allowing
     * `value` as a daily-safe filter would delete unrelated aggregate
     * rows whose daily total happens to match the predicate. The
     * routing layer treats `value` as raw-only.
     */
    public function testValueFilterPurgeDoesNotMatchAggregateDailyRows(): void
    {
        $this->usage->purge('1');

        // Seed two raw rows whose values sum to 10 on the same day,
        // so the daily MV row has value = 10. A naive purge with
        // `value = 10` would delete the daily row and undercount.
        $this->seedHistoricalRow('purge.value', 4, '-3 days', ['path' => '/v1/a']);
        $this->seedHistoricalRow('purge.value', 6, '-3 days', ['path' => '/v1/b']);

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $this->usage->purge('1', [
            Query::equal('value', [10]),
        ], Usage::TYPE_EVENT);

        // value is raw-only, so the routed sum stays on raw — it
        // sees the still-present rows. The point of this test is the
        // daily MV; check it directly via sumDaily.
        $dailySum = $this->usage->sumDaily('1', [
            Query::equal('metric', ['purge.value']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ]);

        $this->assertSame(10, $dailySum, 'daily MV row must survive a value-only purge');
    }

    public function testClosedDayWindowRoutesTotalBatchToDaily(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $totals = $this->usage->getTotalBatch('1', ['routed.metric', 'routed.absent'], [
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('getTotalBatch', $log[0]['operation']);
        $this->assertSame('split', $log[0]['route'], 'an inclusive midnight bound needs the boundary instant read raw; the interior still comes from the rollup');
        $this->assertSame($rawSum, $totals['routed.metric'], 'the daily rollup must re-aggregate to the same batch total as raw');
        $this->assertSame(0, $totals['routed.absent'], 'an absent metric still comes back as zero');
    }

    public function testOpenWindowRoutesTotalBatchToHybrid(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 day', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $rawSum = $this->sumRaw('routed.metric', $start, $end);

        $totals = $this->usage->getTotalBatch('1', ['routed.metric'], [
            Query::greaterThanEqual('time', $start),
            Query::lessThan('time', $end),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('getTotalBatch', $log[0]['operation']);
        $this->assertSame('hybrid', $log[0]['route']);
        $this->assertSame($rawSum, $totals['routed.metric'], 'closed days from the rollup plus today from raw must equal the raw batch total');
    }

    public function testNonRollupFilterKeepsTotalBatchOnRaw(): void
    {
        $this->adapter->clearRouteLog();

        $start = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        $totals = $this->usage->getTotalBatch('1', ['routed.metric'], [
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::equal('path', ['/v1/a']),
        ], Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('getTotalBatch', $log[0]['operation']);
        $this->assertSame('raw', $log[0]['route'], 'a filter column the rollup does not carry must fall back to the raw table');
        $this->assertSame(100, $totals['routed.metric']);
    }

    public function testNonAlignedWindowRoutesSumToSplit(): void
    {
        // Billing-shaped window: mid-day start, mid-day end — 0 of 451k
        // production teams have midnight-aligned invoice dates, so this is
        // the shape routing must serve or it serves nothing.
        $day = fn (int $back, string $time): string =>
            (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d') . ' ' . $time;

        $start = $day(7, '14:30:00');
        $end = $day(1, '10:00:00');

        // Boundary-precise seeds: excluded-before, head edge, interior, tail
        // edge, excluded-after.
        $this->seedHistoricalRowAt('routed.metric', 1000, $day(7, '10:00:00'));
        $this->seedHistoricalRowAt('routed.metric', 3, $day(7, '18:00:00'));
        $this->seedHistoricalRowAt('routed.metric', 7, $day(4, '12:00:00'));
        $this->seedHistoricalRowAt('routed.metric', 13, $day(1, '08:00:00'));
        $this->seedHistoricalRowAt('routed.metric', 5000, $day(1, '11:30:00'));

        $queries = [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThan('time', $end),
        ];

        $rawSum = $this->sumRawHalfOpen('routed.metric', $start, $end);

        $this->adapter->clearRouteLog();
        $sum = $this->usage->sum('1', $queries, 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('split', $log[0]['route']);
        $this->assertSame($rawSum, $sum, 'daily interior + raw edges must partition the window exactly');
    }

    public function testNonAlignedWindowRoutesTotalBatchToSplit(): void
    {
        $day = fn (int $back, string $time): string =>
            (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d') . ' ' . $time;

        $start = $day(7, '14:30:00');
        $end = $day(1, '10:00:00');

        $this->seedHistoricalRowAt('routed.metric', 3, $day(7, '18:00:00'));
        $this->seedHistoricalRowAt('routed.other', 21, $day(4, '12:00:00'));
        $this->seedHistoricalRowAt('routed.other', 9, $day(1, '08:00:00'));

        $queries = [
            Query::greaterThanEqual('time', $start),
            Query::lessThan('time', $end),
        ];

        $expected = [
            'routed.metric' => $this->sumRawHalfOpen('routed.metric', $start, $end),
            'routed.other' => $this->sumRawHalfOpen('routed.other', $start, $end),
        ];

        $this->adapter->clearRouteLog();
        $totals = $this->usage->getTotalBatch('1', ['routed.metric', 'routed.other'], $queries, Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('getTotalBatch', $log[0]['operation']);
        $this->assertSame('split', $log[0]['route']);
        $this->assertSame($expected, ['routed.metric' => $totals['routed.metric'], 'routed.other' => $totals['routed.other']]);
    }

    public function testIrregularTimeFilterStaysRaw(): void
    {
        // A notBetween carves a mid-day hole inside the window. Day-granularity
        // rollup rows cannot honor it, and the split interior would drop it —
        // both would over-count, so it must stay raw. Value-checked: the raw
        // path applies the hole, and routed reads must match it.
        $day = fn (int $back, string $time): string =>
            (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d') . ' ' . $time;

        $start = $day(7, '00:00:00');
        $end = $day(2, '00:00:00');

        $this->seedHistoricalRowAt('routed.metric', 40, $day(4, '11:00:00'));

        $this->adapter->clearRouteLog();
        $sum = $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $start),
            Query::lessThan('time', $end),
            Query::notBetween('time', $day(4, '10:00:00'), $day(4, '12:00:00')),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route'], 'a non-window time filter cannot be expressed on day rows');

        $rawWithoutHole = $this->sumRawHalfOpen('routed.metric', $start, $end);
        $this->assertSame($rawWithoutHole - 40, $sum, 'the mid-day hole must exclude the seeded row');
    }

    public function testSubDayWindowStaysRaw(): void
    {
        $day = fn (int $back, string $time): string =>
            (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d') . ' ' . $time;

        $this->adapter->clearRouteLog();

        $this->usage->sum('1', [
            Query::equal('metric', ['routed.metric']),
            Query::greaterThanEqual('time', $day(1, '10:00:00')),
            Query::lessThan('time', $day(1, '14:00:00')),
        ], 'value', Usage::TYPE_EVENT);

        $log = $this->adapter->getRouteLog();
        $this->assertCount(1, $log);
        $this->assertSame('raw', $log[0]['route'], 'a window with no whole interior day has nothing the rollup can answer');
    }

    /**
     * Raw ground truth over a half-open window, matching the routed calls.
     */
    private function sumRawHalfOpen(string $metric, string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, '1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThan('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();
        return is_int($result) ? $result : 0;
    }

    /**
     * Seed one raw event row at an absolute UTC timestamp.
     */
    private function seedHistoricalRowAt(string $metric, int $value, string $timestamp): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);
        $id = bin2hex(random_bytes(16));
        $this->queryRaw($this->adapter, sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, tenant) VALUES ('%s', '%s', %d, '%s.000', '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $timestamp,
        ));
    }

    private function sumRaw(string $metric, string $start, string $end): int
    {
        $reflection = new ReflectionClass($this->adapter);
        $sumFromTable = $reflection->getMethod('sumFromTable');
        $sumFromTable->setAccessible(true);
        $result = $sumFromTable->invoke($this->adapter, '1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();
        return is_int($result) ? $result : 0;
    }
}
