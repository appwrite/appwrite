<?php

namespace Utopia\Usage\Tests\E2E\Adapter;

use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

/**
 * inspect() is the read-only counterpart of migrate(): it names what
 * migrate() would change and what migrate() leaves unfinished.
 */
class ClickHouseInspectTest extends ClickHouseTestCase
{
    public function testAMigratedSchemaWithDataReportsNothing(): void
    {
        $adapter = $this->freshAdapter('utopia_usage_inspect_clean');
        $usage = new Usage($adapter);
        $usage->migrate();
        $this->seed($usage);

        $this->assertSame([], $usage->inspect());
    }

    public function testReportsEachDifferenceMigrateThenRemoves(): void
    {
        $adapter = $this->freshAdapter('utopia_usage_inspect_drift');
        $database = $this->databaseName($adapter);
        $eventsName = $this->resolveTableName($adapter, 'getEventsTableName');
        $gaugesName = $this->resolveTableName($adapter, 'getGaugesTableName');
        $dailyMvName = $this->resolveTableName($adapter, 'getTableName') . '_events_daily_mv';
        $events = "`{$database}`.`{$eventsName}`";
        $gauges = "`{$database}`.`{$gaugesName}`";

        $usage = new Usage($adapter);
        $usage->migrate();
        $this->seed($usage);

        $this->queryRaw($adapter, "DROP TABLE `{$database}`.`{$dailyMvName}`");
        $this->queryRaw($adapter, "ALTER TABLE {$events} DROP INDEX `index-protocol`");
        $this->queryRaw($adapter, "ALTER TABLE {$events} DROP COLUMN `sdkVersion`");
        $this->queryRaw($adapter, "ALTER TABLE {$events} MODIFY COLUMN `protocol` Nullable(String) SETTINGS mutations_sync = 2");
        $this->queryRaw($adapter, "ALTER TABLE {$gauges} DROP INDEX `index-resourceType`, DROP INDEX `index-resourceId`");
        $this->queryRaw($adapter, "ALTER TABLE {$gauges} ADD INDEX `index-resource` (`resourceType`) TYPE set(0) GRANULARITY 1, ADD INDEX `index-resourceId` (`resourceId`) TYPE set(0) GRANULARITY 1");

        $this->assertSame([
            "{$eventsName} column.drifted protocol",
            "{$eventsName} column.missing sdkVersion",
            "{$eventsName} index.missing index-protocol",
            "{$dailyMvName} table.missing {$dailyMvName}",
            "{$gaugesName} index.drifted index-resourceId",
            "{$gaugesName} index.duplicate index-resource",
            "{$gaugesName} index.missing index-resourceType",
        ], $this->summarize($usage->inspect()));

        $details = [];
        foreach ($usage->inspect() as $finding) {
            $details["{$finding['kind']} {$finding['name']}"] = $finding['detail'];
        }
        $this->assertSame('is Nullable(String), expected LowCardinality(Nullable(String))', $details['column.drifted protocol']);
        $this->assertSame('is (resourceId) TYPE set(0), expected (resourceId) TYPE bloom_filter', $details['index.drifted index-resourceId']);
        $this->assertSame('duplicates index-resourceType', $details['index.duplicate index-resource']);

        $usage->migrate();
        $this->waitForMutations($adapter, [$eventsName, $gaugesName]);

        $this->assertSame([], $usage->inspect());
    }

    public function testReportsAProjectionUntilItIsAddedAndBuiltForExistingParts(): void
    {
        $adapter = $this->freshAdapter('utopia_usage_inspect_projection');
        $database = $this->databaseName($adapter);
        $eventsName = $this->resolveTableName($adapter, 'getEventsTableName');
        $events = "`{$database}`.`{$eventsName}`";

        $usage = new Usage($adapter);
        $usage->migrate();
        $this->seed($usage);
        $partition = new \DateTime('now', new \DateTimeZone('UTC'))->format('Ym');

        $this->queryRaw($adapter, "ALTER TABLE {$events} DROP PROJECTION p_by_path SETTINGS mutations_sync = 2");
        $this->assertSame(["{$eventsName} projection.missing p_by_path"], $this->summarize($usage->inspect()));

        $usage->migrate();
        $findings = $usage->inspect();
        $this->assertSame(["{$eventsName} projection.unmaterialized p_by_path"], $this->summarize($findings));
        $this->assertSame("parts without it, by partition: {$partition} (1)", $findings[0]['detail']);

        $this->seed($usage);
        $this->assertSame(
            "parts without it, by partition: {$partition} (1)",
            $usage->inspect()[0]['detail'] ?? '',
            'A part written after the projection was added already carries it',
        );

        $this->queryRaw($adapter, "ALTER TABLE {$events} MATERIALIZE PROJECTION p_by_path SETTINGS mutations_sync = 2");
        $this->assertSame([], $usage->inspect());
    }

