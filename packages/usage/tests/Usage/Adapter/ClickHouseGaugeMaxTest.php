<?php

namespace Utopia\Tests\Usage\Adapter;

use Utopia\Query\Query;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;
use Utopia\Usage\UsageQuery;

/**
 * Integration tests for `aggregate('max')` on the gauge read path.
 *
 * Gauges default to `argMax(value, time)` — the *latest* reading in the bucket.
 * That is right for a snapshot ("how much storage is used now"), but wrong for
 * rolling a sampled level series up to a coarser interval: reading a realtime
 * concurrency gauge sampled every 5 minutes at a 1h interval should give the
 * hour's highest sample, not its last one.
 *
 * Rows are inserted with raw SQL so the query under test stays isolated from
 * the write path.
 */
class ClickHouseGaugeMaxTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $host = getenv('CLICKHOUSE_HOST') ?: 'clickhouse';
        $username = getenv('CLICKHOUSE_USER') ?: 'default';
        $password = getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse';
        $port = (int) (getenv('CLICKHOUSE_PORT') ?: 8123);
        $secure = (bool) (getenv('CLICKHOUSE_SECURE') ?: false);

        $this->adapter = new ClickHouseAdapter(
            $host,
            $username,
            $password,
            $port,
            $secure,
            namespace: 'utopia_usage_gauge_max',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
        );

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
    }

    /**
     * Insert gauge samples straight into the gauges table.
     *
     * @param  array<int, array{value: int, time: string}>  $rows
     */
    private function insertSamples(string $metric, array $rows): void
    {
        $table = $this->resolveTableName($this->adapter, 'getGaugesTableName');
        $database = $this->databaseName($this->adapter);
        $ref = '`'.$database.'`.`'.$table.'`';

        $tuples = [];
        foreach ($rows as $i => $row) {
            $id = $metric.'-'.$i;
            $value = (int) $row['value'];
            $time = $row['time'];
            $tuples[] = "('{$id}', '{$metric}', {$value}, '{$time}')";
        }

        $sql = "INSERT INTO {$ref} (id, metric, value, time) VALUES ".implode(', ', $tuples);
        $this->queryRaw($this->adapter, $sql);
    }

    public function test_gauge_defaults_to_latest_reading(): void
    {
        $metric = 'rt-concurrent-default-'.uniqid();

        // Peak is 9, but the last sample is 4 — the default argMax path
        // must still return the latest reading.
        $this->insertSamples($metric, [
            ['value' => 2, 'time' => '2026-06-01 00:00:00'],
            ['value' => 9, 'time' => '2026-06-01 00:05:00'],
            ['value' => 4, 'time' => '2026-06-01 00:10:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::groupByInterval('time', '1h'),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $this->assertEquals(4, $results[0]->getValue());
    }

    public function test_gauge_max_returns_highest_sample(): void
    {
        $metric = 'rt-concurrent-max-'.uniqid();

        $this->insertSamples($metric, [
            ['value' => 2, 'time' => '2026-06-01 00:00:00'],
            ['value' => 9, 'time' => '2026-06-01 00:05:00'],
            ['value' => 4, 'time' => '2026-06-01 00:10:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::aggregate('max'),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $this->assertEquals(9, $results[0]->getValue());
    }

    public function test_gauge_max_per_bucket(): void
    {
        $metric = 'rt-concurrent-buckets-'.uniqid();

        // Hour 00 peaks at 9; hour 01 peaks at 6. Max composes upward, so
        // each bucket reports its own highest sample.
        $this->insertSamples($metric, [
            ['value' => 2, 'time' => '2026-06-01 00:00:00'],
            ['value' => 9, 'time' => '2026-06-01 00:05:00'],
            ['value' => 4, 'time' => '2026-06-01 00:10:00'],
            ['value' => 6, 'time' => '2026-06-01 01:05:00'],
            ['value' => 1, 'time' => '2026-06-01 01:10:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 02:00:00'),
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::aggregate('max'),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(2, $results);
        $this->assertEquals(9, $results[0]->getValue());
        $this->assertEquals(6, $results[1]->getValue());
    }

    public function test_gauge_max_flat_aggregate_over_window(): void
    {
        $metric = 'rt-concurrent-flat-'.uniqid();

        // No interval: one row for the whole window — the billing shape.
        $this->insertSamples($metric, [
            ['value' => 2, 'time' => '2026-06-01 00:00:00'],
            ['value' => 9, 'time' => '2026-06-01 00:05:00'],
            ['value' => 6, 'time' => '2026-06-01 01:05:00'],
        ]);

        $results = $this->usage->find('1', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 02:00:00'),
            UsageQuery::aggregate('max'),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $this->assertEquals(9, $results[0]->getValue());
    }

    /**
     * Shared-tables adapter — the multi-tenant shape cloud runs. Built on
     * demand so the single-tenant tests above keep the simpler schema.
     *
     * @return array{0: Usage, 1: ClickHouseAdapter}
     */
    private function sharedTablesUsage(): array
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_gauge_max_shared',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );

        $usage = new Usage($adapter);
        $usage->setup();

        return [$usage, $adapter];
    }

    /**
     * Two tenants, one table, one metric — 7 for tenant-a and 5 for tenant-b.
     */
    private function seedTwoTenants(ClickHouseAdapter $adapter, string $metric): void
    {
        $table = $this->resolveTableName($adapter, 'getGaugesTableName');
        $database = $this->databaseName($adapter);
        $ref = '`'.$database.'`.`'.$table.'`';

        $sql = "INSERT INTO {$ref} (id, metric, value, time, tenant) VALUES "
            ."('{$metric}-a1', '{$metric}', 3, '2026-06-01 00:00:00', 'tenant-a'), "
            ."('{$metric}-a2', '{$metric}', 7, '2026-06-01 00:05:00', 'tenant-a'), "
            ."('{$metric}-b1', '{$metric}', 2, '2026-06-01 00:00:00', 'tenant-b'), "
            ."('{$metric}-b2', '{$metric}', 5, '2026-06-01 00:05:00', 'tenant-b')";
        $this->queryRaw($adapter, $sql);
    }

    public function test_gauge_max_stays_scoped_to_one_tenant(): void
    {
        [$usage, $adapter] = $this->sharedTablesUsage();
        $metric = 'rt-concurrent-scoped-'.uniqid();
        $this->seedTwoTenants($adapter, $metric);

        // tenant-a's own peak — never tenant-b's, never a combined figure.
        $results = $usage->find('tenant-a', [
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::aggregate('max'),
        ], Usage::TYPE_GAUGE);

        $this->assertCount(1, $results);
        $this->assertEquals(7, $results[0]->getValue());
    }

    public function test_find_across_tenants_returns_a_row_per_tenant(): void
    {
        [$usage, $adapter] = $this->sharedTablesUsage();
        $metric = 'rt-concurrent-crosstenant-'.uniqid();
        $this->seedTwoTenants($adapter, $metric);

        // One pass over every tenant — what the aggregation job needs instead
        // of N per-tenant queries. groupBy('tenant') keeps rows attributable.
        $results = $usage->findAcrossTenants([
            Query::equal('metric', [$metric]),
            Query::greaterThanEqual('time', '2026-06-01 00:00:00'),
            Query::lessThanEqual('time', '2026-06-01 01:00:00'),
            UsageQuery::groupBy('tenant'),
            UsageQuery::aggregate('max'),
        ], Usage::TYPE_GAUGE);

        $byTenant = [];
        foreach ($results as $row) {
            $tenant = $row->getTenant();
            $this->assertNotNull($tenant, 'every cross-tenant row must carry its tenant');
            $byTenant[$tenant] = $row->getValue();
        }

        $this->assertCount(2, $byTenant);
        $this->assertEquals(7, $byTenant['tenant-a']);
        $this->assertEquals(5, $byTenant['tenant-b']);
    }

    public function test_find_across_tenants_rejected_without_shared_tables(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('requires shared-tables mode');

        $this->usage->findAcrossTenants([
            Query::equal('metric', ['anything']),
        ], Usage::TYPE_GAUGE);
    }
}
