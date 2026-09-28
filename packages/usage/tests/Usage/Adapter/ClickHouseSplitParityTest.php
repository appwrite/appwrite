<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Differential parity: for every window shape a caller can produce, the
 * routed read must return exactly what a raw scan returns. Routing that is
 * merely cheaper is worthless if it is off by an edge row, so this walks
 * a deterministic matrix of shapes (mid-day, midnight, inclusive/exclusive,
 * straddling today, sub-day, month-long) against boundary-dense seeds and
 * asserts equality on both the flat sum and the batch, plus that the route
 * actually taken is the intended one.
 */
class ClickHouseSplitParityTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metricA = 'parity.metric.a';

    private string $metricB = 'parity.metric.b';

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_split_parity',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        // Boundary-dense seeding: every day from 40 days ago through today
        // gets rows at midnight exactly, mid-morning, and one millisecond
        // before midnight — so any off-by-one at a day edge changes a total.
        for ($back = 40; $back >= 0; $back--) {
            $day = (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d');
            $this->seedAt($this->metricA, 100 + $back, $day . ' 00:00:00.000');
            $this->seedAt($this->metricA, 200 + $back, $day . ' 09:15:00.000');
            $this->seedAt($this->metricA, 300 + $back, $day . ' 23:59:59.999');
            $this->seedAt($this->metricB, 7, $day . ' 12:00:00.000');
        }
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    private function seedAt(string $metric, int $value, string $timestamp): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);
        $id = bin2hex(random_bytes(16));

        $this->queryRaw($this->adapter, sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, tenant) VALUES ('%s', '%s', %d, '%s', '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $timestamp,
        ));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     *         label => [start, end, inclusiveEnd, expectedRoute]
     */
    public static function windowShapes(): array
    {
        $day = static fn (int $back): string =>
            (new DateTime("-{$back} days", new DateTimeZone('UTC')))->format('Y-m-d');

        return [
            // Billing-shaped: anchored at an arbitrary time of day, closed.
            'mid-day both ends' => [$day(30) . ' 14:37:11', $day(3) . ' 14:37:11', false, 'split'],
            'mid-day start, midnight end' => [$day(30) . ' 06:00:00', $day(3) . ' 00:00:00', false, 'split'],
            'midnight start, mid-day end' => [$day(30) . ' 00:00:00', $day(3) . ' 18:20:00', false, 'split'],
            // Day-aligned closed: the pre-existing daily route.
            'both midnight' => [$day(30) . ' 00:00:00', $day(3) . ' 00:00:00', false, 'daily'],
            // Reaching into the running day.
            'mid-day start, straddles today' => [$day(30) . ' 14:00:00', $day(-1) . ' 00:00:00', false, 'split'],
            'midnight start, straddles today' => [$day(30) . ' 00:00:00', $day(-1) . ' 00:00:00', false, 'hybrid'],
            // Inclusive upper bound (lessThanEqual) at both alignments.
            'inclusive mid-day end' => [$day(30) . ' 08:00:00', $day(3) . ' 08:00:00', true, 'split'],
            // Inclusive at midnight covers the midnight instant, which a day
            // row cannot represent — split reads that instant from raw. The
            // pre-existing 'daily' route silently dropped it.
            'inclusive midnight end' => [$day(30) . ' 00:00:00', $day(3) . ' 00:00:00', true, 'split'],
            // Degenerate/small: no whole interior day to route.
            'sub-day window' => [$day(5) . ' 06:00:00', $day(5) . ' 18:00:00', false, 'raw'],
            'exactly one interior day' => [$day(6) . ' 13:00:00', $day(4) . ' 11:00:00', false, 'split'],
            // Long window, the shape a monthly invoice cycle actually uses.
            'month-long mid-day' => [$day(35) . ' 03:33:33', $day(4) . ' 21:11:11', false, 'split'],
        ];
    }

    /**
     * @dataProvider windowShapes
     */
    public function testRoutedSumEqualsRawForEveryWindowShape(string $start, string $end, bool $inclusiveEnd, string $expectedRoute): void
    {
        $bound = $inclusiveEnd
            ? Query::lessThanEqual('time', $end)
            : Query::lessThan('time', $end);

        $queries = [
            Query::equal('metric', [$this->metricA]),
            Query::greaterThanEqual('time', $start),
            $bound,
        ];

        $raw = $this->rawSum($queries);

        $this->adapter->clearRouteLog();
        $routed = $this->usage->sum('1', $queries, 'value', Usage::TYPE_EVENT);
        $log = $this->adapter->getRouteLog();

        $this->assertCount(1, $log);
        $this->assertSame($expectedRoute, $log[0]['route'], "unexpected route for [{$start}, {$end}" . ($inclusiveEnd ? ']' : ')') . ']');
        $this->assertSame($raw, $routed, "routed sum diverged from raw for [{$start}, {$end}" . ($inclusiveEnd ? ']' : ')') . ']');
        $this->assertGreaterThan(0, $raw, 'the shape must actually cover seeded rows or it proves nothing');
    }

    /**
     * @dataProvider windowShapes
     */
    public function testRoutedBatchEqualsRawForEveryWindowShape(string $start, string $end, bool $inclusiveEnd, string $expectedRoute): void
    {
        $bound = $inclusiveEnd
            ? Query::lessThanEqual('time', $end)
            : Query::lessThan('time', $end);

        $queries = [
            Query::greaterThanEqual('time', $start),
            $bound,
        ];

        $expected = [
            $this->metricA => $this->rawSum(array_merge($queries, [Query::equal('metric', [$this->metricA])])),
            $this->metricB => $this->rawSum(array_merge($queries, [Query::equal('metric', [$this->metricB])])),
        ];

        $this->adapter->clearRouteLog();
        $totals = $this->usage->getTotalBatch('1', [$this->metricA, $this->metricB], $queries, Usage::TYPE_EVENT);
        $log = $this->adapter->getRouteLog();

        $this->assertCount(1, $log);
        $this->assertSame($expectedRoute, $log[0]['route']);
        $this->assertSame(
            $expected,
            [$this->metricA => $totals[$this->metricA], $this->metricB => $totals[$this->metricB]],
            "routed batch diverged from raw for [{$start}, {$end}" . ($inclusiveEnd ? ']' : ')') . ']',
        );
    }

    /**
     * Ground truth straight from the raw events table.
     *
     * @param array<Query> $queries
     */
    private function rawSum(array $queries): int
    {
        $reflection = new \ReflectionClass($this->adapter);
        $method = $reflection->getMethod('sumFromTable');
        $method->setAccessible(true);
        $result = $method->invoke($this->adapter, '1', $queries, 'value', Usage::TYPE_EVENT);
        $this->adapter->clearRouteLog();

        return is_int($result) ? $result : 0;
    }
}