    public function testReportsAMutationThatHasNotFinishedAndWhyItFails(): void
    {
        $adapter = $this->freshAdapter('utopia_usage_inspect_mutation');
        $database = $this->databaseName($adapter);
        $gaugesName = $this->resolveTableName($adapter, 'getGaugesTableName');
        $gauges = "`{$database}`.`{$gaugesName}`";

        $usage = new Usage($adapter);
        $usage->migrate();
        $this->seed($usage);

        $this->queryRaw($adapter, "ALTER TABLE {$gauges} UPDATE value = throwIf(1, 'inspect-test') WHERE 1");
        try {
            $findings = [];
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $findings = $usage->inspect();
                if (($findings[0]['kind'] ?? '') === 'mutation.failing') {
                    break;
                }
                usleep(200_000);
            }

            $this->assertSame(["{$gaugesName} mutation.failing " . ($findings[0]['name'] ?? '')], $this->summarize($findings));
            $this->assertStringContainsString('UPDATE value = throwIf', $findings[0]['detail']);
            $this->assertStringContainsString('1 parts to do', $findings[0]['detail']);
            $this->assertStringContainsString('inspect-test', $findings[0]['detail']);
        } finally {
            $this->queryRaw($adapter, 'KILL MUTATION WHERE database = {database:String} AND table = {table:String} FORMAT Null', ['database' => $database, 'table' => $gaugesName]);
        }

        $this->waitForMutations($adapter, [$gaugesName]);
        $this->assertSame([], $usage->inspect());
    }

    /**
     * One event and one gauge, so each table has a part to inspect.
     */
    private function seed(Usage $usage): void
    {
        $this->assertTrue($usage->addBatch([[
            'tenant' => '1',
            'metric' => 'requests',
            'value' => 1,
            'tags' => ['path' => '/v1/ping', 'resourceType' => 'site'],
        ]], Usage::TYPE_EVENT));
        $this->assertTrue($usage->addBatch([[
            'tenant' => '1',
            'metric' => 'storage',
            'value' => 1,
            'tags' => ['service' => 'storage', 'resourceType' => 'bucket', 'resourceId' => 'b1'],
        ]], Usage::TYPE_GAUGE));
    }

    /**
     * @param list<array{table: string, kind: string, name: string, detail: string}> $findings
     * @return list<string>
     */
    private function summarize(array $findings): array
    {
        $lines = array_map(
            fn (array $finding): string => "{$finding['table']} {$finding['kind']} {$finding['name']}",
            $findings,
        );
        sort($lines);

        return $lines;
    }

    /**
     * @param list<string> $tables
     */
    private function waitForMutations(ClickHouseAdapter $adapter, array $tables): void
    {
        foreach ($tables as $table) {
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $raw = $this->queryRaw(
                    $adapter,
                    'SELECT count() AS total FROM system.mutations WHERE database = {database:String} AND table = {table:String} AND NOT is_done FORMAT JSON',
                    ['database' => $this->databaseName($adapter), 'table' => $table],
                );
                $json = json_decode($raw, true);
                $total = is_array($json) && is_array($json['data'] ?? null) && is_array($json['data'][0] ?? null) ? ($json['data'][0]['total'] ?? 0) : 0;
                if ((int) (is_numeric($total) ? $total : 0) === 0) {
                    continue 2;
                }
                usleep(100_000);
            }
            $this->fail("Mutations on {$table} did not finish");
        }
    }

    private function freshAdapter(string $namespace): ClickHouseAdapter
    {
        $adapter = new ClickHouseAdapter(
            getenv('CLICKHOUSE_HOST') ?: 'clickhouse',
            getenv('CLICKHOUSE_USER') ?: 'default',
            getenv('CLICKHOUSE_PASSWORD') ?: 'clickhouse',
            (int) (getenv('CLICKHOUSE_PORT') ?: 8123),
            (bool) (getenv('CLICKHOUSE_SECURE') ?: false),
            namespace: $namespace,
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );

        $database = $this->databaseName($adapter);
        $base = $this->resolveTableName($adapter, 'getTableName');
        foreach (['_events_daily_mv', '_events_daily', '_events', '_gauges', '_samples'] as $suffix) {
            $this->queryRaw($adapter, "DROP TABLE IF EXISTS `{$database}`.`{$base}{$suffix}` SYNC");
        }

        return $adapter;
    }
}
