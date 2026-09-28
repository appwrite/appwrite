<?php

namespace Utopia\Tests\Benchmark;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * Base class for ClickHouse benchmarks.
 *
 * Each scenario seeds a synthetic dataset via `INSERT … SELECT FROM
 * numbers(…)`, then runs the target query N times with a fresh query_id and
 * records both wall-clock (PHP-side) and ClickHouse-side stats (`rows_read`,
 * `read_bytes`, `query_duration_ms` from `system.query_log`).
 *
 * Output is a JSON report with p50/p95 per scenario, written to
 * `tests/Benchmark/output/<class>.json`.
 */
abstract class BenchmarkBase extends TestCase
{
    protected Usage $usage;

    protected ClickHouseAdapter $adapter;

    /** @var array<string, array<int, array{wall_ms: float, rows_read: int, read_bytes: int, query_duration_ms: float}>> */
    protected array $results = [];

    /** @var array<string, array<int, string>> Per-scenario routing decisions captured for assertion. */
    protected array $routes = [];

    /** @var array<string, array<int, string>> Per-scenario projection names captured from system.query_log. */
    protected array $projectionsByScenario = [];

    /** @var int Default number of synthetic rows; override via BENCH_ROWS env */
    protected int $defaultRows = 1_000_000;

    protected string $tenant = '1';

    protected string $namespace = 'utopia_usage_bench';

    protected string $metric = 'network.requests';

