<?php

namespace Utopia\Tests\Adapter;

use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Metric;
use Utopia\Usage\Usage;

/**
 * Asserts the events / gauges / daily / dim-rollup tables emerge from
 * setup() with the expected codecs, LowCardinality assignments, indexes
 * and UTC pinning.
 */
class ClickHouseSchemaTest extends ClickHouseTestCase
{
    private ClickHouseAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $usage = new Usage($this->adapter);
        $usage->setup();
    }

    public function testEventsTableCarriesCodecsAndLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getEventsTableName'));

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`id` String CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`path` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`hostname` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`osVersion` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`deviceModel` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);

        // premium geo: lower-cardinality dims are LowCardinality, high-cardinality
        // dims fall through to plain Nullable(String).
        $this->assertStringContainsString('`continentCode` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`connectionType` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`city` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`isp` Nullable(String) CODEC(ZSTD(3))', $ddl);

        // sdk: both dims are low-cardinality.
        $this->assertStringContainsString('`sdk` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`sdkVersion` LowCardinality(Nullable(String)) CODEC(ZSTD(3))', $ddl);
    }

    public function testEventProjectionsLeadWithTenantAndKeyOnTheHourlyBucket(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getEventsTableName'));

        // A projection is sorted by its GROUP BY order, so key order is the
        // whole point of this assertion, not just its contents.
        $this->assertStringContainsString(
            "GROUP BY\n            tenant,\n            metric,\n            timeBucket,\n            path",
            $ddl
        );
        $this->assertStringContainsString("toStartOfHour(time, 'UTC') AS timeBucket", $ddl);
    }

    public function testGaugeProjectionsCarryBothTheWindowedAndLatestSlates(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getGaugesTableName'));

        // The windowed slate keeps its original time-keyed shape — windowed
        // grouped reads need the time predicate expressible on the projection.
        $this->assertStringContainsString(
            "GROUP BY\n            metric,\n            time,\n            tenant,\n            service",
            $ddl
        );
        $this->assertStringNotContainsString('timeBucket', $ddl);

        // The fresh measurements the old pin asked for arrived: unwindowed
        // grouped latest-value reads (the billing prefetch) read ~300k rows
        // per query against the time-keyed slate and force_optimize_projection
        // refuses it. The latest slate answers them at one state per series:
        // keyed (tenant, metric, dims), no time key.
        $this->assertStringContainsString(
            "PROJECTION p_latest_by_service\n    (\n        SELECT\n            tenant,\n            metric,\n            service,\n            argMax(value, time) AS value\n        GROUP BY\n            tenant,\n            metric,\n            service",
            $ddl
        );
    }

    public function testEventsTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getEventsTableName'));

        $this->assertStringContainsString('`index-status` status TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-method` method TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-country` country TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-clientType` clientType TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-osName` osName TYPE set(0)', $ddl);

        $this->assertStringContainsString('`index-path` path TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-hostname` hostname TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);

        // request-attribute + premium-geo dims added for firewall/console filtering:
        // bounded-cardinality equality dims get set(0); un-normalized text and
        // high-cardinality dims get bloom_filter.
        $this->assertStringContainsString('`index-protocol` protocol TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-ipReputation` ipReputation TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-timeZone` timeZone TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-weatherCode` weatherCode TYPE set(0)', $ddl);

        $this->assertStringContainsString('`index-accept` accept TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-acceptLanguage` acceptLanguage TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-queryKeys` queryKeys TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-postalCode` postalCode TYPE bloom_filter', $ddl);

        // latitude/longitude are display-only and intentionally NOT indexed.
        $this->assertStringNotContainsString('`index-latitude`', $ddl);
        $this->assertStringNotContainsString('`index-longitude`', $ddl);
    }

    public function testDailyTableMatchesPrePrSchema(): void
    {
        $database = $this->databaseName($this->adapter);
        $dailyTable = $this->resolveTableName($this->adapter, 'getEventsDailyTableName');
        $fullName = "`{$database}`.`{$dailyTable}`";
        $mvName = "`{$database}`.`{$this->namespacedDailyMv()}`";

        $this->queryRaw($this->adapter, "DROP TABLE IF EXISTS {$mvName}");
        $this->queryRaw($this->adapter, "DROP TABLE IF EXISTS {$fullName}");

        $usage = new Usage($this->adapter);
        $usage->setup();

        $ddl = $this->showCreate($dailyTable);

        $this->assertStringContainsString("`time` DateTime64(3, 'UTC')", $ddl);
        $this->assertStringNotContainsString("`time` DateTime64(3, 'UTC') CODEC", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String)', $ddl);
        $this->assertStringNotContainsString('`resourceId` Nullable(String) CODEC', $ddl);
    }

    private function namespacedDailyMv(): string
    {
        return $this->resolveTableName($this->adapter, 'getTableName') . '_events_daily_mv';
    }

    public function testGaugesTableCarriesServiceAndResource(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getGaugesTableName'));

        $this->assertStringContainsString('`service` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString('`resourceType` LowCardinality(Nullable(String))', $ddl);
        $this->assertStringContainsString("`time` DateTime64(3, 'UTC') CODEC(Delta(4), LZ4)", $ddl);
        $this->assertStringContainsString('`resourceId` Nullable(String) CODEC(ZSTD(3))', $ddl);
        $this->assertStringContainsString('`teamId` Nullable(String) CODEC(ZSTD(3))', $ddl);
    }

    public function testGaugesTableSwapsBloomForSetOnLowCardinality(): void
    {
        $ddl = $this->showCreate($this->resolveTableName($this->adapter, 'getGaugesTableName'));

        $this->assertStringContainsString('`index-service` service TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resourceType` resourceType TYPE set(0)', $ddl);
        $this->assertStringContainsString('`index-resourceId` resourceId TYPE bloom_filter', $ddl);
        $this->assertStringContainsString('`index-teamId` teamId TYPE bloom_filter', $ddl);
    }

    public function testSetupBackfillsServiceResourceOnLegacyGaugesTable(): void
    {
        $legacyAdapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_legacy_gauge',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $database = $this->databaseName($legacyAdapter);
        $gaugesTable = $this->resolveTableName($legacyAdapter, 'getGaugesTableName');
        $fullName = "`{$database}`.`{$gaugesTable}`";

        $this->queryRaw($legacyAdapter, "DROP TABLE IF EXISTS {$fullName}");
        $this->queryRaw($legacyAdapter, "
            CREATE TABLE {$fullName} (
                id String,
                metric String,
                value Int64,
                time DateTime64(3),
                tenant Nullable(String)
            )
            ENGINE = MergeTree()
            ORDER BY (tenant, metric, time, id)
            PARTITION BY toYYYYMM(time)
            SETTINGS allow_nullable_key = 1
        ");

        $usage = new Usage($legacyAdapter);
        $usage->setup();

        $ddl = $this->showCreateFor($legacyAdapter, $fullName);

        foreach ($this->expectedDimAssertions(Metric::GAUGE_COLUMNS, 'gauge') as $expected) {
            $this->assertStringContainsString($expected, $ddl);
        }
    }

    public function testSetupBackfillsIpOnLegacyEventsTable(): void
    {
        $legacyAdapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_legacy_event',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $database = $this->databaseName($legacyAdapter);
        $eventsTable = $this->resolveTableName($legacyAdapter, 'getEventsTableName');
        $dailyTable = $this->resolveTableName($legacyAdapter, 'getEventsDailyTableName');
        $dailyMv = $this->resolveTableName($legacyAdapter, 'getTableName') . '_events_daily_mv';
        $fullName = "`{$database}`.`{$eventsTable}`";
        $fullDaily = "`{$database}`.`{$dailyTable}`";
        $fullMv = "`{$database}`.`{$dailyMv}`";

        $this->queryRaw($legacyAdapter, "DROP TABLE IF EXISTS {$fullMv}");
        $this->queryRaw($legacyAdapter, "DROP TABLE IF EXISTS {$fullDaily}");
        $this->queryRaw($legacyAdapter, "DROP TABLE IF EXISTS {$fullName}");
        $this->queryRaw($legacyAdapter, "
            CREATE TABLE {$fullName} (
                id String,
                metric String,
                value Int64,
                time DateTime64(3, 'UTC'),
                tenant Nullable(String)
            )
            ENGINE = MergeTree()
            ORDER BY (tenant, metric, time, id)
            PARTITION BY toYYYYMM(time)
            SETTINGS allow_nullable_key = 1
        ");

        $usage = new Usage($legacyAdapter);
        $usage->setup();

        $ddl = $this->showCreateFor($legacyAdapter, $fullName);

        foreach ($this->expectedDimAssertions(Metric::EVENT_COLUMNS, 'event') as $expected) {
            $this->assertStringContainsString($expected, $ddl);
        }
    }

    public function testRetentionAppliesTtlToEventsAndDailyTables(): void
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_retention',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
            retention: 30,
        );
        $database = $this->databaseName($adapter);
        $eventsTable = $this->resolveTableName($adapter, 'getEventsTableName');
        $dailyTable = $this->resolveTableName($adapter, 'getEventsDailyTableName');
        $gaugesTable = $this->resolveTableName($adapter, 'getGaugesTableName');
        $dailyMv = $this->resolveTableName($adapter, 'getTableName') . '_events_daily_mv';

        // Start clean so the TTL is applied by setup(), not left over.
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS `{$database}`.`{$dailyMv}`");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS `{$database}`.`{$dailyTable}`");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS `{$database}`.`{$eventsTable}`");

        $usage = new Usage($adapter);
        $usage->setup();

        $eventsDdl = $this->showCreateFor($adapter, "`{$database}`.`{$eventsTable}`");
        $this->assertStringContainsString('TTL toDateTime(time)', $eventsDdl);

        // Aggregated daily table shares the same retention window.
        $dailyDdl = $this->showCreateFor($adapter, "`{$database}`.`{$dailyTable}`");
        $this->assertStringContainsString('TTL toDateTime(time)', $dailyDdl);

        // Gauges are point-in-time state and never carry a TTL.
        $gaugesDdl = $this->showCreateFor($adapter, "`{$database}`.`{$gaugesTable}`");
        $this->assertStringNotContainsString('TTL', $gaugesDdl);
    }

    public function testRetentionRejectsNonPositiveDays(): void
    {
        $this->expectException(\Exception::class);

        new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            retention: 0,
        );
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    private function expectedDimAssertions(array $columns, string $type): array
    {
        $lowCardinality = [
            'country', 'region', 'service', 'resourceType',
            'osCode', 'osName', 'osVersion',
            'clientType', 'clientCode', 'clientName', 'clientVersion',
            'clientEngine', 'clientEngineVersion',
            'deviceName', 'deviceBrand', 'deviceModel',
            'hostname', 'ip',
            'protocol', 'ipReputation',
            'continentCode', 'subdivisions', 'connectionType',
            'connectionUsageType', 'autonomousSystemNumber',
            'timeZone', 'weatherCode',
            'sdk', 'sdkVersion',
            'ordinal',
        ];

        $baseKey = ['id', 'metric', 'value', 'time', 'tenant'];

        $expected = [];
        foreach ($columns as $column) {
            if (in_array($column, $baseKey, true)) {
                continue;
            }
            $suffix = in_array($column, $lowCardinality, true)
                ? 'LowCardinality(Nullable(String))'
                : 'Nullable(String)';
            $expected[] = "`{$column}` {$suffix}";
        }
        return $expected;
    }

    private function showCreateFor(ClickHouseAdapter $adapter, string $fullName): string
    {
        $rawString = $this->queryRaw($adapter, "SHOW CREATE TABLE {$fullName} FORMAT JSON");
        $json = json_decode($rawString, true);
        if (is_array($json) && isset($json['data']) && is_array($json['data']) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $statement = $json['data'][0]['statement'] ?? '';
            if (is_string($statement)) {
                return $statement;
            }
        }
        return '';
    }

    private function showCreate(string $table): string
    {
        $database = $this->databaseName($this->adapter);
        $raw = $this->queryRaw($this->adapter, "SHOW CREATE TABLE `{$database}`.`{$table}` FORMAT JSON");
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data']) && is_array($json['data']) && isset($json['data'][0]) && is_array($json['data'][0])) {
            $statement = $json['data'][0]['statement'] ?? '';
            if (is_string($statement)) {
                return $statement;
            }
        }
        return '';
    }
}
