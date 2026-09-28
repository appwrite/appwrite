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
 * Routing tests for the gauge per-dim projection slate (p_by_service,
 * p_by_resourceType). Each grouped scenario asserts (a) latest-per-group
 * values match the raw scan, and (b) the optimizer picked the matching
 * argMaxState projection per `system.query_log.projections`.
 */
class ClickHouseGaugeDimRoutingTest extends ClickHouseTestCase
{
    private Usage $usage;

    private ClickHouseAdapter $adapter;

    private string $metric = 'gauge.routing.metric';

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_gauge_dim_routing',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow($this->metric, 100, '-5 days', ['service' => 'storage', 'resourceType' => 'file', 'resourceId' => 'f1']);
        $this->seedHistoricalRow($this->metric, 200, '-4 days', ['service' => 'storage', 'resourceType' => 'file', 'resourceId' => 'f2']);
        $this->seedHistoricalRow($this->metric, 50, '-3 days', ['service' => 'databases', 'resourceType' => 'database', 'resourceId' => 'db1']);
        $this->seedHistoricalRow($this->metric, 80, '-3 days', ['service' => 'functions', 'resourceType' => 'function', 'resourceId' => 'fn1']);

        $this->usage->addBatch([
            ['tenant' => '1', 'metric' => $this->metric, 'value' => 999, 'tags' => ['service' => 'storage', 'resourceType' => 'file', 'resourceId' => 'f1']],
        ], Usage::TYPE_GAUGE);
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    /**
     * @param array<string, string> $tags
     */
    private function seedHistoricalRow(string $metric, int $value, string $modifier, array $tags = []): void
    {
        $gaugesTable = $this->resolveTableName($this->adapter, 'getGaugesTableName');
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
        foreach (['service', 'resourceType'] as $tag) {
            if (isset($tags[$tag])) {
                $cols[] = $tag;
                $vals[] = "'" . addslashes($tags[$tag]) . "'";
            }
        }

        $sql = "INSERT INTO `{$database}`.`{$gaugesTable}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $this->queryRaw($this->adapter, $sql);
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: string}>
     */
    public static function topGaugesProjectionProvider(): array
    {
        return [
            'by_service'                 => [['service'], 'p_by_service'],
            'by_resourceType'            => [['resourceType'], 'p_by_resourceType'],
            'by_resourceId'              => [['resourceId'], 'p_by_resourceId'],
            'by_resourceType_resourceId' => [['resourceType', 'resourceId'], 'p_by_resourceType_resourceId'],
        ];
    }

    /**
     * @dataProvider topGaugesProjectionProvider
     * @param array<int, string> $dims
     */
    public function testTopGaugesGroupedQueryRoutesToMatchingProjection(array $dims, string $expectedProjection): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

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
        $this->usage->find('1', $queries, Usage::TYPE_GAUGE);

        $this->assertProjectionUsed($queryId, $expectedProjection);
    }

    public function testGaugesSubDayIntervalStillRoutesToProjection(): void
    {
        // Projections retain raw `time`, so a 1h time-bucketed gauge
        // query still routes through the projection.
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupByInterval('time', '1h'),
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $this->assertProjectionUsed($queryId, 'p_by_service');
    }

    public function testGaugesFilterOnNonProjectionColumnFallsBackToBaseTable(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::equal('resourceId', ['x']),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testGaugesUngroupedFallsBackToBaseTable(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('-2 days'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
        ], Usage::TYPE_GAUGE);

        $this->assertNoProjectionUsed($queryId);
    }

    public function testGaugesWindowStraddlesTodayStillRoutesToProjection(): void
    {
        $start = (new DateTime('-7 days'))->format('Y-m-d H:i:s');
        $end = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $queryId = bin2hex(random_bytes(8));
        $this->adapter->setNextQueryId($queryId);
        $this->usage->find('1', [
            UsageQuery::groupBy('service'),
            Query::equal('metric', [$this->metric]),
            Query::greaterThanEqual('time', $start),
            Query::lessThanEqual('time', $end),
            Query::limit(50),
        ], Usage::TYPE_GAUGE);

        $this->assertProjectionUsed($queryId, 'p_by_service');
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
}
