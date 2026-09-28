<?php

namespace Utopia\Tests\Adapter;

use DateTime;
use DateTimeZone;
use Exception;
use Utopia\Query\Query;
use Utopia\Tests\Usage\Adapter\ClickHouseTestCase;
use Utopia\Usage\Adapter\ClickHouse as ClickHouseAdapter;
use Utopia\Usage\Usage;

class ClickHouseBackfillTest extends ClickHouseTestCase
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
            namespace: 'utopia_usage_backfill',
            database: getenv('CLICKHOUSE_DATABASE') ?: 'default',
            sharedTables: true,
        );
        $this->usage = new Usage($this->adapter);
        $this->usage->setup();
        $this->usage->purge('1');

        $this->seedHistoricalRow('backfill.metric', 100, '-5 days');
        $this->seedHistoricalRow('backfill.metric', 200, '-3 days');
        $this->seedHistoricalRow('backfill.other', 40, '-4 days');
    }

    protected function tearDown(): void
    {
        $this->usage->purge('1');
    }

    private function seedHistoricalRow(string $metric, int $value, string $modifier): void
    {
        $eventsTable = $this->resolveTableName($this->adapter, 'getEventsTableName');
        $database = $this->databaseName($this->adapter);

        $time = (new DateTime($modifier, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $id = bin2hex(random_bytes(16));

        $this->queryRaw($this->adapter, sprintf(
            "INSERT INTO `%s`.`%s` (id, metric, value, time, tenant) VALUES ('%s', '%s', %d, '%s', '1')",
            $database,
            $eventsTable,
            $id,
            addslashes($metric),
            $value,
            $time,
        ));
    }

    private function truncateDaily(): void
    {
        $dailyTable = $this->resolveTableName($this->adapter, 'getEventsDailyTableName');
        $database = $this->databaseName($this->adapter);
        $this->queryRaw($this->adapter, sprintf('TRUNCATE TABLE `%s`.`%s`', $database, $dailyTable));
    }

    /**
     * @return array{string, string} day-aligned [from, to) covering the seeds
     */
    private function window(): array
    {
        $from = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $to = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return [$from, $to];
    }

    /**
     * @return array<string, int>
     */
    private function dailyTotals(string $from, string $to): array
    {
        return $this->usage->sumDailyBatch('1', ['backfill.metric', 'backfill.other'], [
            Query::greaterThanEqual('time', $from),
            Query::lessThan('time', $to),
        ]);
    }

    public function testBackfillRestoresTheRollupToRawTotals(): void
    {
        [$from, $to] = $this->window();

        // The MV rolled the seeds up on insert; wipe the rollup so the window
        // is genuinely missing, as it is for any table that predates the MV.
        $this->truncateDaily();
        $this->assertSame(
            ['backfill.metric' => 0, 'backfill.other' => 0],
            $this->dailyTotals($from, $to),
            'the rollup must be empty before the backfill for this test to prove anything',
        );

        $this->usage->backfillDaily($from, $to);

        $this->assertSame(
            ['backfill.metric' => 300, 'backfill.other' => 40],
            $this->dailyTotals($from, $to),
            'a backfilled window must re-aggregate to the exact totals the raw table holds',
        );
    }

    public function testBackfillRefusesAWindowTheRollupAlreadyCovers(): void
    {
        [$from, $to] = $this->window();

        // The MV already rolled the seeds up on insert.
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('double-count');

        $this->usage->backfillDaily($from, $to);
    }

    public function testForceBackfillsOverAnOverlappingWindow(): void
    {
        [$from, $to] = $this->window();

        // With force, the caller owns overlap safety; here the rollup was
        // cleared first, so force writes the same rows the guard path would.
        $this->truncateDaily();
        $this->usage->backfillDaily($from, $to, force: true);

        $this->assertSame(
            ['backfill.metric' => 300, 'backfill.other' => 40],
            $this->dailyTotals($from, $to),
        );
    }

    public function testBackfillRejectsPartialDayBounds(): void
    {
        $from = (new DateTime('-7 days', new DateTimeZone('UTC')))->setTime(12, 0, 0)->format('Y-m-d H:i:s');
        $to = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('UTC midnights');

        $this->usage->backfillDaily($from, $to);
    }

    public function testBackfillRejectsNonUtcMidnightOffsets(): void
    {
        // Midnight in +05:00 is 19:00 UTC — accepting it would backfill a
        // different range than the caller intended.
        $to = (new DateTime('today', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('UTC midnights');

        $this->usage->backfillDaily('2026-07-01 00:00:00+05:00', $to);
    }

    public function testBackfillRejectsAnInvertedWindow(): void
    {
        [$from, $to] = $this->window();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ascending');

        $this->usage->backfillDaily($to, $from);
    }
}
