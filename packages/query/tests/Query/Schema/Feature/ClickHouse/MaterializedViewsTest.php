<?php

namespace Tests\Query\Schema\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Query;
use Utopia\Query\Schema\ClickHouse as Schema;

class MaterializedViewsTest extends TestCase
{
    use AssertsBindingCount;

    public function testCreateMaterializedViewFromRawBody(): void
    {
        $schema = new Schema();

        $body = 'SELECT metric, sum(value) AS value, toStartOfDay(time) AS d FROM `events` GROUP BY metric, d';

        $result = $schema->createMaterializedView(
            'usage_events_daily_mv',
            $body,
            'usage_events_daily',
        );

        $this->assertBindingCount($result);
        $this->assertSame(
            'CREATE MATERIALIZED VIEW IF NOT EXISTS `usage_events_daily_mv` TO `usage_events_daily` AS '
            . 'SELECT metric, sum(value) AS value, toStartOfDay(time) AS d FROM `events` GROUP BY metric, d',
            $result->query,
        );
    }

    public function testCreateMaterializedViewWithoutIfNotExists(): void
    {
        $schema = new Schema();

        $result = $schema->createMaterializedView(
            'daily_mv',
            'SELECT * FROM `events`',
            'daily',
            false,
        );

        $this->assertSame(
            'CREATE MATERIALIZED VIEW `daily_mv` TO `daily` AS SELECT * FROM `events`',
            $result->query,
        );
    }

    public function testCreateMaterializedViewFromBuilder(): void
    {
        $schema = new Schema();

        $builder = (new ClickHouseBuilder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])]);

        $result = $schema->createMaterializedView(
            'active_events_mv',
            $builder,
            'active_events',
        );

        $this->assertSame(
            'CREATE MATERIALIZED VIEW IF NOT EXISTS `active_events_mv` TO `active_events` AS '
            . 'SELECT * FROM `events` WHERE `status` IN (?)',
            $result->query,
        );
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCreateMaterializedViewWithoutTargetTable(): void
    {
        $schema = new Schema();

        $result = $schema->createMaterializedView(
            'snapshot_mv',
            'SELECT * FROM `events`',
        );

        $this->assertSame(
            'CREATE MATERIALIZED VIEW IF NOT EXISTS `snapshot_mv` AS SELECT * FROM `events`',
            $result->query,
        );
    }

    public function testCreateMaterializedViewDropInReplacementForUsageAdapter(): void
    {
        $schema = new Schema();

        $innerSelect = 'metric, sum(value) as value, toStartOfDay(time) as d';
        $innerGroupBy = 'metric, d';
        $outerSelect = 'metric, value, d as time';

        $body = "SELECT {$outerSelect}"
            . ' FROM ('
            . " SELECT {$innerSelect}"
            . ' FROM `usage`.`usage_events`'
            . " GROUP BY {$innerGroupBy}"
            . ' )';

        $result = $schema->createMaterializedView(
            'usage_events_daily_mv',
            $body,
            'usage_events_daily',
        );

        $expected = 'CREATE MATERIALIZED VIEW IF NOT EXISTS `usage_events_daily_mv` TO `usage_events_daily` AS '
            . 'SELECT metric, value, d as time FROM ( SELECT metric, sum(value) as value, toStartOfDay(time) as d FROM `usage`.`usage_events` GROUP BY metric, d )';

        $this->assertSame($expected, $result->query);
    }

    public function testDropMaterializedView(): void
    {
        $schema = new Schema();

        $result = $schema->dropMaterializedView('usage_events_daily_mv');

        $this->assertBindingCount($result);
        $this->assertSame(
            'DROP VIEW IF EXISTS `usage_events_daily_mv`',
            $result->query,
        );
    }

    public function testDropMaterializedViewWithoutIfExists(): void
    {
        $schema = new Schema();

        $result = $schema->dropMaterializedView('daily_mv', false);

        $this->assertSame(
            'DROP VIEW `daily_mv`',
            $result->query,
        );
    }
}
