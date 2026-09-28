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
 * The gauge projections must actually be selectable by the optimizer: the
 * old shape carried raw `time` as a GROUP BY key, making the projection one
 * row per sample — a copy of the base table the optimizer never used
 * (verified in production: force_optimize_projection returned
 * PROJECTION_NOT_USED on every grouped gauge read).
 */
class ClickHouseGaugeProjectionTest extends ClickHouseTestCase
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
            namespace: 'utopia_usage_gauge_proj',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);

        // A leftover table from an earlier run may carry the old projection
        // shape under the same name (setup() adds IF NOT EXISTS); drop it so
        // the schema under test is the one this code creates.
        $gauges = $this->resolveTableName($this->adapter, 'getGaugesTableName');
        $database = $this->databaseName($this->adapter);
        $this->queryRaw($this->adapter, "DROP TABLE IF EXISTS `{$database}`.`{$gauges}`");

        $this->usage->setup();
        $this->usage->purge('1');

        // Two samples per series so argMax has something to resolve, plus a
        // second resourceType under the same metric.
        $this->seedGaugeRow('proj.users', 5, 'user', '-3 days');
        $this->seedGaugeRow('proj.users', 9, 'user', '-1 hour');
        $this->seedGaugeRow('proj.users', 2, 'team', '-2 days');
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    private function seedGaugeRow(string $metric, int $value, string $resourceType, string $modifier): void
    {
        $gauges = $this->resolveTableName($this->adapter, 'getGaugesTableName');
        $database = $this->databaseName($this->adapter);
        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $this->queryRaw($this->adapter, sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, resourceType, tenant) VALUES ('%s', '%s', %d, '%s', '%s', '1')",
            $database,
            $gauges,
            $id,
            addslashes($metric),
            $value,
            $time,
            addslashes($resourceType),
        ));
    }

    public function testGroupedLatestValueReadIsServedByAProjection(): void
    {
        $gauges = $this->resolveTableName($this->adapter, 'getGaugesTableName');
        $database = $this->databaseName($this->adapter);

        // force_optimize_projection makes ClickHouse ERROR when no projection
        // matches — success here IS the assertion that the shape matches.
        $forced = $this->queryRaw($this->adapter, sprintf(
            'SELECT `metric`, argMax(`value`, `time`) AS `value`, `resourceType`'
            . " FROM `%s`.`%s` WHERE `tenant` IN ('1') AND `metric` IN ('proj.users')"
            . ' GROUP BY `metric`, `resourceType`'
            . ' SETTINGS optimize_use_projections = 1, force_optimize_projection = 1'
            . ' FORMAT JSON',
            $database,
            $gauges,
        ));

        $decoded = json_decode($forced, true);
        $rows = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $byType = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['resourceType'] ?? null) && is_numeric($row['value'] ?? null)) {
                $byType[$row['resourceType']] = (int) $row['value'];
            }
        }

        $this->assertSame(
            ['user' => 9, 'team' => 2],
            ['user' => $byType['user'] ?? null, 'team' => $byType['team'] ?? null],
            'the projection-served read must return the latest sample per series, same as raw argMax',
        );
    }

    public function testAdapterGroupedReadMatchesProjectionServedValues(): void
    {
        // The adapter's own grouped read (the billing prefetch shape) must
        // agree with the projection-served result.
        $rows = $this->usage->find('1', [
            Query::equal('metric', ['proj.users']),
            UsageQuery::groupBy('resourceType'),
            Query::limit(100),
        ], Usage::TYPE_GAUGE);

        $byType = [];
        foreach ($rows as $row) {
            $resourceType = $row->getAttribute('resourceType');
            if (is_string($resourceType)) {
                $byType[$resourceType] = (int) $row->getValue();
            }
        }

        $this->assertSame(9, $byType['user'] ?? null);
        $this->assertSame(2, $byType['team'] ?? null);
    }
}
