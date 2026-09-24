<?php

namespace Tests\Integration\Builder;

use Tests\Integration\IntegrationTestCase;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Builder\ClickHouse\AsofOperator;
use Utopia\Query\Builder\WindowFrame;
use Utopia\Query\Query;

class ClickHouseIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->connectClickhouse();

        $this->trackClickhouseTable('ch_events');
        $this->trackClickhouseTable('ch_users');

        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_events`');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_users`');

        $this->clickhouseStatement('
            CREATE TABLE `ch_users` (
                `id` UInt32,
                `name` String,
                `email` String,
                `age` UInt32,
                `country` String
            ) ENGINE = ReplacingMergeTree()
            ORDER BY `id`
        ');

        $this->clickhouseStatement('
            CREATE TABLE `ch_events` (
                `id` UInt32,
                `user_id` UInt32,
                `action` String,
                `value` Float64,
                `created_at` DateTime
            ) ENGINE = MergeTree()
            ORDER BY (`id`, `created_at`)
        ');

        $this->clickhouseStatement("
            INSERT INTO `ch_users` (`id`, `name`, `email`, `age`, `country`) VALUES
            (1, 'Alice', 'alice@test.com', 30, 'US'),
            (2, 'Bob', 'bob@test.com', 25, 'UK'),
            (3, 'Charlie', 'charlie@test.com', 35, 'US'),
            (4, 'Diana', 'diana@test.com', 28, 'DE'),
            (5, 'Eve', 'eve@test.com', 22, 'UK')
        ");

        $this->clickhouseStatement("
            INSERT INTO `ch_events` (`id`, `user_id`, `action`, `value`, `created_at`) VALUES
            (1, 1, 'click', 1.5, '2024-01-01 10:00:00'),
            (2, 1, 'purchase', 99.99, '2024-01-02 11:00:00'),
            (3, 2, 'click', 2.0, '2024-01-01 12:00:00'),
            (4, 2, 'click', 3.5, '2024-01-03 09:00:00'),
            (5, 3, 'purchase', 49.99, '2024-01-02 14:00:00'),
            (6, 3, 'view', 0.0, '2024-01-04 08:00:00'),
            (7, 4, 'click', 1.0, '2024-01-05 10:00:00'),
            (8, 5, 'purchase', 199.99, '2024-01-06 16:00:00')
        ");
    }

    public function testSelectWithWhere(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name', 'country'])
            ->filter([Query::equal('country', ['US'])])
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
    }

    public function testSelectWithOrderByAndLimit(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name', 'age'])
            ->sortDesc('age')
            ->limit(3)
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Charlie', $rows[0]['name']);
        $this->assertSame('Alice', $rows[1]['name']);
        $this->assertSame('Diana', $rows[2]['name']);
    }

    public function testSelectWithJoin(): void
    {
        $result = (new Builder())
            ->from('ch_events', 'e')
            ->select(['e.id', 'e.action', 'u.name'])
            ->join('ch_users', 'e.user_id', 'u.id', '=', 'u')
            ->filter([Query::equal('e.action', ['purchase'])])
            ->sortAsc('e.id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
        $this->assertSame('Eve', $rows[2]['name']);
    }

    public function testSelectWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['id', 'action', 'value'])
            ->prewhere([Query::equal('action', ['click'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('click', $row['action']);
        }
    }

    public function testSelectWithFinal(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name'])
            ->final()
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(5, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testInsertSingleRow(): void
    {
        $insert = (new Builder())
            ->into('ch_events')
            ->set([
                'id' => 100,
                'user_id' => 1,
                'action' => 'signup',
                'value' => 0.0,
                'created_at' => '2024-02-01 00:00:00',
            ])
            ->insert();

        $this->executeOnClickhouse($insert);

        $select = (new Builder())
            ->from('ch_events')
            ->select(['id', 'action'])
            ->filter([Query::equal('id', [100])])
            ->build();

        $rows = $this->executeOnClickhouse($select);

        $this->assertCount(1, $rows);
        $this->assertSame('signup', $rows[0]['action']);
    }

    public function testInsertMultipleRows(): void
    {
        $insert = (new Builder())
            ->into('ch_users')
            ->set(['id' => 10, 'name' => 'Frank', 'email' => 'frank@test.com', 'age' => 40, 'country' => 'FR'])
            ->set(['id' => 11, 'name' => 'Grace', 'email' => 'grace@test.com', 'age' => 33, 'country' => 'FR'])
            ->insert();

        $this->executeOnClickhouse($insert);

        $select = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name'])
            ->filter([Query::equal('country', ['FR'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($select);

        $this->assertCount(2, $rows);
        $this->assertSame('Frank', $rows[0]['name']);
        $this->assertSame('Grace', $rows[1]['name']);
    }

    public function testSelectWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['action'])
            ->count('*', 'cnt')
            ->groupBy(['action'])
            ->having([Query::greaterThan('cnt', 1)])
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $actions = array_column($rows, 'action');
        $this->assertContains('click', $actions);
        $this->assertContains('purchase', $actions);
        foreach ($rows as $row) {
            $this->assertGreaterThan(1, (int) $row['cnt']); // @phpstan-ignore cast.int
        }
    }

    public function testSelectWithUnionAll(): void
    {
        $first = (new Builder())
            ->from('ch_users')
            ->select(['name'])
            ->filter([Query::equal('country', ['US'])]);

        $second = (new Builder())
            ->from('ch_users')
            ->select(['name'])
            ->filter([Query::equal('country', ['UK'])]);

        $result = $first->unionAll($second)->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(4, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Bob', $names);
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithCte(): void
    {
        $cteQuery = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name', 'country'])
            ->filter([Query::equal('country', ['US'])]);

        $result = (new Builder())
            ->with('us_users', $cteQuery)
            ->from('us_users')
            ->select(['id', 'name'])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Charlie', $rows[1]['name']);
    }

    public function testSelectWithWindowFunction(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['id', 'action', 'value'])
            ->selectWindow('row_number()', 'rn', ['action'], ['id'])
            ->filter([Query::equal('action', ['click'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(4, $rows);
        $this->assertSame(1, (int) $rows[0]['rn']); // @phpstan-ignore cast.int
        $this->assertSame(2, (int) $rows[1]['rn']); // @phpstan-ignore cast.int
        $this->assertSame(3, (int) $rows[2]['rn']); // @phpstan-ignore cast.int
        $this->assertSame(4, (int) $rows[3]['rn']); // @phpstan-ignore cast.int
    }

    public function testSelectWithDistinct(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['country'])
            ->distinct()
            ->sortAsc('country')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(3, $rows);
        $countries = array_column($rows, 'country');
        $this->assertSame(['DE', 'UK', 'US'], $countries);
    }

    public function testSelectWithSubqueryInWhere(): void
    {
        $subquery = (new Builder())
            ->from('ch_events')
            ->select(['user_id'])
            ->filter([Query::equal('action', ['purchase'])]);

        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name'])
            ->filterWhereIn('id', $subquery)
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(3, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Eve', $names);
    }

    public function testSelectWithSample(): void
    {
        $this->trackClickhouseTable('ch_sample');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_sample`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_sample` (
                `id` UInt32,
                `name` String
            ) ENGINE = MergeTree()
            ORDER BY `id`
            SAMPLE BY `id`
        ');
        $this->clickhouseStatement("INSERT INTO `ch_sample` VALUES (1, 'A'), (2, 'B'), (3, 'C'), (4, 'D'), (5, 'E')");

        $result = (new Builder())
            ->from('ch_sample')
            ->select(['id', 'name'])
            ->sample(0.5)
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertLessThanOrEqual(5, count($rows));
        foreach ($rows as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function testSelectWithSettings(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['id', 'action', 'value'])
            ->sortAsc('id')
            ->settings(['max_threads' => '2'])
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(8, $rows);
        $this->assertSame('click', $rows[0]['action']);
    }

    public function testSelectWithBetween(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name', 'age'])
            ->filter([Query::between('age', 25, 30)])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $ages = array_column($rows, 'age');
        foreach ($ages as $age) {
            $this->assertGreaterThanOrEqual(25, (int) $age); // @phpstan-ignore cast.int
            $this->assertLessThanOrEqual(30, (int) $age); // @phpstan-ignore cast.int
        }
        $this->assertContains('Alice', array_column($rows, 'name'));
    }

    public function testSelectWithStartsWithAndContains(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name', 'email'])
            ->filter([
                Query::startsWith('email', 'a'),
                Query::containsString('name', ['Alice']),
            ])
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testSelectWithCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('age', Operator::LessThan, 30, 'young')
            ->when('age', Operator::LessThan, 35, 'mid')
            ->else('senior')
            ->alias('bucket');

        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name'])
            ->selectCase($case)
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(5, $rows);
        $buckets = array_column($rows, 'bucket');
        $this->assertContains('young', $buckets);
        $this->assertContains('mid', $buckets);
        $this->assertContains('senior', $buckets);
    }

    public function testSelectWithArrayJoin(): void
    {
        $this->trackClickhouseTable('ch_tags');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_tags`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_tags` (
                `id` UInt32,
                `name` String,
                `tags` Array(String)
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_tags` (`id`, `name`, `tags`) VALUES
            (1, 'Post A', ['news', 'sport']),
            (2, 'Post B', ['tech']),
            (3, 'Post C', ['news', 'tech', 'culture'])
        ");

        $result = (new Builder())
            ->from('ch_tags')
            ->select(['id', 'name'])
            ->arrayJoin('tags', 'tag')
            ->filter([Query::equal('tag', ['news'])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(2, $rows);
        $this->assertSame('Post A', $rows[0]['name']);
        $this->assertSame('Post C', $rows[1]['name']);
    }

    public function testSelectWithExistsSubquery(): void
    {
        $subquery = (new Builder())
            ->from('ch_events')
            ->filter([
                Query::equal('action', ['purchase']),
                Query::equal('user_id', [1]),
            ]);

        $result = (new Builder())
            ->from('ch_users')
            ->select(['id', 'name'])
            ->filterExists($subquery)
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // Subquery has rows, so all users are returned.
        $this->assertCount(5, $rows);
    }

    public function testAsofJoin(): void
    {
        $this->trackClickhouseTable('ch_quotes');
        $this->trackClickhouseTable('ch_trades');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_quotes`');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_trades`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_quotes` (
                `symbol` String,
                `ts` DateTime,
                `bid` Float64
            ) ENGINE = MergeTree()
            ORDER BY (`symbol`, `ts`)
        ');
        $this->clickhouseStatement('
            CREATE TABLE `ch_trades` (
                `symbol` String,
                `ts` DateTime,
                `price` Float64
            ) ENGINE = MergeTree()
            ORDER BY (`symbol`, `ts`)
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_quotes` (`symbol`, `ts`, `bid`) VALUES
            ('AAPL', '2026-01-01 10:00:00', 100.0),
            ('AAPL', '2026-01-01 10:00:05', 101.0),
            ('AAPL', '2026-01-01 10:00:10', 102.0)
        ");
        $this->clickhouseStatement("
            INSERT INTO `ch_trades` (`symbol`, `ts`, `price`) VALUES
            ('AAPL', '2026-01-01 10:00:03', 100.5),
            ('AAPL', '2026-01-01 10:00:07', 101.5)
        ");

        $result = (new Builder())
            ->from('ch_trades', 't')
            ->select(['t.symbol', 't.ts', 't.price', 'q.bid'])
            ->asofJoin(
                'ch_quotes',
                ['t.symbol' => 'q.symbol'],
                't.ts',
                AsofOperator::GreaterThanEqual,
                'q.ts',
                'q',
            )
            ->sortAsc('t.ts')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(2, $rows);
        $this->assertSame(100.0, (float) $rows[0]['bid']); // @phpstan-ignore cast.double
        $this->assertSame(101.0, (float) $rows[1]['bid']); // @phpstan-ignore cast.double
    }

    public function testAsofLeftJoin(): void
    {
        $this->trackClickhouseTable('ch_quotes');
        $this->trackClickhouseTable('ch_trades');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_quotes`');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_trades`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_quotes` (
                `symbol` String,
                `ts` DateTime,
                `bid` Nullable(Float64)
            ) ENGINE = MergeTree()
            ORDER BY (`symbol`, `ts`)
        ');
        $this->clickhouseStatement('
            CREATE TABLE `ch_trades` (
                `symbol` String,
                `ts` DateTime,
                `price` Float64
            ) ENGINE = MergeTree()
            ORDER BY (`symbol`, `ts`)
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_quotes` (`symbol`, `ts`, `bid`) VALUES
            ('AAPL', '2026-01-01 10:00:00', 100.0)
        ");
        $this->clickhouseStatement("
            INSERT INTO `ch_trades` (`symbol`, `ts`, `price`) VALUES
            ('AAPL', '2026-01-01 10:00:05', 100.5),
            ('MSFT', '2026-01-01 10:00:05', 200.5)
        ");

        $result = (new Builder())
            ->from('ch_trades', 't')
            ->select(['t.symbol', 't.ts', 't.price', 'q.bid'])
            ->asofLeftJoin(
                'ch_quotes',
                ['t.symbol' => 'q.symbol'],
                't.ts',
                AsofOperator::GreaterThanEqual,
                'q.ts',
                'q',
            )
            ->sortAsc('t.symbol')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(2, $rows);
        $this->assertSame('AAPL', $rows[0]['symbol']);
        $this->assertSame(100.0, (float) $rows[0]['bid']); // @phpstan-ignore cast.double
        $this->assertSame('MSFT', $rows[1]['symbol']);
        $this->assertNull($rows[1]['bid']);
    }

    public function testApproxDistinctCount(): void
    {
        $this->trackClickhouseTable('ch_approx');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_approx`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_approx` (
                `id` UInt64,
                `user_id` UInt64,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');

        $values = [];
        for ($id = 1; $id <= 120; $id++) {
            $userId = (($id - 1) % 10) + 1;
            $value = (float) $id;
            $values[] = '(' . $id . ', ' . $userId . ', ' . $value . ')';
        }
        $this->clickhouseStatement(
            'INSERT INTO `ch_approx` (`id`, `user_id`, `value`) VALUES '
            . \implode(', ', $values)
        );

        $result = (new Builder())
            ->from('ch_approx')
            ->uniq('user_id', 'approx_distinct')
            ->uniqExact('user_id', 'exact_distinct')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $exact = (int) $rows[0]['exact_distinct']; // @phpstan-ignore cast.int
        $approx = (int) $rows[0]['approx_distinct']; // @phpstan-ignore cast.int

        $this->assertSame(10, $exact);
        $this->assertGreaterThanOrEqual((int) \floor(10 * 0.95), $approx);
        $this->assertLessThanOrEqual((int) \ceil(10 * 1.05), $approx);
    }

    public function testApproxQuantile(): void
    {
        $this->trackClickhouseTable('ch_approx');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_approx`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_approx` (
                `id` UInt64,
                `user_id` UInt64,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');

        $values = [];
        for ($id = 1; $id <= 101; $id++) {
            $userId = (($id - 1) % 10) + 1;
            $value = (float) $id;
            $values[] = '(' . $id . ', ' . $userId . ', ' . $value . ')';
        }
        $this->clickhouseStatement(
            'INSERT INTO `ch_approx` (`id`, `user_id`, `value`) VALUES '
            . \implode(', ', $values)
        );

        $result = (new Builder())
            ->from('ch_approx')
            ->quantile(0.5, 'value', 'p50')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $median = (float) $rows[0]['p50']; // @phpstan-ignore cast.double

        // Values are 1..101; true median is 51. Allow +/-10% tolerance.
        $this->assertGreaterThanOrEqual(51.0 * 0.9, $median);
        $this->assertLessThanOrEqual(51.0 * 1.1, $median);
    }

    public function testApproxQuantiles(): void
    {
        $this->trackClickhouseTable('ch_approx');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_approx`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_approx` (
                `id` UInt64,
                `user_id` UInt64,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');

        $values = [];
        for ($id = 1; $id <= 100; $id++) {
            $userId = (($id - 1) % 10) + 1;
            $value = (float) $id;
            $values[] = '(' . $id . ', ' . $userId . ', ' . $value . ')';
        }
        $this->clickhouseStatement(
            'INSERT INTO `ch_approx` (`id`, `user_id`, `value`) VALUES '
            . \implode(', ', $values)
        );

        $result = (new Builder())
            ->from('ch_approx')
            ->quantiles([0.25, 0.5, 0.75], 'value', 'qs')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('qs', $rows[0]);
        $qs = $rows[0]['qs'];
        $this->assertIsArray($qs);
        $this->assertCount(3, $qs);

        $q25 = (float) $qs[0]; // @phpstan-ignore cast.double
        $q50 = (float) $qs[1]; // @phpstan-ignore cast.double
        $q75 = (float) $qs[2]; // @phpstan-ignore cast.double

        $this->assertLessThanOrEqual($q50, $q25);
        $this->assertLessThanOrEqual($q75, $q50);
    }

    public function testWindowFunctionWithRowsFrame(): void
    {
        $this->trackClickhouseTable('ch_window');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_window`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_window` (
                `id` UInt64,
                `user_id` UInt64,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY (`user_id`, `id`)
        ');

        $this->clickhouseStatement("
            INSERT INTO `ch_window` (`id`, `user_id`, `value`) VALUES
            (1, 1, 10.0),
            (2, 1, 20.0),
            (3, 1, 30.0),
            (4, 1, 40.0),
            (5, 1, 50.0),
            (6, 2, 100.0),
            (7, 2, 200.0),
            (8, 2, 300.0)
        ");

        $frame = new WindowFrame('ROWS', '2 PRECEDING', 'CURRENT ROW');

        $result = (new Builder())
            ->from('ch_window')
            ->select(['id', 'user_id', 'value'])
            ->selectWindow('sum(`value`)', 'rolling_sum', ['user_id'], ['id'], null, $frame)
            ->filter([Query::equal('user_id', [1])])
            ->sortAsc('id')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(5, $rows);
        $sums = \array_map(
            static fn (array $row): float => (float) $row['rolling_sum'], // @phpstan-ignore cast.double
            $rows,
        );

        // user_id=1 values are 10,20,30,40,50 ordered by id.
        // Rolling sum (2 preceding + current):
        //   id=1 -> 10
        //   id=2 -> 10+20 = 30
        //   id=3 -> 10+20+30 = 60
        //   id=4 -> 20+30+40 = 90
        //   id=5 -> 30+40+50 = 120
        $this->assertSame(10.0, $sums[0]);
        $this->assertSame(30.0, $sums[1]);
        $this->assertSame(60.0, $sums[2]);
        $this->assertSame(90.0, $sums[3]);
        $this->assertSame(120.0, $sums[4]);
    }

    public function testOrderWithFillFillsGaps(): void
    {
        $this->trackClickhouseTable('ch_fill');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_fill`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_fill` (
                `ts` UInt32,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `ts`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_fill` (`ts`, `value`) VALUES
            (1, 10.0),
            (3, 30.0),
            (5, 50.0)
        ");

        $result = (new Builder())
            ->from('ch_fill')
            ->select(['ts', 'value'])
            ->orderWithFill('ts', 'ASC', 1, 5, 1)
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // WITH FILL fills the gaps at ts=2 and ts=4 -> 5 rows.
        $this->assertCount(5, $rows);
        $timestamps = \array_map(
            static fn (array $row): int => (int) $row['ts'], // @phpstan-ignore cast.int
            $rows,
        );
        $this->assertSame([1, 2, 3, 4, 5], $timestamps);
    }

    public function testLimitByKeepsTopN(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['user_id', 'id', 'action'])
            ->sortAsc('user_id')
            ->sortAsc('id')
            ->limitBy(1, ['user_id'])
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // user_ids are 1,2,3,4,5 — limit 1 per user_id -> 5 rows, one per user.
        $this->assertCount(5, $rows);
        $userIds = \array_map(
            static fn (array $row): int => (int) $row['user_id'], // @phpstan-ignore cast.int
            $rows,
        );
        $this->assertSame([1, 2, 3, 4, 5], $userIds);
    }

    public function testGroupConcat(): void
    {
        $result = (new Builder())
            ->from('ch_users')
            ->select(['country'])
            ->groupConcat('name', ',', 'names')
            ->groupBy(['country'])
            ->sortAsc('country')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(3, $rows);
        $byCountry = [];
        foreach ($rows as $row) {
            $country = $row['country'];
            $names = $row['names'];
            \assert(\is_string($country) && \is_string($names));
            $byCountry[$country] = $names;
        }

        $this->assertSame('Diana', $byCountry['DE']);

        $ukNames = \explode(',', $byCountry['UK']);
        \sort($ukNames);
        $this->assertSame(['Bob', 'Eve'], $ukNames);

        $usNames = \explode(',', $byCountry['US']);
        \sort($usNames);
        $this->assertSame(['Alice', 'Charlie'], $usNames);
    }

    public function testStddev(): void
    {
        $this->trackClickhouseTable('ch_stats');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_stats`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_stats` (
                `id` UInt32,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_stats` (`id`, `value`) VALUES
            (1, 2.0),
            (2, 4.0),
            (3, 4.0),
            (4, 4.0),
            (5, 5.0),
            (6, 5.0),
            (7, 7.0),
            (8, 9.0)
        ");

        $result = (new Builder())
            ->from('ch_stats')
            ->stddev('value', 'sd')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $sd = (float) $rows[0]['sd']; // @phpstan-ignore cast.double

        // Population stddev of [2,4,4,4,5,5,7,9] is 2.0.
        $this->assertGreaterThanOrEqual(1.9, $sd);
        $this->assertLessThanOrEqual(2.1, $sd);
    }

    public function testVariance(): void
    {
        $this->trackClickhouseTable('ch_stats');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_stats`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_stats` (
                `id` UInt32,
                `value` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_stats` (`id`, `value`) VALUES
            (1, 2.0),
            (2, 4.0),
            (3, 4.0),
            (4, 4.0),
            (5, 5.0),
            (6, 5.0),
            (7, 7.0),
            (8, 9.0)
        ");

        $result = (new Builder())
            ->from('ch_stats')
            ->variance('value', 'var')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $var = (float) $rows[0]['var']; // @phpstan-ignore cast.double

        // Population variance of the same set is 4.0.
        $this->assertGreaterThanOrEqual(3.8, $var);
        $this->assertLessThanOrEqual(4.2, $var);
    }

    public function testBitAnd(): void
    {
        $this->trackClickhouseTable('ch_flags');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_flags`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_flags` (
                `id` UInt32,
                `flags` UInt32
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement('
            INSERT INTO `ch_flags` (`id`, `flags`) VALUES
            (1, 7),
            (2, 5),
            (3, 6)
        ');

        $result = (new Builder())
            ->from('ch_flags')
            ->bitAnd('flags', 'and_flags')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // 7 & 5 & 6 = 4
        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['and_flags']); // @phpstan-ignore cast.int
    }

    public function testBitOr(): void
    {
        $this->trackClickhouseTable('ch_flags');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_flags`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_flags` (
                `id` UInt32,
                `flags` UInt32
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement('
            INSERT INTO `ch_flags` (`id`, `flags`) VALUES
            (1, 1),
            (2, 2),
            (3, 4)
        ');

        $result = (new Builder())
            ->from('ch_flags')
            ->bitOr('flags', 'or_flags')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // 1 | 2 | 4 = 7
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['or_flags']); // @phpstan-ignore cast.int
    }

    public function testBitXor(): void
    {
        $this->trackClickhouseTable('ch_flags');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_flags`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_flags` (
                `id` UInt32,
                `flags` UInt32
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement('
            INSERT INTO `ch_flags` (`id`, `flags`) VALUES
            (1, 3),
            (2, 5),
            (3, 6)
        ');

        $result = (new Builder())
            ->from('ch_flags')
            ->bitXor('flags', 'xor_flags')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // 3 ^ 5 ^ 6 = 0
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]['xor_flags']); // @phpstan-ignore cast.int
    }

    public function testHintSetting(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['id', 'action'])
            ->sortAsc('id')
            ->hint('max_threads=2')
            ->build();

        $this->assertStringContainsString('SETTINGS max_threads=2', $result->query);

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(8, $rows);
        $this->assertSame('click', $rows[0]['action']);
    }

    public function testCountIf(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->countWhen('`action` = ?', 'clicks', 'click')
            ->countWhen('`action` = ?', 'purchases', 'purchase')
            ->build();

        $this->assertStringContainsString('countIf(`action` = ?)', $result->query);

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]['clicks']); // @phpstan-ignore cast.int
        $this->assertSame(3, (int) $rows[0]['purchases']); // @phpstan-ignore cast.int
    }

    public function testSumIf(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->sumWhen('value', '`action` = ?', 'purchase_total', 'purchase')
            ->build();

        $this->assertStringContainsString('sumIf(`value`, `action` = ?)', $result->query);

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        // Purchases: 99.99 + 49.99 + 199.99 = 349.97
        $total = (float) $rows[0]['purchase_total']; // @phpstan-ignore cast.double
        $this->assertGreaterThanOrEqual(349.96, $total);
        $this->assertLessThanOrEqual(349.98, $total);
    }

    public function testTableSample(): void
    {
        $this->trackClickhouseTable('ch_sampled');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_sampled`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_sampled` (
                `id` UInt32,
                `name` String
            ) ENGINE = MergeTree()
            ORDER BY `id`
            SAMPLE BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_sampled` (`id`, `name`) VALUES
            (1, 'A'), (2, 'B'), (3, 'C'), (4, 'D'), (5, 'E')
        ");

        $result = (new Builder())
            ->from('ch_sampled')
            ->select(['id', 'name'])
            ->tablesample(50.0)
            ->build();

        $this->assertStringContainsString('SAMPLE 0.5', $result->query);

        $rows = $this->executeOnClickhouse($result);

        $this->assertLessThanOrEqual(5, \count($rows));
        foreach ($rows as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function testGroupByWithRollup(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['user_id', 'action'])
            ->count('*', 'cnt')
            ->groupBy(['user_id', 'action'])
            ->withRollup()
            ->sortAsc('user_id')
            ->sortAsc('action')
            ->build();

        $this->assertStringContainsString('WITH ROLLUP', $result->query);

        $rows = $this->executeOnClickhouse($result);

        // With ROLLUP: leaf rows (user_id, action), subtotals per user_id
        // (action NULL/empty), and a grand total (both NULL/empty).
        // There are 7 distinct (user_id, action) pairs in the seed data,
        // plus 5 user subtotals, plus 1 grand total = 13.
        $this->assertSame(13, \count($rows));
    }

    public function testGroupByWithTotals(): void
    {
        $result = (new Builder())
            ->from('ch_events')
            ->select(['action'])
            ->count('*', 'cnt')
            ->groupBy(['action'])
            ->withTotals()
            ->sortAsc('action')
            ->build();

        $this->assertStringContainsString('WITH TOTALS', $result->query);

        $rows = $this->executeOnClickhouse($result);

        // JSONEachRow emits only data rows for WITH TOTALS (totals are in a
        // separate section), so we see one row per distinct action.
        $actions = \array_column($rows, 'action');
        $this->assertContains('click', $actions);
        $this->assertContains('purchase', $actions);
        $this->assertContains('view', $actions);
    }

    public function testFullOuterJoin(): void
    {
        $this->trackClickhouseTable('ch_left');
        $this->trackClickhouseTable('ch_right');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_left`');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_right`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_left` (
                `id` UInt32,
                `label` String
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement('
            CREATE TABLE `ch_right` (
                `id` UInt32,
                `label` String
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_left` (`id`, `label`) VALUES
            (1, 'L1'),
            (2, 'L2')
        ");
        $this->clickhouseStatement("
            INSERT INTO `ch_right` (`id`, `label`) VALUES
            (2, 'R2'),
            (3, 'R3')
        ");

        $result = (new Builder())
            ->from('ch_left', 'l')
            ->select(['l.label', 'r.label'])
            ->fullOuterJoin('ch_right', 'l.id', 'r.id', '=', 'r')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        // Full outer join: matched (2) + left-only (1) + right-only (3) = 3 rows.
        $this->assertCount(3, $rows);
    }

    public function testTopK(): void
    {
        $this->trackClickhouseTable('ch_topk');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_topk`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_topk` (
                `id` UInt32,
                `category` String
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');

        // Heavy-hitter distribution so topK results are deterministic:
        //   'a' x 50, 'b' x 30, 'c' x 15, 'd' x 5.
        $values = [];
        $id = 1;
        foreach (['a' => 50, 'b' => 30, 'c' => 15, 'd' => 5] as $category => $count) {
            for ($i = 0; $i < $count; $i++) {
                $values[] = '(' . $id . ", '" . $category . "')";
                $id++;
            }
        }
        $this->clickhouseStatement(
            'INSERT INTO `ch_topk` (`id`, `category`) VALUES ' . \implode(', ', $values)
        );

        $result = (new Builder())
            ->from('ch_topk')
            ->topK(3, 'category', 'top')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $top = $rows[0]['top'];
        $this->assertIsArray($top);
        $this->assertCount(3, $top);
        $this->assertSame(['a', 'b', 'c'], $top);
    }

    public function testArgMinArgMax(): void
    {
        $this->trackClickhouseTable('ch_arg');
        $this->clickhouseStatement('DROP TABLE IF EXISTS `ch_arg`');
        $this->clickhouseStatement('
            CREATE TABLE `ch_arg` (
                `id` UInt32,
                `name` String,
                `score` Float64
            ) ENGINE = MergeTree()
            ORDER BY `id`
        ');
        $this->clickhouseStatement("
            INSERT INTO `ch_arg` (`id`, `name`, `score`) VALUES
            (1, 'Alice', 10.0),
            (2, 'Bob', 25.0),
            (3, 'Charlie', 5.0),
            (4, 'Diana', 40.0)
        ");

        $result = (new Builder())
            ->from('ch_arg')
            ->argMin('name', 'score', 'min_name')
            ->argMax('name', 'score', 'max_name')
            ->build();

        $rows = $this->executeOnClickhouse($result);

        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]['min_name']);
        $this->assertSame('Diana', $rows[0]['max_name']);
    }
}