    /** Number of measured iterations (after one warmup). */
    protected int $iterations = 5;

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
            namespace: $this->namespace,
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );

        $this->usage = new Usage($this->adapter);
        $this->usage->setup();

        $rowsEnv = getenv('BENCH_ROWS');
        if (is_string($rowsEnv) && ctype_digit($rowsEnv)) {
            $this->defaultRows = (int) $rowsEnv;
        }
    }

    protected function tearDown(): void
    {
        $this->writeReport();
    }

    /**
     * Seed `$n` synthetic rows into the events table via
     * `INSERT … SELECT FROM numbers(n)`. Bypasses the library's batching path.
     *
     * @param int $rows Number of rows to insert
     * @param string|null $metric Metric name (defaults to $this->metric)
     */
    protected function seedEventRows(int $rows, ?string $metric = null): void
    {
        $metric ??= $this->metric;

        $reflection = new ReflectionClass($this->adapter);

        $getEvents = $reflection->getMethod('getEventsTableName');
        $getEvents->setAccessible(true);
        $eventsTableRaw = $getEvents->invoke($this->adapter);
        $eventsTable = is_string($eventsTableRaw) ? $eventsTableRaw : '';

        $getDatabase = $reflection->getProperty('database');
        $getDatabase->setAccessible(true);
        $databaseRaw = $getDatabase->getValue($this->adapter);
        $database = is_string($databaseRaw) ? $databaseRaw : '';

        $tableRef = "`{$database}`.`{$eventsTable}`";

        $template = file_get_contents(__DIR__ . '/fixtures/seed.sql');
        if (!is_string($template)) {
            throw new RuntimeException('Unable to read seed.sql fixture');
        }

        $sql = strtr($template, [
            '{TABLE}' => $tableRef,
            '{ROWS}' => (string) $rows,
            '{METRIC}' => addslashes($metric),
            '{TENANT}' => addslashes($this->tenant),
        ]);

        $this->runRawSql($sql);
    }

    /**
     * Seed `$n` synthetic rows into the gauges table.
     */
    protected function seedGaugeRows(int $rows, string $metric = 'storage'): void
    {
        $reflection = new ReflectionClass($this->adapter);

        $getGauges = $reflection->getMethod('getGaugesTableName');
        $getGauges->setAccessible(true);
        $gaugesTableRaw = $getGauges->invoke($this->adapter);
        $gaugesTable = is_string($gaugesTableRaw) ? $gaugesTableRaw : '';

        $getDatabase = $reflection->getProperty('database');
        $getDatabase->setAccessible(true);
        $databaseRaw = $getDatabase->getValue($this->adapter);
        $database = is_string($databaseRaw) ? $databaseRaw : '';

        $tableRef = "`{$database}`.`{$gaugesTable}`";

        $services = ['storage', 'databases', 'functions', 'sites'];
        $resources = ['file', 'database', 'function', 'site'];
        $svcExpr = "['" . implode("','", $services) . "'][1 + (number % " . count($services) . ")]";
        $resExpr = "['" . implode("','", $resources) . "'][1 + (number % " . count($resources) . ")]";

        $sql = "INSERT INTO {$tableRef} (id, metric, value, time, tenant, service, resource) "
            . "SELECT lower(hex(randomString(16))), '" . addslashes($metric) . "', "
            . "number AS value, now() - toIntervalSecond(intDiv(number * 86400 * 30, {$rows})) AS time, "
            . "'" . addslashes($this->tenant) . "' AS tenant, "
            . "{$svcExpr} AS service, {$resExpr} AS resource "
            . "FROM numbers({$rows})";

        $this->runRawSql($sql);
    }

    /**
     * Execute a raw SQL string against ClickHouse (bypassing parameter binding).
     */
    protected function runRawSql(string $sql): void
    {
        $reflection = new ReflectionClass($this->adapter);
        $query = $reflection->getMethod('query');
        $query->setAccessible(true);
        $query->invoke($this->adapter, $sql, []);
    }

    /**
     * Truncate the events / gauges tables and recreate the rollup tables.
     */
    protected function purgeAll(): void
    {
        $this->usage->purge($this->tenant);
    }

    /**
     * Run a benchmark scenario `$iterations + 1` times (1 warmup) and record
     * per-iteration stats into $this->results[$name].
     *
     * @param string $name Scenario name
     * @param callable(string): void $callable Receives a unique query_id; must
     *                                          forward it to the adapter via
     *                                          `setNextQueryId()` before its
     *                                          ClickHouse call.
     */
    protected function runBench(string $name, callable $callable, ?int $iterations = null): void
    {
        $iterations ??= $this->iterations;

        $this->adapter->clearRouteLog();

        $warmupId = $this->generateQueryId($name, -1);
        $callable($warmupId);

        $records = [];
        $projections = [];
        for ($i = 0; $i < $iterations; $i++) {
            $queryId = $this->generateQueryId($name, $i);

            $start = microtime(true);
            $callable($queryId);
            $wallMs = (microtime(true) - $start) * 1000.0;

            $chStats = $this->captureCHStats($queryId);
            $chStats['wall_ms'] = round($wallMs, 3);

            $records[] = $chStats;
            $projections = array_merge($projections, $this->captureProjectionsUsed($queryId));
        }
        $this->projectionsByScenario[$name] = array_values(array_unique($projections));

        $routes = [];
        foreach ($this->adapter->getRouteLog() as $entry) {
            $routes[] = $entry['route'];
        }
        $this->routes[$name] = $routes;
        $this->adapter->clearRouteLog();

        $this->results[$name] = $records;
    }

    /**
     * Query system.query_log for the given query_id and return rows_read,
     * read_bytes, query_duration_ms (best-effort — returns zeros if the log
     * hasn't flushed yet).
     *
     * @return array{wall_ms: float, rows_read: int, read_bytes: int, query_duration_ms: float}
     */
    protected function captureCHStats(string $queryId): array
    {
        $stats = [
            'wall_ms' => 0.0,
            'rows_read' => 0,
            'read_bytes' => 0,
            'query_duration_ms' => 0.0,
        ];

        try {
            $this->runRawSql('SYSTEM FLUSH LOGS');
        } catch (Throwable $e) {
            return $stats;
        }

        $escapedId = addslashes($queryId);
        $sql = "SELECT sum(read_rows) AS rows_read, sum(read_bytes) AS read_bytes, "
            . "max(query_duration_ms) AS query_duration_ms "
            . "FROM system.query_log "
            . "WHERE query_id = '{$escapedId}' AND type >= 2 "
            . "FORMAT JSON";

        try {
            $reflection = new ReflectionClass($this->adapter);
            $query = $reflection->getMethod('query');
            $query->setAccessible(true);
            $raw = $query->invoke($this->adapter, $sql, []);
            $rawString = is_string($raw) ? $raw : '';
            $json = json_decode($rawString, true);
            if (is_array($json) && isset($json['data']) && is_array($json['data']) && isset($json['data'][0]) && is_array($json['data'][0])) {
                $row = $json['data'][0];
                $rowsRead = $row['rows_read'] ?? 0;
                $readBytes = $row['read_bytes'] ?? 0;
                $queryDuration = $row['query_duration_ms'] ?? 0;
                $stats['rows_read'] = is_numeric($rowsRead) ? (int) $rowsRead : 0;
                $stats['read_bytes'] = is_numeric($readBytes) ? (int) $readBytes : 0;
                $stats['query_duration_ms'] = is_numeric($queryDuration) ? (float) $queryDuration : 0.0;
            }
        } catch (Throwable $e) {
        }

        return $stats;
    }

    /**
     * Read the projection names that ClickHouse picked for a given query_id,
     * via `system.query_log.projections`. Empty array means no projection
     * fired (the optimizer chose the base table).
     *
     * @return array<int, string>
     */
    protected function captureProjectionsUsed(string $queryId): array
    {
        try {
            $this->runRawSql('SYSTEM FLUSH LOGS');
        } catch (Throwable $e) {
            return [];
        }

        $escapedId = addslashes($queryId);
        $sql = "SELECT projections FROM system.query_log "
            . "WHERE query_id = '{$escapedId}' AND type = 'QueryFinish' "
            . "ORDER BY event_time DESC LIMIT 1 FORMAT JSON";

        try {
            $reflection = new ReflectionClass($this->adapter);
            $query = $reflection->getMethod('query');
            $query->setAccessible(true);
            $raw = $query->invoke($this->adapter, $sql, []);
            $rawString = is_string($raw) ? $raw : '';
            $json = json_decode($rawString, true);
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
        } catch (Throwable $e) {
            return [];
        }
    }

    protected function assertProjectionFiredAtLeastOnce(string $scenario, string $projectionName): void
    {
        $this->assertArrayHasKey(
            $scenario,
            $this->projectionsByScenario,
            "scenario {$scenario} did not record any projection use"
        );

        $seen = $this->projectionsByScenario[$scenario];
        $match = false;
        foreach ($seen as $p) {
            if ($p === $projectionName || str_ends_with($p, '.' . $projectionName)) {
                $match = true;
                break;
            }
        }
        $this->assertTrue(
            $match,
            "{$scenario} expected projection {$projectionName} to fire; saw: " . implode(',', $seen)
        );
    }

    /**
     * Compute percentile for a sorted numeric series.
     *
     * @param array<int|float> $values
     */
    private function percentile(array $values, float $p): float
    {
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $idx = (int) floor($p * (count($values) - 1));
        return (float) $values[$idx];
    }

    /**
     * Write the per-scenario JSON report under tests/Benchmark/output/.
     */
    protected function writeReport(): void
    {
        if (empty($this->results)) {
            return;
        }

        $summary = [];
        foreach ($this->results as $name => $records) {
            $wall = array_map(static fn (array $r) => $r['wall_ms'], $records);
            $rows = array_map(static fn (array $r) => $r['rows_read'], $records);
            $bytes = array_map(static fn (array $r) => $r['read_bytes'], $records);
            $chDur = array_map(static fn (array $r) => $r['query_duration_ms'], $records);

            $summary[$name] = [
                'iterations' => count($records),
                'rows_dataset' => $this->defaultRows,
                'wall_p50_ms' => $this->percentile($wall, 0.50),
                'wall_p95_ms' => $this->percentile($wall, 0.95),
                'ch_p50_ms' => $this->percentile($chDur, 0.50),
                'ch_p95_ms' => $this->percentile($chDur, 0.95),
                'rows_read_p50' => $this->percentile($rows, 0.50),
                'rows_read_p95' => $this->percentile($rows, 0.95),
                'read_bytes_p50' => $this->percentile($bytes, 0.50),
                'read_bytes_p95' => $this->percentile($bytes, 0.95),
                'samples' => $records,
            ];
        }

        $outDir = __DIR__ . '/output';
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0775, true);
        }

        $short = (new ReflectionClass($this))->getShortName();
        $file = $outDir . '/' . $short . '.json';
        file_put_contents($file, (string) json_encode([
            'class' => static::class,
            'tenant' => $this->tenant,
            'rows_dataset' => $this->defaultRows,
            'generated_at' => date(DATE_ATOM),
            'scenarios' => $summary,
        ], JSON_PRETTY_PRINT));
    }

    protected function generateQueryId(string $scenario, int $iter): string
    {
        return 'bench-' . bin2hex(random_bytes(8)) . '-' . preg_replace('/[^a-z0-9_]+/i', '_', $scenario) . '-' . $iter;
    }
}
