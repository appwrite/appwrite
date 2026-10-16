<?php

namespace Utopia\Usage\Tests\E2E\Adapter;

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

    public function testMigrateAddsMissingColumnsWithoutApplyingRetention(): void
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_migrate',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
            retention: 30,
        );
        $database = $this->databaseName($adapter);
        $eventsTable = "`{$database}`.`{$this->resolveTableName($adapter, 'getEventsTableName')}`";
        $dailyTable = "`{$database}`.`{$this->resolveTableName($adapter, 'getEventsDailyTableName')}`";
        $dailyMv = "`{$database}`.`{$this->resolveTableName($adapter, 'getTableName')}_events_daily_mv`";

        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyMv}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyTable}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$eventsTable}");
        $this->queryRaw($adapter, "
            CREATE TABLE {$eventsTable} (
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

        $usage = new Usage($adapter);
        $usage->migrate();

        $eventsDdl = $this->showCreateFor($adapter, $eventsTable);
        foreach ($this->expectedDimAssertions(Metric::EVENT_COLUMNS, 'event') as $expected) {
            $this->assertStringContainsString($expected, $eventsDdl);
        }
        $this->assertStringNotContainsString('TTL', $eventsDdl, 'migrate() must leave an existing table\'s retention as it is');
        $this->assertStringContainsString('TTL toDateTime(time) + toIntervalDay(30)', $this->showCreateFor($adapter, $dailyTable), 'A table migrate() creates must carry the retention');

        $this->assertTrue($usage->addBatch([[
            'tenant' => '1',
            'metric' => 'requests',
            'value' => 1,
            'tags' => ['ordinal' => '1'],
        ]], Usage::TYPE_EVENT));

        $usage->setup();

        $this->assertStringContainsString('TTL toDateTime(time)', $this->showCreateFor($adapter, $eventsTable));
        $this->assertStringContainsString('TTL toDateTime(time)', $this->showCreateFor($adapter, $dailyTable));
    }

    public function testMigrateConvergesColumnsThatPredateTheirTypeAndCodec(): void
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_converge',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $database = $this->databaseName($adapter);
        $table = $this->resolveTableName($adapter, 'getEventsTableName');
        $eventsTable = "`{$database}`.`{$table}`";
        $dailyMv = "`{$database}`.`{$this->resolveTableName($adapter, 'getTableName')}_events_daily_mv`";

        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyMv}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$eventsTable}");

        $usage = new Usage($adapter);
        $usage->migrate();

        $this->queryRaw($adapter, "ALTER TABLE {$eventsTable} DROP INDEX `index-protocol`");
        $this->queryRaw($adapter, "ALTER TABLE {$eventsTable} MODIFY COLUMN `protocol` Nullable(String) SETTINGS mutations_sync = 2");
        $this->queryRaw($adapter, "ALTER TABLE {$eventsTable} ADD INDEX `index-protocol` (`protocol`) TYPE set(0) GRANULARITY 1");
        $this->queryRaw($adapter, "ALTER TABLE {$eventsTable} MODIFY COLUMN `accept` REMOVE CODEC");
        $this->assertSame(['type' => 'Nullable(String)', 'codec' => ''], $this->columnShape($adapter, $table, 'protocol'));
        $this->assertSame('', $this->columnShape($adapter, $table, 'accept')['codec']);
        $this->assertStringContainsString('INDEX `index-accept` accept TYPE bloom_filter', $this->showCreateFor($adapter, $eventsTable));

        $usage->migrate();

        $this->assertSame('LowCardinality(Nullable(String))', $this->columnShape($adapter, $table, 'protocol')['type']);
        $this->assertSame('CODEC(ZSTD(3))', $this->columnShape($adapter, $table, 'accept')['codec']);
        $ddl = $this->showCreateFor($adapter, $eventsTable);
        $this->assertStringContainsString('INDEX `index-protocol` protocol TYPE set(0) GRANULARITY 1', $ddl);
        $this->assertStringContainsString('INDEX `index-accept` accept TYPE bloom_filter GRANULARITY 1', $ddl);

        $mutations = $this->mutationCount($adapter, $table);
        $usage->migrate();
        $this->assertSame($mutations, $this->mutationCount($adapter, $table), 'A converged table must not be altered again');
    }

    public function testMigrateRealignsSkipIndexesWhoseExpressionOrTypeDrifted(): void
    {
        $adapter = $this->migrationAdapter('utopia_usage_schema_index_drift');
        $table = $this->resolveTableName($adapter, 'getGaugesTableName');
        $gaugesTable = "`{$this->databaseName($adapter)}`.`{$table}`";

        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$gaugesTable}");

        $usage = new Usage($adapter);
        $usage->migrate();
        $this->assertTrue($usage->addBatch([[
            'tenant' => '1',
            'metric' => 'storage',
            'value' => 1,
            'tags' => ['service' => 'storage', 'resourceType' => 'bucket', 'resourceId' => 'b1'],
        ]], Usage::TYPE_GAUGE));

        $this->queryRaw($adapter, "ALTER TABLE {$gaugesTable} DROP INDEX `index-resourceType`, DROP INDEX `index-resourceId`");
        $this->queryRaw($adapter, "ALTER TABLE {$gaugesTable} ADD INDEX `index-resourceType` (`service`) TYPE set(0) GRANULARITY 1, ADD INDEX `index-resourceId` (`resourceId`) TYPE set(0) GRANULARITY 1");
        $this->assertSame(['expression' => 'service', 'type' => 'set(0)'], $this->skipIndexes($adapter, $table)['index-resourceType']);
        $this->assertSame(['expression' => 'resourceId', 'type' => 'set(0)'], $this->skipIndexes($adapter, $table)['index-resourceId']);

        $usage->migrate();

        $indexes = $this->skipIndexes($adapter, $table);
        $realigned = $indexes;
        $this->assertSame(['expression' => 'resourceType', 'type' => 'set(0)'], $indexes['index-resourceType'], 'An index whose expression drifted must be recreated on the defined column');
        $this->assertSame(['expression' => 'resourceId', 'type' => 'bloom_filter'], $indexes['index-resourceId'], 'An index whose type drifted must be recreated with the defined type');
        $this->assertSame(['expression' => 'service', 'type' => 'set(0)'], $indexes['index-service'], 'An index that matches its definition must be left as it is');
        $this->assertStringContainsString('MATERIALIZE INDEX `index-resourceType`', $this->mutationCommands($adapter, $table));
        $this->assertStringContainsString('MATERIALIZE INDEX `index-resourceId`', $this->mutationCommands($adapter, $table));
        $this->assertStringNotContainsString('MATERIALIZE INDEX `index-service`', $this->mutationCommands($adapter, $table));

        $mutations = $this->mutationCount($adapter, $table);
        $usage->migrate();
        $this->assertSame($mutations, $this->mutationCount($adapter, $table), 'A realigned table must not be altered again');
        $this->assertSame($realigned, $this->skipIndexes($adapter, $table));
    }

    /**
     * Production tables predate the `resource` -> `resourceType` rename: their
     * index on `resourceType` is still named `index-resource`, which the
     * definition no longer has, so `index-resourceType` duplicates it.
     */
    public function testMigrateDropsAnIndexThatDuplicatesADefinedIndexUnderAnotherName(): void
    {
        $adapter = $this->migrationAdapter('utopia_usage_schema_index_renamed');
        $database = $this->databaseName($adapter);
        $tables = [
            $this->resolveTableName($adapter, 'getGaugesTableName') => 'set(0)',
            $this->resolveTableName($adapter, 'getEventsTableName') => 'bloom_filter',
        ];
        $dailyMv = "`{$database}`.`{$this->resolveTableName($adapter, 'getTableName')}_events_daily_mv`";

        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyMv}");
        foreach ($tables as $table => $type) {
            $this->queryRaw($adapter, "DROP TABLE IF EXISTS `{$database}`.`{$table}`");
        }

        $usage = new Usage($adapter);
        $usage->migrate();

        foreach ($tables as $table => $type) {
            $this->queryRaw($adapter, "ALTER TABLE `{$database}`.`{$table}` DROP INDEX `index-resourceType`");
            $this->queryRaw($adapter, "ALTER TABLE `{$database}`.`{$table}` ADD INDEX `index-resource` (`resourceType`) TYPE {$type} GRANULARITY 1, ADD INDEX `index-custom` (`metric`) TYPE bloom_filter GRANULARITY 1");
            $this->assertArrayNotHasKey('index-resourceType', $this->skipIndexes($adapter, $table));
        }

        $usage->migrate();

        foreach ($tables as $table => $type) {
            $indexes = $this->skipIndexes($adapter, $table);
            $this->assertArrayNotHasKey('index-resource', $indexes, "{$table}: the stale duplicate of index-resourceType must be dropped");
            $this->assertSame(['expression' => 'resourceType', 'type' => $type], $indexes['index-resourceType'], "{$table}: the defined index must replace it");
            $this->assertSame(['expression' => 'metric', 'type' => 'bloom_filter'], $indexes['index-custom'], "{$table}: an index that duplicates nothing must be left as it is");
            $this->assertStringContainsString('MATERIALIZE INDEX `index-resourceType`', $this->mutationCommands($adapter, $table), "{$table}: the replacement must be built for the parts the duplicate covered");

            $mutations = $this->mutationCount($adapter, $table);
            $usage->migrate();
            $this->assertSame($mutations, $this->mutationCount($adapter, $table), "{$table}: a realigned table must not be altered again");
            $this->assertSame($indexes, $this->skipIndexes($adapter, $table));
        }
    }

    public function testMigrateCreatesNewTablesWithTheRetention(): void
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: 'utopia_usage_schema_migrate_retention',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
            retention: 30,
        );
        $database = $this->databaseName($adapter);
        $eventsTable = "`{$database}`.`{$this->resolveTableName($adapter, 'getEventsTableName')}`";
        $dailyTable = "`{$database}`.`{$this->resolveTableName($adapter, 'getEventsDailyTableName')}`";
        $gaugesTable = "`{$database}`.`{$this->resolveTableName($adapter, 'getGaugesTableName')}`";
        $dailyMv = "`{$database}`.`{$this->resolveTableName($adapter, 'getTableName')}_events_daily_mv`";

        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyMv}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$dailyTable}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$eventsTable}");
        $this->queryRaw($adapter, "DROP TABLE IF EXISTS {$gaugesTable}");

        new Usage($adapter)->migrate();

        $this->assertStringContainsString('TTL toDateTime(time) + toIntervalDay(30)', $this->showCreateFor($adapter, $eventsTable));
        $this->assertStringContainsString('TTL toDateTime(time) + toIntervalDay(30)', $this->showCreateFor($adapter, $dailyTable));
        $this->assertStringNotContainsString('TTL', $this->showCreateFor($adapter, $gaugesTable));
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

    /**
     * @return array{type: string, codec: string}
     */
    private function columnShape(ClickHouseAdapter $adapter, string $table, string $column): array
    {
        $raw = $this->queryRaw(
            $adapter,
            'SELECT type, compression_codec AS codec FROM system.columns WHERE database = {database:String} AND table = {table:String} AND name = {column:String} FORMAT JSON',
            ['database' => $this->databaseName($adapter), 'table' => $table, 'column' => $column],
        );
        $row = $this->firstRow($raw);

        return [
            'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
            'codec' => is_string($row['codec'] ?? null) ? $row['codec'] : '',
        ];
    }

    private function migrationAdapter(string $namespace): ClickHouseAdapter
    {
        return new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: $namespace,
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
    }

    /**
     * @return array<string, array{expression: string, type: string}>
     */
    private function skipIndexes(ClickHouseAdapter $adapter, string $table): array
    {
        $raw = $this->queryRaw(
            $adapter,
            'SELECT name, expr, type_full FROM system.data_skipping_indices WHERE database = {database:String} AND table = {table:String} FORMAT JSON',
            ['database' => $this->databaseName($adapter), 'table' => $table],
        );
        $json = json_decode($raw, true);
        $rows = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [];

        $indexes = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['name'] ?? null) && is_string($row['expr'] ?? null) && is_string($row['type_full'] ?? null)) {
                $indexes[$row['name']] = ['expression' => $row['expr'], 'type' => $row['type_full']];
            }
        }
        ksort($indexes);

        return $indexes;
    }

    private function mutationCommands(ClickHouseAdapter $adapter, string $table): string
    {
        $raw = $this->queryRaw(
            $adapter,
            'SELECT command FROM system.mutations WHERE database = {database:String} AND table = {table:String} FORMAT JSON',
            ['database' => $this->databaseName($adapter), 'table' => $table],
        );
        $json = json_decode($raw, true);
        $rows = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [];

        $commands = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['command'] ?? null)) {
                $commands[] = $row['command'];
            }
        }

        return implode(PHP_EOL, $commands);
    }

    private function mutationCount(ClickHouseAdapter $adapter, string $table): int
    {
        $raw = $this->queryRaw(
            $adapter,
            'SELECT count() AS total FROM system.mutations WHERE database = {database:String} AND table = {table:String} FORMAT JSON',
            ['database' => $this->databaseName($adapter), 'table' => $table],
        );
        $total = $this->firstRow($raw)['total'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }

    /**
     * @return array<mixed>
     */
    private function firstRow(string $raw): array
    {
        $json = json_decode($raw, true);
        if (!is_array($json) || !is_array($json['data'] ?? null) || !is_array($json['data'][0] ?? null)) {
            return [];
        }

        return $json['data'][0];
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
