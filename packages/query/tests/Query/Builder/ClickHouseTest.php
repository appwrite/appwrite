<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\Case\Expression as CaseExpression;
use Utopia\Query\Builder\Case\Operator;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Builder\ClickHouse\AsofOperator;
use Utopia\Query\Builder\Condition;
use Utopia\Query\Builder\Feature\Aggregates;
use Utopia\Query\Builder\Feature\BitwiseAggregates;
use Utopia\Query\Builder\Feature\ClickHouse\ApproximateAggregates;
use Utopia\Query\Builder\Feature\ClickHouse\ArrayJoins;
use Utopia\Query\Builder\Feature\ClickHouse\AsofJoins;
use Utopia\Query\Builder\Feature\ClickHouse\LimitBy;
use Utopia\Query\Builder\Feature\ClickHouse\WithFill;
use Utopia\Query\Builder\Feature\ConditionalAggregates;
use Utopia\Query\Builder\Feature\CTEs;
use Utopia\Query\Builder\Feature\Cube;
use Utopia\Query\Builder\Feature\Deletes;
use Utopia\Query\Builder\Feature\FullOuterJoins;
use Utopia\Query\Builder\Feature\Hints;
use Utopia\Query\Builder\Feature\Hooks;
use Utopia\Query\Builder\Feature\Inserts;
use Utopia\Query\Builder\Feature\Joins;
use Utopia\Query\Builder\Feature\Json;
use Utopia\Query\Builder\Feature\Locking;
use Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch;
use Utopia\Query\Builder\Feature\Rollup;
use Utopia\Query\Builder\Feature\Selects;
use Utopia\Query\Builder\Feature\Spatial;
use Utopia\Query\Builder\Feature\StatisticalAggregates;
use Utopia\Query\Builder\Feature\StringAggregates;
use Utopia\Query\Builder\Feature\TableSampling;
use Utopia\Query\Builder\Feature\Totals;
use Utopia\Query\Builder\Feature\Transactions;
use Utopia\Query\Builder\Feature\Unions;
use Utopia\Query\Builder\Feature\Updates;
use Utopia\Query\Builder\Feature\Upsert;
use Utopia\Query\Builder\Feature\Windows;
use Utopia\Query\Builder\JoinBuilder;
use Utopia\Query\Builder\JoinType;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Compiler;
use Utopia\Query\Exception;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Hook;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Attribute\Map as AttributeMap;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Condition as JoinCondition;
use Utopia\Query\Hook\Join\Filter as JoinFilter;
use Utopia\Query\Hook\Join\Placement;
use Utopia\Query\Query;

class ClickHouseTest extends TestCase
{
    use AssertsBindingCount;
    public function testImplementsCompiler(): void
    {
        $builder = new Builder();
        $this->assertInstanceOf(Compiler::class, $builder);
    }

    public function testImplementsSelects(): void
    {
        $this->assertInstanceOf(Selects::class, new Builder());
    }

    public function testImplementsAggregates(): void
    {
        $this->assertInstanceOf(Aggregates::class, new Builder());
    }

    public function testImplementsJoins(): void
    {
        $this->assertInstanceOf(Joins::class, new Builder());
    }

    public function testImplementsUnions(): void
    {
        $this->assertInstanceOf(Unions::class, new Builder());
    }

    public function testImplementsCTEs(): void
    {
        $this->assertInstanceOf(CTEs::class, new Builder());
    }

    public function testImplementsInserts(): void
    {
        $this->assertInstanceOf(Inserts::class, new Builder());
    }

    public function testImplementsUpdates(): void
    {
        $this->assertInstanceOf(Updates::class, new Builder());
    }

    public function testImplementsDeletes(): void
    {
        $this->assertInstanceOf(Deletes::class, new Builder());
    }

    public function testImplementsHooks(): void
    {
        $this->assertInstanceOf(Hooks::class, new Builder());
    }

    public function testBasicSelect(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['name', 'timestamp'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `timestamp` FROM `events`', $result->query);
    }

    public function testFilterAndSort(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([
                Query::equal('status', ['active']),
                Query::greaterThan('count', 10),
            ])
            ->sortDesc('timestamp')
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` WHERE `status` IN (?) AND `count` > ? ORDER BY `timestamp` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['active', 10, 100], $result->bindings);
    }

    public function testRegexUsesMatchFunction(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('path', '^/api/v[0-9]+')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`path`, ?)', $result->query);
        $this->assertSame(['^/api/v[0-9]+'], $result->bindings);
    }

    public function testSearchThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search is not supported by this dialect.');

        (new Builder())
            ->from('logs')
            ->filter([Query::search('content', 'hello')])
            ->build();
    }

    public function testNotSearchThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search is not supported by this dialect.');

        (new Builder())
            ->from('logs')
            ->filter([Query::notSearch('content', 'hello')])
            ->build();
    }

    public function testRandomOrderUsesLowercaseRand(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY rand()', $result->query);
    }

    public function testFinalKeyword(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL', $result->query);
    }

    public function testFinalWithFilters(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->filter([Query::equal('status', ['active'])])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` FINAL WHERE `status` IN (?) LIMIT ?',
            $result->query
        );
        $this->assertSame(['active', 10], $result->bindings);
    }

    public function testSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1', $result->query);
    }

    public function testSampleWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5', $result->query);
    }

    public function testPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('event_type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `event_type` IN (?)',
            $result->query
        );
        $this->assertSame(['click'], $result->bindings);
    }

    public function testPrewhereWithMultipleConditions(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([
                Query::equal('event_type', ['click']),
                Query::greaterThan('timestamp', '2024-01-01'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `event_type` IN (?) AND `timestamp` > ?',
            $result->query
        );
        $this->assertSame(['click', '2024-01-01'], $result->bindings);
    }

    public function testPrewhereWithWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `event_type` IN (?) WHERE `count` > ?',
            $result->query
        );
        $this->assertSame(['click', 5], $result->bindings);
    }

    public function testPrewhereWithJoinAndWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.user_id', 'users.id')
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('users.age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` JOIN `users` ON `events`.`user_id` = `users`.`id` PREWHERE `event_type` IN (?) WHERE `users`.`age` > ?',
            $result->query
        );
        $this->assertSame(['click', 18], $result->bindings);
    }

    public function testFinalSamplePrewhereWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sortDesc('timestamp')
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `event_type` IN (?) WHERE `count` > ? ORDER BY `timestamp` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['click', 5, 100], $result->bindings);
    }

    public function testAggregation(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'total')
            ->sum('duration', 'total_duration')
            ->groupBy(['event_type'])
            ->having([Query::greaterThan('total', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(*) AS `total`, SUM(`duration`) AS `total_duration` FROM `events` GROUP BY `event_type` HAVING COUNT(*) > ?',
            $result->query
        );
        $this->assertSame([10], $result->bindings);
    }

    public function testJoin(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.user_id', 'users.id')
            ->leftJoin('sessions', 'events.session_id', 'sessions.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` JOIN `users` ON `events`.`user_id` = `users`.`id` LEFT JOIN `sessions` ON `events`.`session_id` = `sessions`.`id`',
            $result->query
        );
    }

    public function testDistinct(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinct()
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `user_id` FROM `events`', $result->query);
    }

    public function testUnion(): void
    {
        $other = (new Builder())->from('events_archive')->filter([Query::equal('year', [2023])]);

        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('year', [2024])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `events` WHERE `year` IN (?)) UNION (SELECT * FROM `events_archive` WHERE `year` IN (?))',
            $result->query
        );
        $this->assertSame([2024, 2023], $result->bindings);
    }

    public function testToRawSql(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->final()
            ->filter([Query::equal('status', ['active'])])
            ->limit(10)
            ->toRawSql();

        $this->assertSame(
            "SELECT * FROM `events` FINAL WHERE `status` IN ('active') LIMIT 10",
            $sql
        );
    }

    public function testResetClearsClickHouseState(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('count', 5)]);

        $builder->build();
        $builder->reset();

        $result = $builder->from('logs')->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testFluentChainingReturnsSameInstance(): void
    {
        $builder = new Builder();

        $this->assertSame($builder, $builder->from('t'));
        $this->assertSame($builder, $builder->final());
        $this->assertSame($builder, $builder->sample(0.1));
        $this->assertSame($builder, $builder->prewhere([]));
        $this->assertSame($builder, $builder->select(['a']));
        $this->assertSame($builder, $builder->filter([]));
        $this->assertSame($builder, $builder->sortAsc('a'));
        $this->assertSame($builder, $builder->limit(1));
        $this->assertSame($builder, $builder->reset());
    }

    public function testAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('events')
            ->addHook(new AttributeMap(['$id' => '_uid']))
            ->filter([Query::equal('$id', ['abc'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` WHERE `_uid` IN (?)',
            $result->query
        );
    }

    public function testConditionProvider(): void
    {
        $hook = new class () implements Filter {
            public function filter(string $table): Condition
            {
                return new Condition('_tenant = ?', ['t1']);
            }
        };

        $result = (new Builder())
            ->from('events')
            ->addHook($hook)
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` WHERE `status` IN (?) AND _tenant = ?',
            $result->query
        );
        $this->assertSame(['active', 't1'], $result->bindings);
    }

    public function testPrewhereBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        // prewhere bindings come before where bindings
        $this->assertSame(['click', 5, 10], $result->bindings);
    }

    public function testCombinedPrewhereWhereJoinGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.user_id', 'users.id')
            ->prewhere([Query::equal('event_type', ['purchase'])])
            ->filter([Query::greaterThan('events.amount', 100)])
            ->count('*', 'total')
            ->select(['users.country'])
            ->groupBy(['users.country'])
            ->having([Query::greaterThan('total', 5)])
            ->sortDesc('total')
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;

        // Verify clause ordering
        $this->assertSame('SELECT COUNT(*) AS `total`, `users`.`country` FROM `events` FINAL SAMPLE 0.1 JOIN `users` ON `events`.`user_id` = `users`.`id` PREWHERE `event_type` IN (?) WHERE `events`.`amount` > ? GROUP BY `users`.`country` HAVING COUNT(*) > ? ORDER BY `total` DESC LIMIT ?', $query);

        // Verify ordering: PREWHERE before WHERE
        $this->assertLessThan(strpos($query, 'WHERE'), strpos($query, 'PREWHERE'));
    }

    public function testPrewhereEmptyArray(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testPrewhereSingleEqual(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `status` IN (?)', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testPrewhereSingleNotEqual(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notEqual('status', 'deleted')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `status` != ?', $result->query);
        $this->assertSame(['deleted'], $result->bindings);
    }

    public function testPrewhereLessThan(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::lessThan('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `age` < ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testPrewhereLessThanEqual(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::lessThanEqual('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `age` <= ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testPrewhereGreaterThan(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::greaterThan('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `score` > ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testPrewhereGreaterThanEqual(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::greaterThanEqual('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `score` >= ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testPrewhereBetween(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testPrewhereNotBetween(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notBetween('age', 0, 17)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `age` NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([0, 17], $result->bindings);
    }

    public function testPrewhereStartsWith(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::startsWith('path', '/api')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE startsWith(`path`, ?)', $result->query);
        $this->assertSame(['/api'], $result->bindings);
    }

    public function testPrewhereNotStartsWith(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notStartsWith('path', '/admin')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE NOT startsWith(`path`, ?)', $result->query);
        $this->assertSame(['/admin'], $result->bindings);
    }

    public function testPrewhereEndsWith(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::endsWith('file', '.csv')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE endsWith(`file`, ?)', $result->query);
        $this->assertSame(['.csv'], $result->bindings);
    }

    public function testPrewhereNotEndsWith(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notEndsWith('file', '.tmp')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE NOT endsWith(`file`, ?)', $result->query);
        $this->assertSame(['.tmp'], $result->bindings);
    }

    public function testPrewhereContainsSingle(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::containsString('name', ['foo'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE position(`name`, ?) > 0', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testPrewhereContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::containsString('name', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (position(`name`, ?) > 0 OR position(`name`, ?) > 0)', $result->query);
        $this->assertSame(['foo', 'bar'], $result->bindings);
    }

    public function testPrewhereContainsAny(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::containsAny('tag', ['a', 'b', 'c'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (position(`tag`, ?) > 0 OR position(`tag`, ?) > 0 OR position(`tag`, ?) > 0)', $result->query);
        $this->assertSame(['a', 'b', 'c'], $result->bindings);
    }

    public function testPrewhereContainsAll(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::containsAll('tag', ['x', 'y'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (position(`tag`, ?) > 0 AND position(`tag`, ?) > 0)', $result->query);
        $this->assertSame(['x', 'y'], $result->bindings);
    }

    public function testPrewhereNotContainsSingle(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notContains('name', ['bad'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE position(`name`, ?) = 0', $result->query);
        $this->assertSame(['bad'], $result->bindings);
    }

    public function testPrewhereNotContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notContains('name', ['bad', 'ugly'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (position(`name`, ?) = 0 AND position(`name`, ?) = 0)', $result->query);
        $this->assertSame(['bad', 'ugly'], $result->bindings);
    }

    public function testPrewhereIsNull(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::isNull('deleted_at')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `deleted_at` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testPrewhereIsNotNull(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::isNotNull('email')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `email` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testPrewhereExists(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::exists(['col_a', 'col_b'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (`col_a` IS NOT NULL AND `col_b` IS NOT NULL)', $result->query);
    }

    public function testPrewhereNotExists(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::notExists(['col_a'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (`col_a` IS NULL)', $result->query);
    }

    public function testPrewhereRegex(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::regex('path', '^/api')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE match(`path`, ?)', $result->query);
        $this->assertSame(['^/api'], $result->bindings);
    }

    public function testPrewhereAndLogical(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::and([
                Query::equal('a', [1]),
                Query::equal('b', [2]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (`a` IN (?) AND `b` IN (?))', $result->query);
        $this->assertSame([1, 2], $result->bindings);
    }

    public function testPrewhereOrLogical(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::or([
                Query::equal('a', [1]),
                Query::equal('b', [2]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE (`a` IN (?) OR `b` IN (?))', $result->query);
        $this->assertSame([1, 2], $result->bindings);
    }

    public function testPrewhereNestedAndOr(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::and([
                Query::or([
                    Query::equal('x', [1]),
                    Query::equal('y', [2]),
                ]),
                Query::greaterThan('z', 0),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE ((`x` IN (?) OR `y` IN (?)) AND `z` > ?)', $result->query);
        $this->assertSame([1, 2, 0], $result->bindings);
    }

    public function testPrewhereRawExpression(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::raw('toDate(created) > ?', ['2024-01-01'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE toDate(created) > ?', $result->query);
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testPrewhereMultipleCallsAdditive(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('a', [1])])
            ->prewhere([Query::equal('b', [2])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `a` IN (?) AND `b` IN (?)', $result->query);
        $this->assertSame([1, 2], $result->bindings);
    }

    public function testPrewhereWithWhereFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` FINAL PREWHERE `type` IN (?) WHERE `count` > ?',
            $result->query
        );
    }

    public function testPrewhereWithWhereSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` SAMPLE 0.5 PREWHERE `type` IN (?) WHERE `count` > ?',
            $result->query
        );
    }

    public function testPrewhereWithWhereFinalSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.3)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` FINAL SAMPLE 0.3 PREWHERE `type` IN (?) WHERE `count` > ?',
            $result->query
        );
        $this->assertSame(['click', 5], $result->bindings);
    }

    public function testPrewhereWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'total')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` PREWHERE `type` IN (?) GROUP BY `type`', $result->query);
    }

    public function testPrewhereWithHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'total')
            ->groupBy(['type'])
            ->having([Query::greaterThan('total', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` PREWHERE `type` IN (?) GROUP BY `type` HAVING COUNT(*) > ?', $result->query);
    }

    public function testPrewhereWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->sortAsc('name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `type` IN (?) ORDER BY `name` ASC',
            $result->query
        );
    }

    public function testPrewhereWithLimitOffset(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `type` IN (?) LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame(['click', 10, 20], $result->bindings);
    }

    public function testPrewhereWithUnion(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` PREWHERE `type` IN (?)) UNION (SELECT * FROM `archive` WHERE `year` IN (?))', $result->query);
    }

    public function testPrewhereWithDistinct(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinct()
            ->select(['user_id'])
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `user_id` FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testPrewhereWithAggregations(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->sum('amount', 'total_amount')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total_amount` FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testPrewhereBindingOrderWithProvider(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant_id = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['click', 5, 't1'], $result->bindings);
    }

    public function testPrewhereBindingOrderWithCursor(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->cursorAfter('abc123')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        // prewhere, where filter, cursor
        $this->assertSame('click', $result->bindings[0]);
        $this->assertSame(5, $result->bindings[1]);
        $this->assertSame('abc123', $result->bindings[2]);
    }

    public function testPrewhereBindingOrderComplex(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->count('*', 'total')
            ->groupBy(['type'])
            ->having([Query::greaterThan('total', 10)])
            ->limit(50)
            ->offset(100)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        // prewhere, filter, provider, cursor, having, limit, offset, union
        $this->assertSame('click', $result->bindings[0]);
        $this->assertSame(5, $result->bindings[1]);
        $this->assertSame('t1', $result->bindings[2]);
        $this->assertSame('cur1', $result->bindings[3]);
    }

    public function testPrewhereWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('events')
            ->addHook(new AttributeMap([
                '$id' => '_uid',
            ]))
            ->prewhere([Query::equal('$id', ['abc'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `_uid` IN (?)', $result->query);
        $this->assertSame(['abc'], $result->bindings);
    }

    public function testPrewhereOnlyNoWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::greaterThan('ts', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `ts` > ?', $result->query);
        // "PREWHERE" contains "WHERE" as a substring, so we check there is no standalone WHERE clause
        $withoutPrewhere = str_replace('PREWHERE', '', $result->query);
        $this->assertStringNotContainsString('WHERE', $withoutPrewhere);
    }

    public function testPrewhereWithEmptyWhereFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['a'])])
            ->filter([])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?)', $result->query);
        $withoutPrewhere = str_replace('PREWHERE', '', $result->query);
        $this->assertStringNotContainsString('WHERE', $withoutPrewhere);
    }

    public function testPrewhereAppearsAfterJoinsBeforeWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $joinPos = strpos($query, 'JOIN');
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');

        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testPrewhereMultipleFiltersInSingleCall(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([
                Query::equal('a', [1]),
                Query::greaterThan('b', 2),
                Query::lessThan('c', 3),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `a` IN (?) AND `b` > ? AND `c` < ?',
            $result->query
        );
        $this->assertSame([1, 2, 3], $result->bindings);
    }

    public function testPrewhereResetClearsPrewhereQueries(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])]);

        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('PREWHERE', $result->query);
    }

    public function testPrewhereInToRawSqlOutput(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->toRawSql();

        $this->assertSame(
            "SELECT * FROM `events` PREWHERE `type` IN ('click') WHERE `count` > 5",
            $sql
        );
    }

    public function testFinalBasicSelect(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->select(['name', 'ts'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `ts` FROM `events` FINAL', $result->query);
    }

    public function testFinalWithJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL JOIN `users` ON `events`.`uid` = `users`.`id`', $result->query);
    }

    public function testFinalWithAggregations(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` FINAL', $result->query);
    }

    public function testFinalWithGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` FINAL GROUP BY `type` HAVING COUNT(*) > ?', $result->query);
    }

    public function testFinalWithDistinct(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->distinct()
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `user_id` FROM `events` FINAL', $result->query);
    }

    public function testFinalWithSort(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sortAsc('name')
            ->sortDesc('ts')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL ORDER BY `name` ASC, `ts` DESC', $result->query);
    }

    public function testFinalWithLimitOffset(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 20], $result->bindings);
    }

    public function testFinalWithCursor(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testFinalWithUnion(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` FINAL) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testFinalWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL PREWHERE `type` IN (?)', $result->query);
    }

    public function testFinalWithSampleAlone(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.25)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.25', $result->query);
    }

    public function testFinalWithPrewhereSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5 PREWHERE `type` IN (?)', $result->query);
    }

    public function testFinalFullPipeline(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->select(['name'])
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->sortDesc('ts')
            ->limit(10)
            ->offset(5)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT `name` FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ? ORDER BY `ts` DESC LIMIT ? OFFSET ?', $query);
    }

    public function testFinalCalledMultipleTimesIdempotent(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->final()
            ->final()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL', $result->query);
        // Ensure FINAL appears only once
        $this->assertSame(1, substr_count($result->query, 'FINAL'));
    }

    public function testFinalInToRawSql(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->final()
            ->filter([Query::equal('status', ['ok'])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `events` FINAL WHERE `status` IN ('ok')", $sql);
    }

    public function testFinalPositionAfterTableBeforeJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $finalPos = strpos($query, 'FINAL');
        $joinPos = strpos($query, 'JOIN');

        $this->assertLessThan($joinPos, $finalPos);
    }

    public function testFinalWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->addHook(new class () implements Attribute {
                public function resolve(string $attribute): string
                {
                    return 'col_' . $attribute;
                }
            })
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE `col_status` IN (?)', $result->query);
    }

    public function testFinalWithConditionProvider(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE deleted = ?', $result->query);
    }

    public function testFinalResetClearsFlag(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final();
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testFinalWithWhenConditional(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->final())
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL', $result->query);

        $result2 = (new Builder())
            ->from('events')
            ->when(false, fn (Builder $b) => $b->final())
            ->build();

        $this->assertStringNotContainsString('FINAL', $result2->query);
    }

    public function testSample10Percent(): void
    {
        $result = (new Builder())->from('events')->sample(0.1)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1', $result->query);
    }

    public function testSample50Percent(): void
    {
        $result = (new Builder())->from('events')->sample(0.5)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5', $result->query);
    }

    public function testSample1Percent(): void
    {
        $result = (new Builder())->from('events')->sample(0.01)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` SAMPLE 0.01', $result->query);
    }

    public function testSample99Percent(): void
    {
        $result = (new Builder())->from('events')->sample(0.99)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` SAMPLE 0.99', $result->query);
    }

    public function testSampleWithFilters(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.2)
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.2 WHERE `status` IN (?)', $result->query);
    }

    public function testSampleWithJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.3)
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.3 JOIN `users` ON `events`.`uid` = `users`.`id`', $result->query);
    }

    public function testSampleWithAggregations(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->count('*', 'cnt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` SAMPLE 0.1', $result->query);
    }

    public function testSampleWithGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->having([Query::greaterThan('cnt', 2)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` SAMPLE 0.5 GROUP BY `type` HAVING COUNT(*) > ?', $result->query);
    }

    public function testSampleWithDistinct(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->distinct()
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `user_id` FROM `events` SAMPLE 0.5', $result->query);
    }

    public function testSampleWithSort(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->sortDesc('ts')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 ORDER BY `ts` DESC', $result->query);
    }

    public function testSampleWithLimitOffset(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 LIMIT ? OFFSET ?', $result->query);
    }

    public function testSampleWithCursor(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->cursorAfter('xyz')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testSampleWithUnion(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` SAMPLE 0.5) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testSampleWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1 PREWHERE `type` IN (?)', $result->query);
    }

    public function testSampleWithFinalKeyword(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1', $result->query);
    }

    public function testSampleWithFinalPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.2)
            ->prewhere([Query::equal('t', ['a'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.2 PREWHERE `t` IN (?)', $result->query);
    }

    public function testSampleFullPipeline(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->select(['name'])
            ->filter([Query::greaterThan('count', 0)])
            ->sortDesc('ts')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT `name` FROM `events` SAMPLE 0.1 WHERE `count` > ? ORDER BY `ts` DESC LIMIT ?', $query);
    }

    public function testSampleInToRawSql(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->filter([Query::equal('x', [1])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `events` SAMPLE 0.1 WHERE `x` IN (1)", $sql);
    }

    public function testSamplePositionAfterFinalBeforeJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $samplePos = strpos($query, 'SAMPLE');
        $joinPos = strpos($query, 'JOIN');
        $finalPos = strpos($query, 'FINAL');

        $this->assertLessThan($samplePos, $finalPos);
        $this->assertLessThan($joinPos, $samplePos);
    }

    public function testSampleResetClearsFraction(): void
    {
        $builder = (new Builder())->from('events')->sample(0.5);
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('SAMPLE', $result->query);
    }

    public function testSampleWithWhenConditional(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->sample(0.5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5', $result->query);

        $result2 = (new Builder())
            ->from('events')
            ->when(false, fn (Builder $b) => $b->sample(0.5))
            ->build();

        $this->assertStringNotContainsString('SAMPLE', $result2->query);
    }

    public function testSampleCalledMultipleTimesLastWins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->sample(0.5)
            ->sample(0.9)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.9', $result->query);
    }

    public function testSampleWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->addHook(new class () implements Attribute {
                public function resolve(string $attribute): string
                {
                    return 'r_' . $attribute;
                }
            })
            ->filter([Query::equal('col', ['v'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 WHERE `r_col` IN (?)', $result->query);
    }

    public function testRegexBasicPattern(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('msg', 'error|warn')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`msg`, ?)', $result->query);
        $this->assertSame(['error|warn'], $result->bindings);
    }

    public function testRegexWithEmptyPattern(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('msg', '')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`msg`, ?)', $result->query);
        $this->assertSame([''], $result->bindings);
    }

    public function testRegexWithSpecialChars(): void
    {
        $pattern = '^/api/v[0-9]+\\.json$';
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('path', $pattern)])
            ->build();
        $this->assertBindingCount($result);

        // Bindings preserve the pattern exactly as provided
        $this->assertSame([$pattern], $result->bindings);
    }

    public function testRegexWithVeryLongPattern(): void
    {
        $longPattern = str_repeat('a', 1000);
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('msg', $longPattern)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`msg`, ?)', $result->query);
        $this->assertSame([$longPattern], $result->bindings);
    }

    public function testRegexCombinedWithOtherFilters(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([
                Query::regex('path', '^/api'),
                Query::equal('status', [200]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `logs` WHERE match(`path`, ?) AND `status` IN (?)',
            $result->query
        );
        $this->assertSame(['^/api', 200], $result->bindings);
    }

    public function testRegexInPrewhere(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->prewhere([Query::regex('path', '^/api')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` PREWHERE match(`path`, ?)', $result->query);
        $this->assertSame(['^/api'], $result->bindings);
    }

    public function testRegexInPrewhereAndWhere(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->prewhere([Query::regex('path', '^/api')])
            ->filter([Query::regex('msg', 'err')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `logs` PREWHERE match(`path`, ?) WHERE match(`msg`, ?)',
            $result->query
        );
        $this->assertSame(['^/api', 'err'], $result->bindings);
    }

    public function testRegexWithAttributeResolver(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->addHook(new class () implements Attribute {
                public function resolve(string $attribute): string
                {
                    return 'col_' . $attribute;
                }
            })
            ->filter([Query::regex('msg', 'test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`col_msg`, ?)', $result->query);
    }

    public function testRegexBindingPreserved(): void
    {
        $pattern = '(foo|bar)\\d+';
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::regex('msg', $pattern)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([$pattern], $result->bindings);
    }

    public function testMultipleRegexFilters(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([
                Query::regex('path', '^/api'),
                Query::regex('msg', 'error'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `logs` WHERE match(`path`, ?) AND match(`msg`, ?)',
            $result->query
        );
    }

    public function testRegexInAndLogical(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::and([
                Query::regex('path', '^/api'),
                Query::greaterThan('status', 399),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `logs` WHERE (match(`path`, ?) AND `status` > ?)',
            $result->query
        );
    }

    public function testRegexInOrLogical(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::or([
                Query::regex('path', '^/api'),
                Query::regex('path', '^/web'),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `logs` WHERE (match(`path`, ?) OR match(`path`, ?))',
            $result->query
        );
    }

    public function testRegexInNestedLogical(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::and([
                Query::or([
                    Query::regex('path', '^/api'),
                    Query::regex('path', '^/web'),
                ]),
                Query::equal('status', [500]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE ((match(`path`, ?) OR match(`path`, ?)) AND `status` IN (?))', $result->query);
    }

    public function testRegexWithFinal(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->final()
            ->filter([Query::regex('path', '^/api')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` FINAL WHERE match(`path`, ?)', $result->query);
    }

    public function testRegexWithSample(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->sample(0.5)
            ->filter([Query::regex('path', '^/api')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` SAMPLE 0.5 WHERE match(`path`, ?)', $result->query);
    }

    public function testRegexInToRawSql(): void
    {
        $sql = (new Builder())
            ->from('logs')
            ->filter([Query::regex('path', '^/api')])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `logs` WHERE match(`path`, '^/api')", $sql);
    }

    public function testRegexCombinedWithContains(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([
                Query::regex('path', '^/api'),
                Query::containsString('msg', ['error']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`path`, ?) AND position(`msg`, ?) > 0', $result->query);
    }

    public function testRegexCombinedWithStartsWith(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([
                Query::regex('path', 'complex.*pattern'),
                Query::startsWith('msg', 'ERR'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE match(`path`, ?) AND startsWith(`msg`, ?)', $result->query);
    }

    public function testRegexPrewhereWithRegexWhere(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->prewhere([Query::regex('path', '^/api')])
            ->filter([Query::regex('msg', 'error')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` PREWHERE match(`path`, ?) WHERE match(`msg`, ?)', $result->query);
        $this->assertSame(['^/api', 'error'], $result->bindings);
    }

    public function testRegexCombinedWithPrewhereContainsRegex(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->prewhere([
                Query::regex('path', '^/api'),
                Query::equal('level', ['error']),
            ])
            ->filter([Query::regex('msg', 'timeout')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['^/api', 'error', 'timeout'], $result->bindings);
    }

    public function testSearchThrowsExceptionMessage(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search is not supported by this dialect.');

        (new Builder())
            ->from('logs')
            ->filter([Query::search('content', 'hello world')])
            ->build();
    }

    public function testNotSearchThrowsExceptionMessage(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search is not supported by this dialect.');

        (new Builder())
            ->from('logs')
            ->filter([Query::notSearch('content', 'hello world')])
            ->build();
    }

    public function testSearchExceptionContainsHelpfulText(): void
    {
        try {
            (new Builder())
                ->from('logs')
                ->filter([Query::search('content', 'test')])
                ->build();
            $this->fail('Expected Exception was not thrown');
        } catch (Exception $e) {
            $this->assertSame('Full-text search is not supported by this dialect.', $e->getMessage());
        }
    }

    public function testSearchInLogicalAndThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->filter([Query::and([
                Query::equal('status', ['active']),
                Query::search('content', 'hello'),
            ])])
            ->build();
    }

    public function testSearchInLogicalOrThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->filter([Query::or([
                Query::equal('status', ['active']),
                Query::search('content', 'hello'),
            ])])
            ->build();
    }

    public function testSearchCombinedWithValidFiltersFailsOnSearch(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->filter([
                Query::equal('status', ['active']),
                Query::search('content', 'hello'),
            ])
            ->build();
    }

    public function testSearchInPrewhereThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->prewhere([Query::search('content', 'hello')])
            ->build();
    }

    public function testNotSearchInPrewhereThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->prewhere([Query::notSearch('content', 'hello')])
            ->build();
    }

    public function testSearchWithFinalStillThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->final()
            ->filter([Query::search('content', 'hello')])
            ->build();
    }

    public function testSearchWithSampleStillThrows(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('logs')
            ->sample(0.5)
            ->filter([Query::search('content', 'hello')])
            ->build();
    }

    public function testRandomSortProducesLowercaseRand(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY rand()', $result->query);
        $this->assertStringNotContainsString('RAND()', $result->query);
    }

    public function testRandomSortCombinedWithAsc(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortAsc('name')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `name` ASC, rand()', $result->query);
    }

    public function testRandomSortCombinedWithDesc(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('ts')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `ts` DESC, rand()', $result->query);
    }

    public function testRandomSortCombinedWithAscAndDesc(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortAsc('name')
            ->sortDesc('ts')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `name` ASC, `ts` DESC, rand()', $result->query);
    }

    public function testRandomSortWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL ORDER BY rand()', $result->query);
    }

    public function testRandomSortWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 ORDER BY rand()', $result->query);
    }

    public function testRandomSortWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` PREWHERE `type` IN (?) ORDER BY rand()',
            $result->query
        );
    }

    public function testRandomSortWithLimit(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortRandom()
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY rand() LIMIT ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testRandomSortWithFiltersAndJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->filter([Query::equal('status', ['active'])])
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` WHERE `status` IN (?) ORDER BY rand()', $result->query);
    }

    public function testRandomSortAlone(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY rand()', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testFilterEqualSingleValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('a', ['x'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?)', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testFilterEqualMultipleValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('a', ['x', 'y', 'z'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` IN (?, ?, ?)', $result->query);
        $this->assertSame(['x', 'y', 'z'], $result->bindings);
    }

    public function testFilterNotEqualSingleValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('a', 'x')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` != ?', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testFilterNotEqualMultipleValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('a', ['x', 'y'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` NOT IN (?, ?)', $result->query);
        $this->assertSame(['x', 'y'], $result->bindings);
    }

    public function testFilterLessThanValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::lessThan('a', 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` < ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testFilterLessThanEqualValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::lessThanEqual('a', 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` <= ?', $result->query);
    }

    public function testFilterGreaterThanValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThan('a', 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` > ?', $result->query);
    }

    public function testFilterGreaterThanEqualValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThanEqual('a', 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` >= ?', $result->query);
    }

    public function testFilterBetweenValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::between('a', 1, 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` BETWEEN ? AND ?', $result->query);
        $this->assertSame([1, 10], $result->bindings);
    }

    public function testFilterNotBetweenValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notBetween('a', 1, 10)])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` NOT BETWEEN ? AND ?', $result->query);
    }

    public function testFilterStartsWithValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::startsWith('a', 'foo')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE startsWith(`a`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testFilterNotStartsWithValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notStartsWith('a', 'foo')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE NOT startsWith(`a`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testFilterEndsWithValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::endsWith('a', 'bar')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE endsWith(`a`, ?)', $result->query);
        $this->assertSame(['bar'], $result->bindings);
    }

    public function testFilterNotEndsWithValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEndsWith('a', 'bar')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE NOT endsWith(`a`, ?)', $result->query);
        $this->assertSame(['bar'], $result->bindings);
    }

    public function testFilterContainsSingleValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::containsString('a', ['foo'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE position(`a`, ?) > 0', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testFilterContainsMultipleValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::containsString('a', ['foo', 'bar'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (position(`a`, ?) > 0 OR position(`a`, ?) > 0)', $result->query);
        $this->assertSame(['foo', 'bar'], $result->bindings);
    }

    public function testFilterContainsAnyValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::containsAny('a', ['x', 'y'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (position(`a`, ?) > 0 OR position(`a`, ?) > 0)', $result->query);
    }

    public function testFilterContainsAllValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::containsAll('a', ['x', 'y'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (position(`a`, ?) > 0 AND position(`a`, ?) > 0)', $result->query);
        $this->assertSame(['x', 'y'], $result->bindings);
    }

    public function testFilterNotContainsSingleValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notContains('a', ['foo'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE position(`a`, ?) = 0', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testFilterNotContainsMultipleValues(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notContains('a', ['foo', 'bar'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (position(`a`, ?) = 0 AND position(`a`, ?) = 0)', $result->query);
    }

    public function testFilterIsNullValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::isNull('a')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testFilterIsNotNullValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::isNotNull('a')])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `a` IS NOT NULL', $result->query);
    }

    public function testFilterExistsValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::exists(['a', 'b'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`a` IS NOT NULL AND `b` IS NOT NULL)', $result->query);
    }

    public function testFilterNotExistsValue(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notExists(['a', 'b'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`a` IS NULL AND `b` IS NULL)', $result->query);
    }

    public function testFilterAndLogical(): void
    {
        $result = (new Builder())->from('t')->filter([
            Query::and([Query::equal('a', [1]), Query::equal('b', [2])]),
        ])->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?) AND `b` IN (?))', $result->query);
    }

    public function testFilterOrLogical(): void
    {
        $result = (new Builder())->from('t')->filter([
            Query::or([Query::equal('a', [1]), Query::equal('b', [2])]),
        ])->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?) OR `b` IN (?))', $result->query);
    }

    public function testFilterRaw(): void
    {
        $result = (new Builder())->from('t')->filter([Query::raw('x > ? AND y < ?', [1, 2])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE x > ? AND y < ?', $result->query);
        $this->assertSame([1, 2], $result->bindings);
    }

    public function testFilterDeeplyNestedLogical(): void
    {
        $result = (new Builder())->from('t')->filter([
            Query::and([
                Query::or([
                    Query::equal('a', [1]),
                    Query::and([
                        Query::greaterThan('b', 2),
                        Query::lessThan('c', 3),
                    ]),
                ]),
                Query::equal('d', [4]),
            ]),
        ])->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ((`a` IN (?) OR (`b` > ? AND `c` < ?)) AND `d` IN (?))', $result->query);
    }

    public function testFilterWithFloats(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThan('price', 9.99)])->build();
        $this->assertBindingCount($result);
        $this->assertSame([9.99], $result->bindings);
    }

    public function testFilterWithNegativeNumbers(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThan('temp', -40)])->build();
        $this->assertBindingCount($result);
        $this->assertSame([-40], $result->bindings);
    }

    public function testFilterWithEmptyStrings(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('name', [''])])->build();
        $this->assertBindingCount($result);
        $this->assertSame([''], $result->bindings);
    }

    public function testAggregationCountWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` FINAL', $result->query);
    }

    public function testAggregationSumWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->sum('amount', 'total_amount')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total_amount` FROM `events` SAMPLE 0.1', $result->query);
    }

    public function testAggregationAvgWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['sale'])])
            ->avg('price', 'avg_price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT AVG(`price`) AS `avg_price` FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testAggregationMinWithPrewhereWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['sale'])])
            ->filter([Query::greaterThan('amount', 0)])
            ->min('price', 'min_price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MIN(`price`) AS `min_price` FROM `events` PREWHERE `type` IN (?) WHERE `amount` > ?', $result->query);
    }

    public function testAggregationMaxWithAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['sale'])])
            ->max('price', 'max_price')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT MAX(`price`) AS `max_price` FROM `events` FINAL SAMPLE 0.5 PREWHERE `type` IN (?)', $result->query);
    }

    public function testMultipleAggregationsWithPrewhereGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['sale'])])
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->groupBy(['region'])
            ->having([Query::greaterThan('cnt', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`amount`) AS `total` FROM `events` PREWHERE `type` IN (?) GROUP BY `region` HAVING COUNT(*) > ?', $result->query);
    }

    public function testAggregationWithJoinFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` FINAL JOIN `users` ON `events`.`uid` = `users`.`id`', $result->query);
    }

    public function testAggregationWithDistinctSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->distinct()
            ->count('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(`user_id`) AS `unique_users` FROM `events` SAMPLE 0.5', $result->query);
    }

    public function testAggregationWithAliasPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'click_count')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `click_count` FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testAggregationWithoutAliasFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->count('*')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) FROM `events` FINAL', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
        $this->assertSame('SELECT COUNT(*) FROM `events` FINAL', $result->query);
    }

    public function testCountStarAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` FINAL SAMPLE 0.5 PREWHERE `type` IN (?)', $result->query);
    }

    public function testAggregationAllFeaturesUnion(): void
    {
        $other = (new Builder())->from('archive')->count('*', 'total');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'total')
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT COUNT(*) AS `total` FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?)) UNION (SELECT COUNT(*) AS `total` FROM `archive`)', $result->query);
    }

    public function testAggregationAttributeResolverPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->addHook(new AttributeMap([
                'amt' => 'amount_cents',
            ]))
            ->prewhere([Query::equal('type', ['sale'])])
            ->sum('amt', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount_cents`) AS `total` FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testAggregationConditionProviderPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['sale'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->count('*', 'cnt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` PREWHERE `type` IN (?) WHERE tenant = ?', $result->query);
    }

    public function testGroupByHavingPrewhereFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['sale'])])
            ->count('*', 'cnt')
            ->groupBy(['region'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` FINAL PREWHERE `type` IN (?) GROUP BY `region` HAVING COUNT(*) > ?', $query);
    }

    public function testJoinWithFinalFeature(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` FINAL JOIN `users` ON `events`.`uid` = `users`.`id`',
            $result->query
        );
    }

    public function testJoinWithSampleFeature(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` SAMPLE 0.5 JOIN `users` ON `events`.`uid` = `users`.`id`',
            $result->query
        );
    }

    public function testJoinWithPrewhereFeature(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)', $result->query);
    }

    public function testJoinWithPrewhereWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('users.age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) WHERE `users`.`age` > ?', $result->query);
    }

    public function testJoinAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('users.age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) WHERE `users`.`age` > ?', $query);
    }

    public function testLeftJoinWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->leftJoin('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LEFT JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)', $result->query);
    }

    public function testRightJoinWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->rightJoin('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` RIGHT JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)', $result->query);
    }

    public function testCrossJoinWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->crossJoin('config')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL CROSS JOIN `config`', $result->query);
    }

    public function testMultipleJoinsWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->leftJoin('sessions', 'events.sid', 'sessions.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` LEFT JOIN `sessions` ON `events`.`sid` = `sessions`.`id` PREWHERE `type` IN (?)', $result->query);
    }

    public function testJoinAggregationPrewhereGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['sale'])])
            ->count('*', 'cnt')
            ->groupBy(['users.country'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) GROUP BY `users`.`country`', $result->query);
    }

    public function testJoinPrewhereBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('users.age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['click', 18], $result->bindings);
    }

    public function testJoinAttributeResolverPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->addHook(new AttributeMap([
                'uid' => 'user_id',
            ]))
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('uid', ['abc'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `user_id` IN (?)', $result->query);
    }

    public function testJoinConditionProviderPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) WHERE tenant = ?', $result->query);
    }

    public function testJoinPrewhereUnion(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testJoinClauseOrdering(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;

        $fromPos = strpos($query, 'FROM');
        $finalPos = strpos($query, 'FINAL');
        $samplePos = strpos($query, 'SAMPLE');
        $joinPos = strpos($query, 'JOIN');
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');

        $this->assertLessThan($finalPos, $fromPos);
        $this->assertLessThan($samplePos, $finalPos);
        $this->assertLessThan($joinPos, $samplePos);
        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testUnionMainHasFinal(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` FINAL) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionMainHasSample(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` SAMPLE 0.5) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionMainHasPrewhere(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` PREWHERE `type` IN (?)) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionMainHasAllClickHouseFeatures(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ?) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionAllWithPrewhere(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->unionAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` PREWHERE `type` IN (?)) UNION ALL (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionBindingOrderWithPrewhere(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::equal('year', [2024])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        // prewhere, where, union
        $this->assertSame(['click', 2024, 2023], $result->bindings);
    }

    public function testMultipleUnionsWithPrewhere(): void
    {
        $other1 = (new Builder())->from('archive1');
        $other2 = (new Builder())->from('archive2');
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other1)
            ->union($other2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` PREWHERE `type` IN (?)) UNION (SELECT * FROM `archive1`) UNION (SELECT * FROM `archive2`)', $result->query);
        $this->assertSame(2, substr_count($result->query, 'UNION'));
    }

    public function testUnionJoinPrewhere(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)) UNION (SELECT * FROM `archive`)', $result->query);
    }

    public function testUnionAggregationPrewhereFinal(): void
    {
        $other = (new Builder())->from('archive')->count('*', 'total');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'total')
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT COUNT(*) AS `total` FROM `events` FINAL PREWHERE `type` IN (?)) UNION (SELECT COUNT(*) AS `total` FROM `archive`)', $result->query);
    }

    public function testUnionWithComplexMainQuery(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->select(['name', 'count'])
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->sortDesc('count')
            ->limit(10)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('(SELECT `name`, `count` FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ? ORDER BY `count` DESC LIMIT ?) UNION (SELECT * FROM `archive` WHERE `year` IN (?))', $query);
    }

    public function testToRawSqlWithFinalFeature(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->final()
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` FINAL', $sql);
    }

    public function testToRawSqlWithSampleFeature(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1', $sql);
    }

    public function testToRawSqlWithPrewhereFeature(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `events` PREWHERE `type` IN ('click')", $sql);
    }

    public function testToRawSqlWithPrewhereWhere(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->toRawSql();

        $this->assertSame(
            "SELECT * FROM `events` PREWHERE `type` IN ('click') WHERE `count` > 5",
            $sql
        );
    }

    public function testToRawSqlWithAllFeatures(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->toRawSql();

        $this->assertSame(
            "SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN ('click') WHERE `count` > 5",
            $sql
        );
    }

    public function testToRawSqlAllFeaturesCombined(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sortDesc('ts')
            ->limit(10)
            ->offset(20)
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (\'click\') WHERE `count` > 5 ORDER BY `ts` DESC LIMIT 10 OFFSET 20', $sql);
    }

    public function testToRawSqlWithStringBindings(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->filter([Query::equal('name', ['hello world'])])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `events` WHERE `name` IN ('hello world')", $sql);
    }

    public function testToRawSqlWithNumericBindings(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->filter([Query::greaterThan('count', 42)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` WHERE `count` > 42', $sql);
    }

    public function testToRawSqlWithBooleanBindings(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->filter([Query::equal('active', [true])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` WHERE `active` IN (1)', $sql);
    }

    public function testToRawSqlWithNullBindings(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->filter([Query::raw('x = ?', [null])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` WHERE x = NULL', $sql);
    }

    public function testToRawSqlWithFloatBindings(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->filter([Query::greaterThan('price', 9.99)])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` WHERE `price` > 9.99', $sql);
    }

    public function testToRawSqlCalledTwiceGivesSameResult(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)]);

        $sql1 = $builder->toRawSql();
        $sql2 = $builder->toRawSql();

        $this->assertSame($sql1, $sql2);
    }

    public function testToRawSqlWithUnionPrewhere(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $sql = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->union($other)
            ->toRawSql();

        $this->assertSame('(SELECT * FROM `events` PREWHERE `type` IN (\'click\')) UNION (SELECT * FROM `archive` WHERE `year` IN (2023))', $sql);
    }

    public function testToRawSqlWithJoinPrewhere(): void
    {
        $sql = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->toRawSql();

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (\'click\')', $sql);
    }

    public function testToRawSqlWithRegexMatch(): void
    {
        $sql = (new Builder())
            ->from('logs')
            ->filter([Query::regex('path', '^/api')])
            ->toRawSql();

        $this->assertSame("SELECT * FROM `logs` WHERE match(`path`, '^/api')", $sql);
    }

    public function testResetClearsPrewhereState(): void
    {
        $builder = (new Builder())->from('events')->prewhere([Query::equal('type', ['click'])]);
        $builder->build();
        $builder->reset();
        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('PREWHERE', $result->query);
    }

    public function testResetClearsFinalState(): void
    {
        $builder = (new Builder())->from('events')->final();
        $builder->build();
        $builder->reset();
        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testResetClearsSampleState(): void
    {
        $builder = (new Builder())->from('events')->sample(0.5);
        $builder->build();
        $builder->reset();
        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('SAMPLE', $result->query);
    }

    public function testResetClearsAllThreeTogether(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])]);
        $builder->build();
        $builder->reset();
        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events`', $result->query);
    }

    public function testResetPreservesAttributeResolver(): void
    {
        $hook = new class () implements Attribute {
            public function resolve(string $attribute): string
            {
                return 'r_' . $attribute;
            }
        };
        $builder = (new Builder())
            ->from('events')
            ->addHook($hook)
            ->final();
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->filter([Query::equal('col', ['v'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` WHERE `r_col` IN (?)', $result->query);
    }

    public function testResetPreservesConditionProviders(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->final();
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` WHERE tenant = ?', $result->query);
    }

    public function testResetClearsTable(): void
    {
        $builder = (new Builder())->from('events');
        $builder->build();
        $builder->reset();

        $result = $builder->from('logs')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `logs`', $result->query);
        $this->assertStringNotContainsString('events', $result->query);
    }

    public function testResetClearsFilters(): void
    {
        $builder = (new Builder())->from('events')->filter([Query::equal('a', [1])]);
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('WHERE', $result->query);
    }

    public function testResetClearsUnions(): void
    {
        $other = (new Builder())->from('archive');
        $builder = (new Builder())->from('events')->union($other);
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('UNION', $result->query);
    }

    public function testResetClearsBindings(): void
    {
        $builder = (new Builder())->from('events')->filter([Query::equal('a', [1])]);
        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testBuildAfterResetMinimalOutput(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sortDesc('ts')
            ->limit(10);
        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testResetRebuildWithPrewhere(): void
    {
        $builder = new Builder();
        $builder->from('events')->final()->build();
        $builder->reset();

        $result = $builder->from('events')->prewhere([Query::equal('x', [1])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` PREWHERE `x` IN (?)', $result->query);
        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testResetRebuildWithFinal(): void
    {
        $builder = new Builder();
        $builder->from('events')->prewhere([Query::equal('x', [1])])->build();
        $builder->reset();

        $result = $builder->from('events')->final()->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` FINAL', $result->query);
        $this->assertStringNotContainsString('PREWHERE', $result->query);
    }

    public function testResetRebuildWithSample(): void
    {
        $builder = new Builder();
        $builder->from('events')->final()->build();
        $builder->reset();

        $result = $builder->from('events')->sample(0.5)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5', $result->query);
        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testMultipleResets(): void
    {
        $builder = new Builder();

        $builder->from('a')->final()->build();
        $builder->reset();
        $builder->from('b')->sample(0.5)->build();
        $builder->reset();
        $builder->from('c')->prewhere([Query::equal('x', [1])])->build();
        $builder->reset();

        $result = $builder->from('d')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `d`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testWhenTrueAddsPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->prewhere([Query::equal('type', ['click'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?)', $result->query);
    }

    public function testWhenFalseDoesNotAddPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(false, fn (Builder $b) => $b->prewhere([Query::equal('type', ['click'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('PREWHERE', $result->query);
    }

    public function testWhenTrueAddsFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->final())
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL', $result->query);
    }

    public function testWhenFalseDoesNotAddFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(false, fn (Builder $b) => $b->final())
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testWhenTrueAddsSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->sample(0.5))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5', $result->query);
    }

    public function testWhenWithBothPrewhereAndFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(
                true,
                fn (Builder $b) => $b
                ->prewhere([Query::equal('type', ['click'])])
                ->filter([Query::greaterThan('count', 5)])
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `count` > ?', $result->query);
    }

    public function testWhenNestedWithClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(
                true,
                fn (Builder $b) => $b
                ->final()
                ->when(true, fn (Builder $b2) => $b2->sample(0.5))
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5', $result->query);
    }

    public function testWhenChainedMultipleTimesWithClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->final())
            ->when(true, fn (Builder $b) => $b->sample(0.5))
            ->when(true, fn (Builder $b) => $b->prewhere([Query::equal('type', ['click'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5 PREWHERE `type` IN (?)', $result->query);
    }

    public function testWhenAddsJoinAndPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(
                true,
                fn (Builder $b) => $b
                ->join('users', 'events.uid', 'users.id')
                ->prewhere([Query::equal('type', ['click'])])
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?)', $result->query);
    }

    public function testWhenCombinedWithRegularWhen(): void
    {
        $result = (new Builder())
            ->from('events')
            ->when(true, fn (Builder $b) => $b->final())
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('status', ['active'])]))
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE `status` IN (?)', $result->query);
    }

    public function testProviderWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE deleted = ?', $result->query);
    }

    public function testProviderWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE deleted = ?', $result->query);
    }

    public function testProviderWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 WHERE deleted = ?', $result->query);
    }

    public function testProviderPrewhereWhereBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        // prewhere, filter, provider
        $this->assertSame(['click', 5, 't1'], $result->bindings);
    }

    public function testMultipleProvidersPrewhereBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('org = ?', ['o1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['click', 't1', 'o1'], $result->bindings);
    }

    public function testProviderPrewhereCursorLimitBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        // prewhere, provider, cursor, limit
        $this->assertSame('click', $result->bindings[0]);
        $this->assertSame('t1', $result->bindings[1]);
        $this->assertSame('cur1', $result->bindings[2]);
        $this->assertSame(10, $result->bindings[3]);
    }

    public function testProviderAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ? AND tenant = ?', $result->query);
    }

    public function testProviderPrewhereAggregation(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->count('*', 'cnt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` PREWHERE `type` IN (?) WHERE tenant = ?', $result->query);
    }

    public function testProviderJoinsPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) WHERE tenant = ?', $result->query);
    }

    public function testProviderReferencesTableNameFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition($table . '.deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE events.deleted = ?', $result->query);
    }

    public function testCursorAfterWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testCursorBeforeWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->cursorBefore('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `_cursor` < ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testCursorPrewhereWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `count` > ? AND `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testCursorWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testCursorWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testCursorPrewhereBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('click', $result->bindings[0]);
        $this->assertSame('cur1', $result->bindings[1]);
    }

    public function testCursorPrewhereProviderBindingOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('click', $result->bindings[0]);
        $this->assertSame('t1', $result->bindings[1]);
        $this->assertSame('cur1', $result->bindings[2]);
    }

    public function testCursorFullClickHousePipeline(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ? AND `_cursor` > ? ORDER BY `_cursor` ASC LIMIT ?', $query);
    }

    public function testPageWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->page(2, 25)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) LIMIT ? OFFSET ?', $result->query);
        $this->assertSame(['click', 25, 25], $result->bindings);
    }

    public function testPageWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->page(3, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([10, 20], $result->bindings);
    }

    public function testPageWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->page(1, 50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([50, 0], $result->bindings);
    }

    public function testPageWithAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->page(2, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) LIMIT ? OFFSET ?', $result->query);
    }

    public function testPageWithComplexClickHouseQuery(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->sortDesc('ts')
            ->page(5, 20)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `count` > ? ORDER BY `ts` DESC LIMIT ? OFFSET ?', $query);
    }

    public function testAllClickHouseMethodsReturnSameInstance(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->final());
        $this->assertSame($builder, $builder->sample(0.5));
        $this->assertSame($builder, $builder->prewhere([]));
        $this->assertSame($builder, $builder->reset());
    }

    public function testChainingClickHouseMethodsWithBaseMethods(): void
    {
        $builder = new Builder();
        $result = $builder
            ->from('events')
            ->final()
            ->sample(0.1)
            ->select(['name'])
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->sortDesc('ts')
            ->limit(10)
            ->offset(20)
            ->build();
        $this->assertBindingCount($result);

        $this->assertNotEmpty($result->query);
    }

    public function testChainingOrderDoesNotMatterForOutput(): void
    {
        $result1 = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();

        $result2 = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->sample(0.1)
            ->filter([Query::greaterThan('count', 5)])
            ->final()
            ->build();

        $this->assertSame($result1->query, $result2->query);
    }

    public function testSameComplexQueryDifferentOrders(): void
    {
        $result1 = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sortDesc('ts')
            ->limit(10)
            ->build();

        $result2 = (new Builder())
            ->from('events')
            ->sortDesc('ts')
            ->limit(10)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sample(0.1)
            ->final()
            ->build();

        $this->assertSame($result1->query, $result2->query);
    }

    public function testFluentResetThenRebuild(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1);
        $builder->build();

        $result = $builder->reset()
            ->from('logs')
            ->sample(0.5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` SAMPLE 0.5', $result->query);
        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testClauseOrderSelectFromFinalSampleJoinPrewhereWhereGroupByHavingOrderByLimitOffset(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->count('*', 'cnt')
            ->select(['users.name'])
            ->groupBy(['users.name'])
            ->having([Query::greaterThan('cnt', 5)])
            ->sortDesc('cnt')
            ->limit(50)
            ->offset(10)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;

        $selectPos = strpos($query, 'SELECT');
        $fromPos = strpos($query, 'FROM');
        $finalPos = strpos($query, 'FINAL');
        $samplePos = strpos($query, 'SAMPLE');
        $joinPos = strpos($query, 'JOIN');
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');
        $groupByPos = strpos($query, 'GROUP BY');
        $havingPos = strpos($query, 'HAVING');
        $orderByPos = strpos($query, 'ORDER BY');
        $limitPos = strpos($query, 'LIMIT');
        $offsetPos = strpos($query, 'OFFSET');

        $this->assertLessThan($fromPos, $selectPos);
        $this->assertLessThan($finalPos, $fromPos);
        $this->assertLessThan($samplePos, $finalPos);
        $this->assertLessThan($joinPos, $samplePos);
        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
        $this->assertLessThan($groupByPos, $wherePos);
        $this->assertLessThan($havingPos, $groupByPos);
        $this->assertLessThan($orderByPos, $havingPos);
        $this->assertLessThan($limitPos, $orderByPos);
        $this->assertLessThan($offsetPos, $limitPos);
    }

    public function testFinalComesAfterTableBeforeJoin(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $tablePos = strpos($query, '`events`');
        $finalPos = strpos($query, 'FINAL');
        $joinPos = strpos($query, 'JOIN');

        $this->assertLessThan($finalPos, $tablePos);
        $this->assertLessThan($joinPos, $finalPos);
    }

    public function testSampleComesAfterFinalBeforeJoin(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $finalPos = strpos($query, 'FINAL');
        $samplePos = strpos($query, 'SAMPLE');
        $joinPos = strpos($query, 'JOIN');

        $this->assertLessThan($samplePos, $finalPos);
        $this->assertLessThan($joinPos, $samplePos);
    }

    public function testPrewhereComesAfterJoinBeforeWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $joinPos = strpos($query, 'JOIN');
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');

        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testPrewhereBeforeGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $prewherePos = strpos($query, 'PREWHERE');
        $groupByPos = strpos($query, 'GROUP BY');

        $this->assertLessThan($groupByPos, $prewherePos);
    }

    public function testPrewhereBeforeOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->sortDesc('ts')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $prewherePos = strpos($query, 'PREWHERE');
        $orderByPos = strpos($query, 'ORDER BY');

        $this->assertLessThan($orderByPos, $prewherePos);
    }

    public function testPrewhereBeforeLimit(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $prewherePos = strpos($query, 'PREWHERE');
        $limitPos = strpos($query, 'LIMIT');

        $this->assertLessThan($limitPos, $prewherePos);
    }

    public function testFinalSampleBeforePrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $finalPos = strpos($query, 'FINAL');
        $samplePos = strpos($query, 'SAMPLE');
        $prewherePos = strpos($query, 'PREWHERE');

        $this->assertLessThan($samplePos, $finalPos);
        $this->assertLessThan($prewherePos, $samplePos);
    }

    public function testWhereBeforeHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::greaterThan('count', 0)])
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $wherePos = strpos($query, 'WHERE');
        $havingPos = strpos($query, 'HAVING');

        $this->assertLessThan($havingPos, $wherePos);
    }

    public function testFullQueryAllClausesAllPositions(): void
    {
        $other = (new Builder())->from('archive');
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->distinct()
            ->select(['name'])
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 0)])
            ->count('*', 'cnt')
            ->groupBy(['name'])
            ->having([Query::greaterThan('cnt', 5)])
            ->sortDesc('cnt')
            ->limit(50)
            ->offset(10)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;

        // All elements present
        $this->assertSame('(SELECT DISTINCT COUNT(*) AS `cnt`, `name` FROM `events` FINAL SAMPLE 0.1 JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `type` IN (?) WHERE `count` > ? GROUP BY `name` HAVING COUNT(*) > ? ORDER BY `cnt` DESC LIMIT ? OFFSET ?) UNION (SELECT * FROM `archive`)', $query);
    }

    public function testQueriesMethodWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->queries([
                Query::equal('status', ['active']),
                Query::orderDesc('ts'),
                Query::limit(10),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `status` IN (?) ORDER BY `ts` DESC LIMIT ?', $result->query);
    }

    public function testQueriesMethodWithFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->queries([
                Query::equal('status', ['active']),
                Query::limit(10),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL WHERE `status` IN (?) LIMIT ?', $result->query);
    }

    public function testQueriesMethodWithSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sample(0.5)
            ->queries([
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.5 WHERE `status` IN (?)', $result->query);
    }

    public function testQueriesMethodWithAllClickHouseFeatures(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->queries([
                Query::equal('status', ['active']),
                Query::orderDesc('ts'),
                Query::limit(10),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?) WHERE `status` IN (?) ORDER BY `ts` DESC LIMIT ?', $result->query);
    }

    public function testQueriesComparedToFluentApiSameSql(): void
    {
        $resultA = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->sortDesc('ts')
            ->limit(10)
            ->build();

        $resultB = (new Builder())
            ->from('events')
            ->queries([
                Query::equal('status', ['active']),
                Query::orderDesc('ts'),
                Query::limit(10),
            ])
            ->build();

        $this->assertSame($resultA->query, $resultB->query);
        $this->assertSame($resultA->bindings, $resultB->bindings);
    }

    public function testEmptyTableNameWithFinal(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->final()
            ->build();
    }

    public function testEmptyTableNameWithSample(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())
            ->sample(0.5)
            ->build();
    }

    public function testPrewhereWithEmptyFilterValues(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE 1 = 0', $result->query);
    }

    public function testVeryLongTableNameWithFinalSample(): void
    {
        $longName = str_repeat('a', 200);
        $result = (new Builder())
            ->from($longName)
            ->final()
            ->sample(0.1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringContainsString('`' . $longName . '`', $result->query);
        $this->assertStringContainsString('FINAL SAMPLE 0.1', $result->query);
    }

    public function testMultipleBuildsConsistentOutput(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)]);

        $result1 = $builder->build();
        $result2 = $builder->build();
        $result3 = $builder->build();

        $this->assertSame($result1->query, $result2->query);
        $this->assertSame($result2->query, $result3->query);
        $this->assertSame($result1->bindings, $result2->bindings);
        $this->assertSame($result2->bindings, $result3->bindings);
    }

    public function testBuildResetsBindingsButNotClickHouseState(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])]);

        $result1 = $builder->build();
        $result2 = $builder->build();

        // ClickHouse state persists
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `type` IN (?)', $result2->query);

        // Bindings are consistent
        $this->assertSame($result1->bindings, $result2->bindings);
    }

    public function testSampleWithAllBindingTypes(): void
    {
        $other = (new Builder())->from('archive')->filter([Query::equal('year', [2023])]);
        $result = (new Builder())
            ->from('events')
            ->sample(0.1)
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('cur1')
            ->sortAsc('_cursor')
            ->filter([Query::greaterThan('count', 5)])
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->having([Query::greaterThan('cnt', 10)])
            ->limit(50)
            ->offset(100)
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        // Verify all binding types present
        $this->assertNotEmpty($result->bindings);
        $this->assertGreaterThan(5, count($result->bindings));
    }

    public function testPrewhereAppearsCorrectlyWithoutJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `count` > ?', $query);

        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testPrewhereAppearsCorrectlyWithJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $joinPos = strpos($query, 'JOIN');
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');

        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testFinalSampleTextInOutputWithJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->join('users', 'events.uid', 'users.id')
            ->leftJoin('sessions', 'events.sid', 'sessions.id')
            ->build();
        $this->assertBindingCount($result);

        $query = $result->query;
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 JOIN `users` ON `events`.`uid` = `users`.`id` LEFT JOIN `sessions` ON `events`.`sid` = `sessions`.`id`', $query);

        // FINAL SAMPLE appears before JOINs
        $finalSamplePos = strpos($query, 'FINAL SAMPLE 0.1');
        $joinPos = strpos($query, 'JOIN');
        $this->assertLessThan($joinPos, $finalSamplePos);
    }

    public function testFilterCrossesThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::crosses('attr', [1])])->build();
    }

    public function testFilterNotCrossesThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::notCrosses('attr', [1])])->build();
    }

    public function testFilterDistanceEqualThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::distanceEqual('attr', [0, 0], 1)])->build();
    }

    public function testFilterDistanceNotEqualThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::distanceNotEqual('attr', [0, 0], 1)])->build();
    }

    public function testFilterDistanceGreaterThanThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::distanceGreaterThan('attr', [0, 0], 1)])->build();
    }

    public function testFilterDistanceLessThanThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::distanceLessThan('attr', [0, 0], 1)])->build();
    }

    public function testFilterIntersectsThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::intersects('attr', [1])])->build();
    }

    public function testFilterNotIntersectsThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::notIntersects('attr', [1])])->build();
    }

    public function testFilterOverlapsThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::overlaps('attr', [1])])->build();
    }

    public function testFilterNotOverlapsThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::notOverlaps('attr', [1])])->build();
    }

    public function testFilterTouchesThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::touches('attr', [1])])->build();
    }

    public function testFilterNotTouchesThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::notTouches('attr', [1])])->build();
    }

    public function testFilterVectorDotThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorDot('attr', [1.0, 2.0])])->build();
    }

    public function testFilterVectorCosineThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorCosine('attr', [1.0, 2.0])])->build();
    }

    public function testFilterVectorEuclideanThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::vectorEuclidean('attr', [1.0, 2.0])])->build();
    }

    public function testFilterElemMatchThrowsException(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->filter([Query::elemMatch('attr', [Query::equal('x', [1])])])->build();
    }

    public function testSampleZero(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(0.0);
    }

    public function testSampleOne(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(1.0);
    }

    public function testSampleNegative(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(-0.5);
    }

    public function testSampleGreaterThanOne(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(2.0);
    }

    public function testSampleVerySmall(): void
    {
        $result = (new Builder())->from('t')->sample(0.001)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` SAMPLE 0.001', $result->query);
    }

    public function testCompileFilterStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileFilter(Query::greaterThan('age', 18));
        $this->assertSame('`age` > ?', $sql);
        $this->assertSame([18], $builder->getBindings());
    }

    public function testCompileOrderAscStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderAsc('name'));
        $this->assertSame('`name` ASC', $sql);
    }

    public function testCompileOrderDescStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderDesc('name'));
        $this->assertSame('`name` DESC', $sql);
    }

    public function testCompileOrderRandomStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOrder(Query::orderRandom());
        $this->assertSame('rand()', $sql);
    }

    public function testCompileOrderExceptionStandalone(): void
    {
        $builder = new Builder();
        $this->expectException(UnsupportedException::class);
        $builder->compileOrder(Query::limit(10));
    }

    public function testCompileLimitStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileLimit(Query::limit(10));
        $this->assertSame('LIMIT ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testCompileOffsetStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileOffset(Query::offset(5));
        $this->assertSame('OFFSET ?', $sql);
        $this->assertSame([5], $builder->getBindings());
    }

    public function testCompileSelectStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileSelect(Query::select(['a', 'b']));
        $this->assertSame('`a`, `b`', $sql);
    }

    public function testCompileSelectEmptyStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileSelect(Query::select([]));
        $this->assertSame('', $sql);
    }

    public function testCompileCursorAfterStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileCursor(Query::cursorAfter('abc'));
        $this->assertSame('`_cursor` > ?', $sql);
        $this->assertSame(['abc'], $builder->getBindings());
    }

    public function testCompileCursorBeforeStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileCursor(Query::cursorBefore('xyz'));
        $this->assertSame('`_cursor` < ?', $sql);
        $this->assertSame(['xyz'], $builder->getBindings());
    }

    public function testCompileAggregateCountStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::count('*', 'total'));
        $this->assertSame('COUNT(*) AS `total`', $sql);
    }

    public function testCompileAggregateSumStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::sum('price'));
        $this->assertSame('SUM(`price`)', $sql);
    }

    public function testCompileAggregateAvgWithAliasStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileAggregate(Query::avg('score', 'avg_score'));
        $this->assertSame('AVG(`score`) AS `avg_score`', $sql);
    }

    public function testCompileGroupByStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileGroupBy(Query::groupBy(['status', 'country']));
        $this->assertSame('`status`, `country`', $sql);
    }

    public function testCompileGroupByEmptyStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileGroupBy(Query::groupBy([]));
        $this->assertSame('', $sql);
    }

    public function testCompileJoinStandalone(): void
    {
        $builder = new Builder();
        $sql = $builder->compileJoin(Query::join('orders', 'u.id', 'o.uid'));
        $this->assertSame('JOIN `orders` ON `u`.`id` = `o`.`uid`', $sql);
    }

    public function testCompileJoinExceptionStandalone(): void
    {
        $builder = new Builder();
        $this->expectException(UnsupportedException::class);
        $builder->compileJoin(Query::equal('x', [1]));
    }

    public function testUnionBothWithClickHouseFeatures(): void
    {
        $sub = (new Builder())->from('archive')
            ->final()
            ->sample(0.5)
            ->filter([Query::equal('status', ['closed'])]);
        $result = (new Builder())->from('events')
            ->final()
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM `events` FINAL PREWHERE `type` IN (?) WHERE `count` > ?) UNION (SELECT * FROM `archive` FINAL SAMPLE 0.5 WHERE `status` IN (?))', $result->query);
    }

    public function testUnionAllBothWithFinal(): void
    {
        $sub = (new Builder())->from('b')->final();
        $result = (new Builder())->from('a')->final()
            ->unionAll($sub)
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('(SELECT * FROM `a` FINAL) UNION ALL (SELECT * FROM `b` FINAL)', $result->query);
    }

    public function testPrewhereBindingOrderWithFilterAndHaving(): void
    {
        $result = (new Builder())->from('t')
            ->count('*', 'total')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->groupBy(['type'])
            ->having([Query::greaterThan('total', 10)])
            ->build();
        $this->assertBindingCount($result);
        // Binding order: prewhere, filter, having
        $this->assertSame(['click', 5, 10], $result->bindings);
    }

    public function testPrewhereBindingOrderWithProviderAndCursor(): void
    {
        $result = (new Builder())->from('t')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);
        // Binding order: prewhere, filter(none), provider, cursor
        $this->assertSame(['click', 't1', 'abc'], $result->bindings);
    }

    public function testPrewhereMultipleFiltersBindingOrder(): void
    {
        $result = (new Builder())->from('t')
            ->prewhere([
                Query::equal('type', ['a']),
                Query::greaterThan('priority', 3),
            ])
            ->filter([Query::lessThan('age', 30)])
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);
        // prewhere bindings first, then filter, then limit
        $this->assertSame(['a', 3, 30, 10], $result->bindings);
    }

    public function testSearchInFilterThrowsExceptionWithMessage(): void
    {
        $this->expectException(UnsupportedException::class);
        $this->expectExceptionMessage('Full-text search');
        (new Builder())->from('t')->filter([Query::search('content', 'hello')])->build();
    }

    public function testSearchInPrewhereThrowsExceptionWithMessage(): void
    {
        $this->expectException(UnsupportedException::class);
        (new Builder())->from('t')->prewhere([Query::search('content', 'hello')])->build();
    }

    public function testLeftJoinWithFinalAndSample(): void
    {
        $result = (new Builder())->from('events')
            ->final()
            ->sample(0.1)
            ->leftJoin('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT * FROM `events` FINAL SAMPLE 0.1 LEFT JOIN `users` ON `events`.`uid` = `users`.`id`',
            $result->query
        );
    }

    public function testRightJoinWithFinalFeature(): void
    {
        $result = (new Builder())->from('events')
            ->final()
            ->rightJoin('users', 'events.uid', 'users.id')
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` FINAL RIGHT JOIN `users` ON `events`.`uid` = `users`.`id`', $result->query);
    }

    public function testCrossJoinWithPrewhereFeature(): void
    {
        $result = (new Builder())->from('events')
            ->crossJoin('colors')
            ->prewhere([Query::equal('type', ['a'])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `events` CROSS JOIN `colors` PREWHERE `type` IN (?)', $result->query);
        $this->assertSame(['a'], $result->bindings);
    }

    public function testJoinWithNonDefaultOperator(): void
    {
        $result = (new Builder())->from('t')
            ->join('other', 'a', 'b', '!=')
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` JOIN `other` ON `a` != `b`', $result->query);
    }

    public function testConditionProviderInWhereNotPrewhere(): void
    {
        $result = (new Builder())->from('t')
            ->prewhere([Query::equal('type', ['click'])])
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            })
            ->build();
        $this->assertBindingCount($result);
        $query = $result->query;
        $prewherePos = strpos($query, 'PREWHERE');
        $wherePos = strpos($query, 'WHERE');
        // Provider should be in WHERE which comes after PREWHERE
        $this->assertNotFalse($prewherePos);
        $this->assertNotFalse($wherePos);
        $this->assertGreaterThan($prewherePos, $wherePos);
        $this->assertSame('SELECT * FROM `t` PREWHERE `type` IN (?) WHERE _tenant = ?', $query);
    }

    public function testConditionProviderWithNoFiltersClickHouse(): void
    {
        $result = (new Builder())->from('t')
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_deleted = ?', [0]);
                }
            })
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE _deleted = ?', $result->query);
        $this->assertSame([0], $result->bindings);
    }

    public function testPageZero(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->page(0, 10)->build();
    }

    public function testPageNegative(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->page(-1, 10)->build();
    }

    public function testPageLargeNumber(): void
    {
        $result = (new Builder())->from('t')->page(1000000, 25)->build();
        $this->assertBindingCount($result);
        $this->assertSame([25, 24999975], $result->bindings);
    }

    public function testBuildWithoutFrom(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No table specified');
        (new Builder())->filter([Query::equal('x', [1])])->build();
    }

    public function testToRawSqlWithFinalAndSampleEdge(): void
    {
        $sql = (new Builder())->from('events')
            ->final()
            ->sample(0.1)
            ->filter([Query::equal('type', ['click'])])
            ->toRawSql();
        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.1 WHERE `type` IN (\'click\')', $sql);
    }

    public function testToRawSqlWithPrewhereEdge(): void
    {
        $sql = (new Builder())->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->toRawSql();
        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (\'click\') WHERE `count` > 5', $sql);
    }

    public function testToRawSqlWithUnionEdge(): void
    {
        $sub = (new Builder())->from('b')->filter([Query::equal('x', [1])]);
        $sql = (new Builder())->from('a')->final()
            ->filter([Query::equal('y', [2])])
            ->union($sub)
            ->toRawSql();
        $this->assertSame('(SELECT * FROM `a` FINAL WHERE `y` IN (2)) UNION (SELECT * FROM `b` WHERE `x` IN (1))', $sql);
    }

    public function testToRawSqlWithBoolFalse(): void
    {
        $sql = (new Builder())->from('t')->filter([Query::equal('active', [false])])->toRawSql();
        $this->assertSame('SELECT * FROM `t` WHERE `active` IN (0)', $sql);
    }

    public function testToRawSqlWithNull(): void
    {
        $sql = (new Builder())->from('t')->filter([Query::raw('col = ?', [null])])->toRawSql();
        $this->assertSame('SELECT * FROM `t` WHERE col = NULL', $sql);
    }

    public function testToRawSqlMixedTypes(): void
    {
        $sql = (new Builder())->from('t')
            ->filter([
                Query::equal('name', ['str']),
                Query::greaterThan('age', 42),
                Query::lessThan('score', 9.99),
            ])
            ->toRawSql();
        $this->assertSame('SELECT * FROM `t` WHERE `name` IN (\'str\') AND `age` > 42 AND `score` < 9.99', $sql);
    }

    public function testHavingMultipleSubQueries(): void
    {
        $result = (new Builder())->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([
                Query::greaterThan('total', 5),
                Query::lessThan('total', 100),
            ])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status` HAVING COUNT(*) > ? AND COUNT(*) < ?', $result->query);
        $this->assertContains(5, $result->bindings);
        $this->assertContains(100, $result->bindings);
    }

    public function testHavingWithOrLogic(): void
    {
        $result = (new Builder())->from('t')
            ->count('*', 'total')
            ->groupBy(['status'])
            ->having([Query::or([
                Query::greaterThan('total', 100),
                Query::lessThan('total', 5),
            ])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT COUNT(*) AS `total` FROM `t` GROUP BY `status` HAVING (`total` > ? OR `total` < ?)', $result->query);
    }

    public function testResetClearsClickHouseProperties(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->limit(10);

        $builder->reset()->from('other');
        $result = $builder->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `other`', $result->query);
        $this->assertSame([], $result->bindings);
        $this->assertStringNotContainsString('FINAL', $result->query);
        $this->assertStringNotContainsString('SAMPLE', $result->query);
        $this->assertStringNotContainsString('PREWHERE', $result->query);
    }

    public function testResetFollowedByUnion(): void
    {
        $builder = (new Builder())->from('a')
            ->final()
            ->union((new Builder())->from('old'));
        $builder->reset()->from('b');
        $result = $builder->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `b`', $result->query);
        $this->assertStringNotContainsString('UNION', $result->query);
        $this->assertStringNotContainsString('FINAL', $result->query);
    }

    public function testConditionProviderPersistsAfterReset(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->final()
            ->addHook(new class () implements Filter {
                public function filter(string $table): Condition
                {
                    return new Condition('_tenant = ?', ['t1']);
                }
            });
        $builder->build();
        $builder->reset()->from('other');
        $result = $builder->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `other` WHERE _tenant = ?', $result->query);
        $this->assertStringNotContainsString('FINAL', $result->query);
        $this->assertSame('SELECT * FROM `other` WHERE _tenant = ?', $result->query);
    }

    public function testFinalSamplePrewhereFilterExactSql(): void
    {
        $result = (new Builder())->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('event_type', ['purchase'])])
            ->filter([Query::greaterThan('amount', 100)])
            ->sortDesc('amount')
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `event_type` IN (?) WHERE `amount` > ? ORDER BY `amount` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['purchase', 100, 50], $result->bindings);
    }

    public function testKitchenSinkExactSql(): void
    {
        $sub = (new Builder())->from('archive')->final()->filter([Query::equal('status', ['closed'])]);
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->distinct()
            ->count('*', 'total')
            ->select(['event_type'])
            ->join('users', 'events.uid', 'users.id')
            ->prewhere([Query::equal('event_type', ['purchase'])])
            ->filter([Query::greaterThan('amount', 100)])
            ->groupBy(['event_type'])
            ->having([Query::greaterThan('total', 5)])
            ->sortDesc('total')
            ->limit(50)
            ->offset(10)
            ->union($sub)
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame(
            '(SELECT DISTINCT COUNT(*) AS `total`, `event_type` FROM `events` FINAL SAMPLE 0.1 JOIN `users` ON `events`.`uid` = `users`.`id` PREWHERE `event_type` IN (?) WHERE `amount` > ? GROUP BY `event_type` HAVING COUNT(*) > ? ORDER BY `total` DESC LIMIT ? OFFSET ?) UNION (SELECT * FROM `archive` FINAL WHERE `status` IN (?))',
            $result->query
        );
        $this->assertSame(['purchase', 100, 5, 50, 10, 'closed'], $result->bindings);
    }

    public function testQueryCompileFilterViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::greaterThan('age', 18)->compile($builder);
        $this->assertSame('`age` > ?', $sql);
    }

    public function testQueryCompileRegexViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::regex('path', '^/api')->compile($builder);
        $this->assertSame('match(`path`, ?)', $sql);
    }

    public function testQueryCompileOrderRandomViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::orderRandom()->compile($builder);
        $this->assertSame('rand()', $sql);
    }

    public function testQueryCompileLimitViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::limit(10)->compile($builder);
        $this->assertSame('LIMIT ?', $sql);
        $this->assertSame([10], $builder->getBindings());
    }

    public function testQueryCompileSelectViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::select(['a', 'b'])->compile($builder);
        $this->assertSame('`a`, `b`', $sql);
    }

    public function testQueryCompileJoinViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::join('orders', 'u.id', 'o.uid')->compile($builder);
        $this->assertSame('JOIN `orders` ON `u`.`id` = `o`.`uid`', $sql);
    }

    public function testQueryCompileGroupByViaClickHouse(): void
    {
        $builder = new Builder();
        $sql = Query::groupBy(['status'])->compile($builder);
        $this->assertSame('`status`', $sql);
    }

    public function testBindingTypesPreservedInt(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThan('age', 18)])->build();
        $this->assertBindingCount($result);
        $this->assertSame([18], $result->bindings);
    }

    public function testBindingTypesPreservedFloat(): void
    {
        $result = (new Builder())->from('t')->filter([Query::greaterThan('score', 9.5)])->build();
        $this->assertBindingCount($result);
        $this->assertSame([9.5], $result->bindings);
    }

    public function testBindingTypesPreservedBool(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('active', [true])])->build();
        $this->assertBindingCount($result);
        $this->assertSame([true], $result->bindings);
    }

    public function testBindingTypesPreservedNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('val', [null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `val` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testEqualWithNullAndNonNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('col', ['a', null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`col` IN (?) OR `col` IS NULL)', $result->query);
        $this->assertSame(['a'], $result->bindings);
    }

    public function testNotEqualWithNullOnly(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('col', [null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `col` IS NOT NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testNotEqualWithNullAndNonNull(): void
    {
        $result = (new Builder())->from('t')->filter([Query::notEqual('col', ['a', 'b', null])])->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`col` NOT IN (?, ?) AND `col` IS NOT NULL)', $result->query);
        $this->assertSame(['a', 'b'], $result->bindings);
    }

    public function testBindingTypesPreservedString(): void
    {
        $result = (new Builder())->from('t')->filter([Query::equal('name', ['hello'])])->build();
        $this->assertBindingCount($result);
        $this->assertSame(['hello'], $result->bindings);
    }

    public function testRawInsideLogicalAnd(): void
    {
        $result = (new Builder())->from('t')
            ->filter([Query::and([
                Query::greaterThan('x', 1),
                Query::raw('custom_func(y) > ?', [5]),
            ])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`x` > ? AND custom_func(y) > ?)', $result->query);
        $this->assertSame([1, 5], $result->bindings);
    }

    public function testRawInsideLogicalOr(): void
    {
        $result = (new Builder())->from('t')
            ->filter([Query::or([
                Query::equal('a', [1]),
                Query::raw('b IS NOT NULL', []),
            ])])
            ->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE (`a` IN (?) OR b IS NOT NULL)', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testNegativeLimit(): void
    {
        $result = (new Builder())->from('t')->limit(-1)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([-1], $result->bindings);
    }

    public function testNegativeOffset(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->offset(-5)->build();
    }


    public function testLimitZero(): void
    {
        $result = (new Builder())->from('t')->limit(0)->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` LIMIT ?', $result->query);
        $this->assertSame([0], $result->bindings);
    }

    public function testMultipleLimitsFirstWins(): void
    {
        $result = (new Builder())->from('t')->limit(10)->limit(20)->build();
        $this->assertBindingCount($result);
        $this->assertSame([10], $result->bindings);
    }

    public function testMultipleOffsetsFirstWins(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->offset(5)->offset(50)->build();
    }


    public function testCursorAfterAndBeforeFirstWins(): void
    {
        $result = (new Builder())->from('t')->cursorAfter('a')->cursorBefore('b')->sortAsc('_cursor')->build();
        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
    }

    public function testDistinctWithUnion(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())->from('a')->distinct()->union($other)->build();
        $this->assertBindingCount($result);
        $this->assertSame('(SELECT DISTINCT * FROM `a`) UNION (SELECT * FROM `b`)', $result->query);
    }

    public function testInsertSingleRow(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['name' => 'click', 'timestamp' => '2024-01-01'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `events` (`name`, `timestamp`) VALUES (?, ?)',
            $result->query
        );
        $this->assertSame(['click', '2024-01-01'], $result->bindings);
    }

    public function testInsertBatch(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['name' => 'click', 'ts' => '2024-01-01'])
            ->set(['name' => 'view', 'ts' => '2024-01-02'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `events` (`name`, `ts`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['click', '2024-01-01', 'view', '2024-01-02'], $result->bindings);
    }

    public function testDoesNotImplementUpsert(): void
    {
        $interfaces = \class_implements(Builder::class);
        $this->assertIsArray($interfaces);
        $this->assertArrayNotHasKey(Upsert::class, $interfaces);
    }

    public function testUpdateUsesAlterTable(): void
    {
        $result = (new Builder())
            ->from('events')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('status', ['old'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `events` UPDATE `status` = ? WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['archived', 'old'], $result->bindings);
    }

    public function testUpdateWithFilterHook(): void
    {
        $hook = new class () implements Filter, Hook {
            public function filter(string $table): Condition
            {
                return new Condition('`_tenant` = ?', ['tenant_123']);
            }
        };

        $result = (new Builder())
            ->from('events')
            ->set(['status' => 'active'])
            ->filter([Query::equal('id', [1])])
            ->addHook($hook)
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `events` UPDATE `status` = ? WHERE `id` IN (?) AND `_tenant` = ?',
            $result->query
        );
        $this->assertSame(['active', 1, 'tenant_123'], $result->bindings);
    }

    public function testUpdateWithoutWhereThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ClickHouse UPDATE requires a WHERE clause');

        (new Builder())
            ->from('events')
            ->set(['status' => 'active'])
            ->update();
    }

    public function testDeleteDefaultsToLightweightDeleteFrom(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::lessThan('timestamp', '2024-01-01')])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `events` WHERE `timestamp` < ?',
            $result->query
        );
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testDeleteUsesAlterTableWhenMutationModeOptedIn(): void
    {
        $result = (new Builder())
            ->from('events')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([Query::lessThan('timestamp', '2024-01-01')])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `events` DELETE WHERE `timestamp` < ?',
            $result->query
        );
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testDeleteWithFilterHook(): void
    {
        $hook = new class () implements Filter, Hook {
            public function filter(string $table): Condition
            {
                return new Condition('`_tenant` = ?', ['tenant_123']);
            }
        };

        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['deleted'])])
            ->addHook($hook)
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'DELETE FROM `events` WHERE `status` IN (?) AND `_tenant` = ?',
            $result->query
        );
        $this->assertSame(['deleted', 'tenant_123'], $result->bindings);
    }

    public function testDeleteWithoutWhereThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ClickHouse DELETE requires a WHERE clause');

        (new Builder())
            ->from('events')
            ->delete();
    }

    public function testIntersect(): void
    {
        $other = (new Builder())->from('admins');
        $result = (new Builder())
            ->from('users')
            ->intersect($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) INTERSECT (SELECT * FROM `admins`)',
            $result->query
        );
    }

    public function testExcept(): void
    {
        $other = (new Builder())->from('banned');
        $result = (new Builder())
            ->from('users')
            ->except($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            '(SELECT * FROM `users`) EXCEPT (SELECT * FROM `banned`)',
            $result->query
        );
    }

    public function testDoesNotImplementLocking(): void
    {
        $interfaces = \class_implements(Builder::class);
        $this->assertIsArray($interfaces);
        $this->assertArrayNotHasKey(Locking::class, $interfaces);
    }

    public function testDoesNotImplementTransactions(): void
    {
        $interfaces = \class_implements(Builder::class);
        $this->assertIsArray($interfaces);
        $this->assertArrayNotHasKey(Transactions::class, $interfaces);
    }

    public function testInsertSelect(): void
    {
        $source = (new Builder())
            ->from('events')
            ->select(['name', 'timestamp'])
            ->filter([Query::equal('type', ['click'])]);

        $result = (new Builder())
            ->into('archived_events')
            ->fromSelect(['name', 'timestamp'], $source)
            ->insertSelect();

        $this->assertSame(
            'INSERT INTO `archived_events` (`name`, `timestamp`) SELECT `name`, `timestamp` FROM `events` WHERE `type` IN (?)',
            $result->query
        );
        $this->assertSame(['click'], $result->bindings);
    }

    public function testCteWith(): void
    {
        $cte = (new Builder())
            ->from('events')
            ->filter([Query::equal('type', ['click'])]);

        $result = (new Builder())
            ->with('clicks', $cte)
            ->from('clicks')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH `clicks` AS (SELECT * FROM `events` WHERE `type` IN (?)) SELECT * FROM `clicks`',
            $result->query
        );
        $this->assertSame(['click'], $result->bindings);
    }

    public function testSetRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->setRaw('count', 'count + ?', [1])
            ->filter([Query::equal('id', [42])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `events` UPDATE `count` = count + ? WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame([1, 42], $result->bindings);
    }

    public function testImplementsHints(): void
    {
        $this->assertInstanceOf(Hints::class, new Builder());
    }

    public function testHintAppendsSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->hint('max_threads=4')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=4', $result->query);
    }

    public function testMultipleHints(): void
    {
        $result = (new Builder())
            ->from('events')
            ->hint('max_threads=4')
            ->hint('max_memory_usage=1000000000')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=4, max_memory_usage=1000000000', $result->query);
    }

    public function testSettingsMethod(): void
    {
        $result = (new Builder())
            ->from('events')
            ->settings(['max_threads' => '4', 'max_memory_usage' => '1000000000'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=4, max_memory_usage=1000000000', $result->query);
    }

    public function testImplementsWindows(): void
    {
        $this->assertInstanceOf(Windows::class, new Builder());
    }

    public function testSelectWindowRowNumber(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['timestamp'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `timestamp` ASC) AS `rn` FROM `events`', $result->query);
    }

    public function testDoesNotImplementSpatial(): void
    {
        $builder = new Builder();
        $this->assertNotInstanceOf(Spatial::class, $builder); // @phpstan-ignore method.alreadyNarrowedType
    }

    public function testDoesNotImplementVectorSearch(): void
    {
        $builder = new Builder();
        $this->assertNotInstanceOf(VectorSearch::class, $builder); // @phpstan-ignore method.alreadyNarrowedType
    }

    public function testDoesNotImplementJson(): void
    {
        $builder = new Builder();
        $this->assertNotInstanceOf(Json::class, $builder); // @phpstan-ignore method.alreadyNarrowedType
    }

    public function testResetClearsHints(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->hint('max_threads=4');

        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('SETTINGS', $result->query);
    }

    public function testPrewhereWithSingleFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->prewhere([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` PREWHERE `status` IN (?)', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testPrewhereWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->prewhere([
                Query::equal('status', ['active']),
                Query::greaterThan('age', 18),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` PREWHERE `status` IN (?) AND `age` > ?', $result->query);
        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testPrewhereBeforeWhere(): void
    {
        $result = (new Builder())
            ->from('t')
            ->prewhere([Query::equal('status', ['active'])])
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $prewherePos = strpos($result->query, 'PREWHERE');
        $wherePos = strpos($result->query, 'WHERE');

        $this->assertNotFalse($prewherePos);
        $this->assertNotFalse($wherePos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testPrewhereBindingOrderBeforeWhere(): void
    {
        $result = (new Builder())
            ->from('t')
            ->prewhere([Query::equal('status', ['active'])])
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 18], $result->bindings);
    }

    public function testPrewhereWithJoin(): void
    {
        $result = (new Builder())
            ->from('t')
            ->join('u', 't.uid', 'u.id')
            ->prewhere([Query::equal('status', ['active'])])
            ->filter([Query::greaterThan('age', 18)])
            ->build();
        $this->assertBindingCount($result);

        $joinPos = strpos($result->query, 'JOIN');
        $prewherePos = strpos($result->query, 'PREWHERE');
        $wherePos = strpos($result->query, 'WHERE');

        $this->assertNotFalse($joinPos);
        $this->assertNotFalse($prewherePos);
        $this->assertNotFalse($wherePos);
        $this->assertLessThan($prewherePos, $joinPos);
        $this->assertLessThan($wherePos, $prewherePos);
    }

    public function testFinalKeywordInFromClause(): void
    {
        $result = (new Builder())
            ->from('t')
            ->final()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` FINAL', $result->query);
    }

    public function testFinalAppearsBeforeWhere(): void
    {
        $result = (new Builder())
            ->from('t')
            ->final()
            ->filter([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $finalPos = strpos($result->query, 'FINAL');
        $wherePos = strpos($result->query, 'WHERE');

        $this->assertNotFalse($finalPos);
        $this->assertNotFalse($wherePos);
        $this->assertLessThan($wherePos, $finalPos);
    }

    public function testFinalWithSample(): void
    {
        $result = (new Builder())
            ->from('t')
            ->final()
            ->sample(0.5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` FINAL SAMPLE 0.5', $result->query);
    }

    public function testSampleFraction(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sample(0.1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` SAMPLE 0.1', $result->query);
    }

    public function testSampleZeroThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->sample(0.0);
    }

    public function testSampleOneThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->sample(1.0);
    }

    public function testSampleNegativeThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->sample(-0.5);
    }

    public function testUpdateAlterTableSyntax(): void
    {
        $result = (new Builder())
            ->from('t')
            ->set(['name' => 'Bob'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `t` UPDATE `name` = ? WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame(['Bob', 1], $result->bindings);
    }

    public function testUpdateWithoutWhereClauseThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('WHERE');

        (new Builder())
            ->from('t')
            ->set(['name' => 'Bob'])
            ->update();
    }

    public function testUpdateWithoutAssignmentsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::equal('id', [1])])
            ->update();
    }

    public function testUpdateWithRawSet(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setRaw('counter', '`counter` + 1')
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `t` UPDATE `counter` = `counter` + 1 WHERE `id` IN (?)', $result->query);
    }

    public function testUpdateWithRawSetBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setRaw('name', 'CONCAT(?, ?)', ['hello', ' world'])
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `t` UPDATE `name` = CONCAT(?, ?) WHERE `id` IN (?)', $result->query);
        $this->assertSame(['hello', ' world', 1], $result->bindings);
    }

    public function testDeleteAlterTableSyntax(): void
    {
        $result = (new Builder())
            ->from('t')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([Query::equal('id', [1])])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame(
            'ALTER TABLE `t` DELETE WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
    }

    public function testDeleteWithoutWhereClauseThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('t')
            ->delete();
    }

    public function testDeleteWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::equal('status', ['old']),
                Query::lessThan('age', 5),
            ])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertSame('DELETE FROM `t` WHERE `status` IN (?) AND `age` < ?', $result->query);
        $this->assertSame(['old', 5], $result->bindings);
    }

    public function testStartsWithUsesStartsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::startsWith('name', 'foo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE startsWith(`name`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testNotStartsWithUsesNotStartsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notStartsWith('name', 'foo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT startsWith(`name`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testEndsWithUsesEndsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::endsWith('name', 'foo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE endsWith(`name`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testNotEndsWithUsesNotEndsWith(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEndsWith('name', 'foo')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE NOT endsWith(`name`, ?)', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testContainsSingleValueUsesPosition(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('name', ['foo'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE position(`name`, ?) > 0', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testContainsMultipleValuesUsesOrPosition(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsString('name', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (position(`name`, ?) > 0 OR position(`name`, ?) > 0)', $result->query);
        $this->assertSame(['foo', 'bar'], $result->bindings);
    }

    public function testContainsAllUsesAndPosition(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::containsAll('name', ['foo', 'bar'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (position(`name`, ?) > 0 AND position(`name`, ?) > 0)', $result->query);
        $this->assertSame(['foo', 'bar'], $result->bindings);
    }

    public function testNotContainsSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('name', ['foo'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE position(`name`, ?) = 0', $result->query);
        $this->assertSame(['foo'], $result->bindings);
    }

    public function testNotContainsMultipleValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notContains('name', ['a', 'b'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (position(`name`, ?) = 0 AND position(`name`, ?) = 0)', $result->query);
        $this->assertSame(['a', 'b'], $result->bindings);
    }

    public function testRegexUsesMatch(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::regex('name', '^test')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE match(`name`, ?)', $result->query);
        $this->assertSame(['^test'], $result->bindings);
    }

    public function testSearchThrowsUnsupported(): void
    {
        $this->expectException(UnsupportedException::class);

        (new Builder())
            ->from('t')
            ->filter([Query::search('body', 'hello')])
            ->build();
    }

    public function testSettingsKeyValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->settings(['max_threads' => '4', 'enable_optimize_predicate_expression' => '1'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` SETTINGS max_threads=4, enable_optimize_predicate_expression=1', $result->query);
    }

    public function testHintAndSettingsCombined(): void
    {
        $result = (new Builder())
            ->from('t')
            ->hint('max_threads=2')
            ->settings(['enable_optimize_predicate_expression' => '1'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` SETTINGS max_threads=2, enable_optimize_predicate_expression=1', $result->query);
    }

    public function testHintsPreserveBindings(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('status', ['active'])])
            ->hint('max_threads=4')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active'], $result->bindings);
        $this->assertSame('SELECT * FROM `t` WHERE `status` IN (?) SETTINGS max_threads=4', $result->query);
    }

    public function testHintsWithJoin(): void
    {
        $result = (new Builder())
            ->from('t')
            ->join('u', 't.uid', 'u.id')
            ->hint('max_threads=4')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` JOIN `u` ON `t`.`uid` = `u`.`id` SETTINGS max_threads=4', $result->query);
        // SETTINGS must be at the very end
        $this->assertStringEndsWith('SETTINGS max_threads=4', $result->query);
    }

    public function testCTE(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->filter([Query::equal('type', ['click'])]);

        $result = (new Builder())
            ->with('sub', $sub)
            ->from('sub')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'WITH `sub` AS (SELECT * FROM `events` WHERE `type` IN (?)) SELECT * FROM `sub`',
            $result->query
        );
        $this->assertSame(['click'], $result->bindings);
    }

    public function testCTERecursive(): void
    {
        $sub = (new Builder())
            ->from('categories')
            ->filter([Query::equal('parent_id', [0])]);

        $result = (new Builder())
            ->withRecursive('tree', $sub)
            ->from('tree')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH RECURSIVE `tree` AS (SELECT * FROM `categories` WHERE `parent_id` IN (?)) SELECT * FROM `tree`', $result->query);
    }

    public function testCTEBindingOrder(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->filter([Query::equal('type', ['click'])]);

        $result = (new Builder())
            ->with('sub', $sub)
            ->from('sub')
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        // CTE bindings come before main query bindings
        $this->assertSame(['click', 5], $result->bindings);
    }

    public function testWindowFunctionPartitionAndOrder(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['created_at'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `created_at` ASC) AS `rn` FROM `t`', $result->query);
    }

    public function testWindowFunctionOrderDescending(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['-created_at'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `created_at` DESC) AS `rn` FROM `t`', $result->query);
    }

    public function testMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()', 'rn', ['user_id'], ['created_at'])
            ->selectWindow('SUM(`amount`)', 'total', ['user_id'], null)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT ROW_NUMBER() OVER (PARTITION BY `user_id` ORDER BY `created_at` ASC) AS `rn`, SUM(`amount`) OVER (PARTITION BY `user_id`) AS `total` FROM `t`', $result->query);
    }

    public function testSelectCaseExpression(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->else('Unknown')
            ->alias('label');

        $result = (new Builder())
            ->from('t')
            ->selectCase($case)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CASE WHEN `status` = ? THEN ? ELSE ? END AS `label` FROM `t`', $result->query);
        $this->assertSame(['active', 'Active', 'Unknown'], $result->bindings);
    }

    public function testSetCaseInUpdate(): void
    {
        $case = (new CaseExpression())
            ->when('role', Operator::Equal, 'admin', 'Admin')
            ->else('User');

        $result = (new Builder())
            ->from('t')
            ->setCase('label', $case)
            ->filter([Query::equal('id', [1])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame('ALTER TABLE `t` UPDATE `label` = CASE WHEN `role` = ? THEN ? ELSE ? END WHERE `id` IN (?)', $result->query);
        $this->assertSame(['admin', 'Admin', 'User', 1], $result->bindings);
    }

    public function testUnionSimple(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())
            ->from('a')
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `a`) UNION (SELECT * FROM `b`)', $result->query);
        $this->assertStringNotContainsString('UNION ALL', $result->query);
    }

    public function testUnionAll(): void
    {
        $other = (new Builder())->from('b');
        $result = (new Builder())
            ->from('a')
            ->unionAll($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT * FROM `a`) UNION ALL (SELECT * FROM `b`)', $result->query);
    }

    public function testUnionBindingsOrder(): void
    {
        $other = (new Builder())->from('b')->filter([Query::equal('y', [2])]);
        $result = (new Builder())
            ->from('a')
            ->filter([Query::equal('x', [1])])
            ->union($other)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2], $result->bindings);
    }

    public function testPage(): void
    {
        $result = (new Builder())
            ->from('t')
            ->page(2, 25)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([25, 25], $result->bindings);
    }

    public function testCursorAfter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->cursorAfter('abc')
            ->sortAsc('_cursor')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `_cursor` > ? ORDER BY `_cursor` ASC', $result->query);
        $this->assertSame(['abc'], $result->bindings);
    }

    public function testBuildWithoutTableThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->build();
    }

    public function testInsertWithoutRowsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('t')
            ->insert();
    }

    public function testBatchInsertMismatchedColumnsThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->into('t')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'email' => 'bob@example.com'])
            ->insert();
    }

    public function testBatchInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('t')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'age' => 25])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `t` (`name`, `age`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 30, 'Bob', 25], $result->bindings);
    }

    public function testJoinFilterForcedToWhere(): void
    {
        $hook = new class () implements JoinFilter {
            public function filterJoin(string $table, JoinType $joinType): JoinCondition
            {
                return new JoinCondition(
                    new Condition('`active` = ?', [1]),
                    Placement::On,
                );
            }
        };

        $result = (new Builder())
            ->from('t')
            ->addHook($hook)
            ->leftJoin('u', 't.uid', 'u.id')
            ->build();
        $this->assertBindingCount($result);

        // ClickHouse forces all join filter conditions to WHERE placement
        $this->assertSame('SELECT * FROM `t` LEFT JOIN `u` ON `t`.`uid` = `u`.`id` WHERE `active` = ?', $result->query);
        $this->assertStringNotContainsString('ON `t`.`uid` = `u`.`id` AND', $result->query);
    }

    public function testToRawSqlClickHouseSyntax(): void
    {
        $sql = (new Builder())
            ->from('t')
            ->final()
            ->filter([Query::equal('status', ['active'])])
            ->limit(10)
            ->toRawSql();

        $this->assertSame('SELECT * FROM `t` FINAL WHERE `status` IN (\'active\') LIMIT 10', $sql);
        $this->assertStringNotContainsString('?', $sql);
    }

    public function testResetClearsPrewhere(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->prewhere([Query::equal('status', ['active'])]);

        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('PREWHERE', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testResetClearsSampleAndFinal(): void
    {
        $builder = (new Builder())
            ->from('t')
            ->final()
            ->sample(0.5);

        $builder->build();
        $builder->reset();

        $result = $builder->from('t')->build();
        $this->assertBindingCount($result);
        $this->assertStringNotContainsString('FINAL', $result->query);
        $this->assertStringNotContainsString('SAMPLE', $result->query);
    }

    public function testEqualEmptyArrayReturnsFalse(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 0', $result->query);
    }

    public function testEqualWithNullOnly(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` IS NULL', $result->query);
    }

    public function testEqualWithNullAndValues(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal('x', [1, null])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`x` IN (?) OR `x` IS NULL)', $result->query);
        $this->assertContains(1, $result->bindings);
    }

    public function testNotEqualSingleValue(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notEqual('x', 42)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `x` != ?', $result->query);
        $this->assertContains(42, $result->bindings);
    }

    public function testAndFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::and([Query::greaterThan('age', 18), Query::lessThan('age', 65)])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`age` > ? AND `age` < ?)', $result->query);
    }

    public function testOrFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([Query::equal('role', ['admin']), Query::equal('role', ['editor'])])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`role` IN (?) OR `role` IN (?))', $result->query);
    }

    public function testNestedAndInsideOr(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::or([
                Query::and([Query::greaterThan('age', 18), Query::lessThan('age', 30)]),
                Query::and([Query::greaterThan('score', 80), Query::lessThan('score', 100)]),
            ])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE ((`age` > ? AND `age` < ?) OR (`score` > ? AND `score` < ?))', $result->query);
        $this->assertSame([18, 30, 80, 100], $result->bindings);
    }

    public function testBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::between('age', 18, 65)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` BETWEEN ? AND ?', $result->query);
        $this->assertSame([18, 65], $result->bindings);
    }

    public function testNotBetweenFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notBetween('score', 0, 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` NOT BETWEEN ? AND ?', $result->query);
        $this->assertSame([0, 50], $result->bindings);
    }

    public function testExistsMultipleAttributes(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::exists(['name', 'email'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NOT NULL AND `email` IS NOT NULL)', $result->query);
    }

    public function testNotExistsSingle(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::notExists(['name'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE (`name` IS NULL)', $result->query);
    }

    public function testRawFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('score > ?', [10])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE score > ?', $result->query);
        $this->assertContains(10, $result->bindings);
    }

    public function testRawFilterEmpty(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::raw('')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE 1 = 1', $result->query);
    }

    public function testDottedIdentifier(): void
    {
        $result = (new Builder())
            ->from('t')
            ->select(['events.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `events`.`name` FROM `t`', $result->query);
    }

    public function testMultipleOrderBy(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortAsc('name')
            ->sortDesc('age')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY `name` ASC, `age` DESC', $result->query);
    }

    public function testDistinctWithSelect(): void
    {
        $result = (new Builder())
            ->from('t')
            ->distinct()
            ->select(['name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT `name` FROM `t`', $result->query);
    }

    public function testSumWithAlias(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total` FROM `t`', $result->query);
    }

    public function testMultipleAggregates(): void
    {
        $result = (new Builder())
            ->from('t')
            ->count('*', 'cnt')
            ->sum('amount', 'total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, SUM(`amount`) AS `total` FROM `t`', $result->query);
    }

    public function testIsNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNull('deleted_at')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `deleted_at` IS NULL', $result->query);
    }

    public function testIsNotNullFilter(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::isNotNull('name')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `name` IS NOT NULL', $result->query);
    }

    public function testLessThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThan('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` < ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testLessThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::lessThanEqual('age', 30)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `age` <= ?', $result->query);
        $this->assertSame([30], $result->bindings);
    }

    public function testGreaterThan(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThan('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` > ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testGreaterThanEqual(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::greaterThanEqual('score', 50)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` WHERE `score` >= ?', $result->query);
        $this->assertSame([50], $result->bindings);
    }

    public function testRightJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->rightJoin('b', 'a.id', 'b.a_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `a` RIGHT JOIN `b` ON `a`.`id` = `b`.`a_id`', $result->query);
    }

    public function testCrossJoin(): void
    {
        $result = (new Builder())
            ->from('a')
            ->crossJoin('b')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `a` CROSS JOIN `b`', $result->query);
        $this->assertStringNotContainsString(' ON ', $result->query);
    }

    public function testPrewhereAndFilterBindingOrderVerification(): void
    {
        $result = (new Builder())
            ->from('t')
            ->prewhere([Query::equal('status', ['active'])])
            ->filter([Query::greaterThan('count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(['active', 5], $result->bindings);
    }

    public function testUpdateRawSetAndFilterBindingOrder(): void
    {
        $result = (new Builder())
            ->from('t')
            ->setRaw('count', 'count + ?', [1])
            ->filter([Query::equal('status', ['active'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertSame([1, 'active'], $result->bindings);
    }

    public function testSortRandomUsesRand(): void
    {
        $result = (new Builder())
            ->from('t')
            ->sortRandom()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `t` ORDER BY rand()', $result->query);
    }

    public function testTableAliasClickHouse(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` AS `e`', $result->query);
    }

    public function testTableAliasWithFinal(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->final()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL AS `e`', $result->query);
    }

    public function testTableAliasWithSample(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->sample(0.1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1 AS `e`', $result->query);
    }

    public function testTableAliasWithFinalAndSample(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->final()
            ->sample(0.5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5 AS `e`', $result->query);
    }

    public function testFromSubClickHouse(): void
    {
        $sub = (new Builder())->from('events')->select(['user_id'])->groupBy(['user_id']);
        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT `user_id` FROM (SELECT `user_id` FROM `events` GROUP BY `user_id`) AS `sub`',
            $result->query
        );
    }

    public function testFilterWhereInClickHouse(): void
    {
        $sub = (new Builder())->from('orders')->select(['user_id']);
        $result = (new Builder())
            ->from('users')
            ->filterWhereIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` IN (SELECT `user_id` FROM `orders`)', $result->query);
    }

    public function testOrderByRawClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderByRaw('toDate(`created_at`) ASC')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY toDate(`created_at`) ASC', $result->query);
    }

    public function testGroupByRawClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupByRaw('toDate(`created_at`)')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY toDate(`created_at`)', $result->query);
    }

    public function testCountDistinctClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->countDistinct('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT COUNT(DISTINCT `user_id`) AS `unique_users` FROM `events`',
            $result->query
        );
    }

    public function testJoinWhereClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->joinWhere('users', function (JoinBuilder $join): void {
                $join->on('events.user_id', 'users.id');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`user_id` = `users`.`id`', $result->query);
    }

    public function testFilterExistsClickHouse(): void
    {
        $sub = (new Builder())->from('orders')->select(['id'])->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);
        $result = (new Builder())
            ->from('users')
            ->filterExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE EXISTS (SELECT `id` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)', $result->query);
    }

    public function testExplainClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
    }

    public function testExplainAnalyzeClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->explain(true);

        $this->assertStringStartsWith('EXPLAIN ANALYZE SELECT', $result->query);
    }

    public function testCrossJoinAliasClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->crossJoin('dates', 'd')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` CROSS JOIN `dates` AS `d`', $result->query);
    }

    public function testWhereInSubqueryClickHouse(): void
    {
        $sub = (new Builder())->from('active_users')->select(['id']);

        $result = (new Builder())
            ->from('events')
            ->filterWhereIn('user_id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `user_id` IN (SELECT `id` FROM `active_users`)', $result->query);
    }

    public function testWhereNotInSubqueryClickHouse(): void
    {
        $sub = (new Builder())->from('banned_users')->select(['id']);

        $result = (new Builder())
            ->from('events')
            ->filterWhereNotIn('user_id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `user_id` NOT IN (SELECT `id` FROM `banned_users`)', $result->query);
    }

    public function testSelectSubClickHouse(): void
    {
        $sub = (new Builder())->from('events')->select('COUNT(*)');

        $result = (new Builder())
            ->from('users')
            ->selectSub($sub, 'event_count')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT (SELECT COUNT(*) FROM `events`) AS `event_count` FROM `users`', $result->query);
    }

    public function testFromSubWithGroupByClickHouse(): void
    {
        $sub = (new Builder())->from('events')->select(['user_id'])->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `user_id` FROM (SELECT `user_id` FROM `events` GROUP BY `user_id`) AS `sub`', $result->query);
    }

    public function testFilterNotExistsClickHouse(): void
    {
        $sub = (new Builder())->from('banned')->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterNotExists($sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE NOT EXISTS (SELECT `id` FROM `banned`)', $result->query);
    }

    public function testHavingRawClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['user_id'])
            ->havingRaw('COUNT(*) > ?', [10])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `user_id` HAVING COUNT(*) > ?', $result->query);
        $this->assertSame([10], $result->bindings);
    }

    public function testWhereRawAppendsFragmentAndBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE a = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testWhereRawCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('b', [2])])
            ->whereRaw('a = ?', [1])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `b` IN (?) AND a = ?', $result->query);
        $this->assertContains(1, $result->bindings);
        $this->assertContains(2, $result->bindings);
    }

    public function testTableAliasWithFinalSampleAndAlias(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->final()
            ->sample(0.5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` FINAL SAMPLE 0.5 AS `e`', $result->query);
    }

    public function testJoinWhereLeftJoinClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->joinWhere('users', function (JoinBuilder $join): void {
                $join->on('events.user_id', 'users.id')
                     ->where('users.active', '=', 1);
            }, JoinType::Left)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LEFT JOIN `users` ON `events`.`user_id` = `users`.`id` AND users.active = ?', $result->query);
        $this->assertSame([1], $result->bindings);
    }

    public function testJoinWhereWithAliasClickHouse(): void
    {
        $result = (new Builder())
            ->from('events', 'e')
            ->joinWhere('users', function (JoinBuilder $join): void {
                $join->on('e.user_id', 'u.id');
            }, JoinType::Inner, 'u')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` AS `e` JOIN `users` AS `u` ON `e`.`user_id` = `u`.`id`', $result->query);
    }

    public function testJoinWhereMultipleOnsClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->joinWhere('users', function (JoinBuilder $join): void {
                $join->on('events.user_id', 'users.id')
                     ->on('events.tenant_id', 'users.tenant_id');
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` JOIN `users` ON `events`.`user_id` = `users`.`id` AND `events`.`tenant_id` = `users`.`tenant_id`', $result->query);
    }

    public function testExplainPreservesBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCountDistinctWithoutAliasClickHouse(): void
    {
        $result = (new Builder())
            ->from('events')
            ->countDistinct('user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(DISTINCT `user_id`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMultipleSubqueriesCombined(): void
    {
        $sub1 = (new Builder())->from('active_users')->select(['id']);
        $sub2 = (new Builder())->from('banned_users')->select(['id']);

        $result = (new Builder())
            ->from('events')
            ->filterWhereIn('user_id', $sub1)
            ->filterWhereNotIn('user_id', $sub2)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `user_id` IN (SELECT `id` FROM `active_users`) AND `user_id` NOT IN (SELECT `id` FROM `banned_users`)', $result->query);
    }

    public function testPrewhereWithSubquery(): void
    {
        $sub = (new Builder())->from('active_users')->select(['id']);

        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filterWhereIn('user_id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `user_id` IN (SELECT `id` FROM `active_users`)', $result->query);
    }

    public function testSettingsStillAppear(): void
    {
        $result = (new Builder())
            ->from('events')
            ->settings(['max_threads' => '4'])
            ->orderByRaw('`created_at` DESC')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `created_at` DESC SETTINGS max_threads=4', $result->query);
    }

    public function testExactSimpleSelect(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['name', 'email'])
            ->filter([Query::equal('status', ['active'])])
            ->sortAsc('name')
            ->limit(25)
            ->build();

        $this->assertSame(
            'SELECT `name`, `email` FROM `users` WHERE `status` IN (?) ORDER BY `name` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['active', 25], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSelectWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['id', 'total'])
            ->filter([
                Query::greaterThan('total', 100),
                Query::lessThanEqual('total', 5000),
                Query::equal('status', ['paid', 'shipped']),
                Query::isNotNull('shipped_at'),
            ])
            ->build();

        $this->assertSame(
            'SELECT `id`, `total` FROM `orders` WHERE `total` > ? AND `total` <= ? AND `status` IN (?, ?) AND `shipped_at` IS NOT NULL',
            $result->query
        );
        $this->assertSame([100, 5000, 'paid', 'shipped'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactPrewhere(): void
    {
        $result = (new Builder())
            ->from('hits')
            ->select(['url', 'count'])
            ->prewhere([Query::equal('site_id', [42])])
            ->filter([Query::greaterThan('count', 10)])
            ->build();

        $this->assertSame(
            'SELECT `url`, `count` FROM `hits` PREWHERE `site_id` IN (?) WHERE `count` > ?',
            $result->query
        );
        $this->assertSame([42, 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactFinal(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->select(['user_id', 'event_type'])
            ->build();

        $this->assertSame(
            'SELECT `user_id`, `event_type` FROM `events` FINAL',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSample(): void
    {
        $result = (new Builder())
            ->from('pageviews')
            ->sample(0.1)
            ->select(['url'])
            ->build();

        $this->assertSame(
            'SELECT `url` FROM `pageviews` SAMPLE 0.1',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactFinalSamplePrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->sortDesc('timestamp')
            ->limit(100)
            ->build();

        $this->assertSame(
            'SELECT * FROM `events` FINAL SAMPLE 0.1 PREWHERE `event_type` IN (?) WHERE `count` > ? ORDER BY `timestamp` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['click', 5, 100], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSettings(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->select(['message'])
            ->filter([Query::equal('level', ['error'])])
            ->settings(['max_threads' => '8'])
            ->build();

        $this->assertSame(
            'SELECT `message` FROM `logs` WHERE `level` IN (?) SETTINGS max_threads=8',
            $result->query
        );
        $this->assertSame(['error'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactInsertMultipleRows(): void
    {
        $result = (new Builder())
            ->into('users')
            ->set(['name' => 'Alice', 'age' => 30])
            ->set(['name' => 'Bob', 'age' => 25])
            ->insert();

        $this->assertSame(
            'INSERT INTO `users` (`name`, `age`) VALUES (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['Alice', 30, 'Bob', 25], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAlterTableUpdate(): void
    {
        $result = (new Builder())
            ->from('events')
            ->set(['status' => 'archived'])
            ->filter([Query::equal('year', [2023])])
            ->update();

        $this->assertSame(
            'ALTER TABLE `events` UPDATE `status` = ? WHERE `year` IN (?)',
            $result->query
        );
        $this->assertSame(['archived', 2023], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAlterTableDelete(): void
    {
        $result = (new Builder())
            ->from('events')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([Query::lessThan('created_at', '2023-01-01')])
            ->delete();

        $this->assertSame(
            'ALTER TABLE `events` DELETE WHERE `created_at` < ?',
            $result->query
        );
        $this->assertSame(['2023-01-01'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactMultipleJoins(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->select(['orders.id', 'users.name', 'products.title'])
            ->join('users', 'orders.user_id', 'users.id')
            ->leftJoin('products', 'orders.product_id', 'products.id')
            ->filter([Query::greaterThan('orders.total', 50)])
            ->build();

        $this->assertSame(
            'SELECT `orders`.`id`, `users`.`name`, `products`.`title` FROM `orders` JOIN `users` ON `orders`.`user_id` = `users`.`id` LEFT JOIN `products` ON `orders`.`product_id` = `products`.`id` WHERE `orders`.`total` > ?',
            $result->query
        );
        $this->assertSame([50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactCte(): void
    {
        $cteQuery = (new Builder())
            ->from('events')
            ->select(['user_id'])
            ->filter([Query::equal('event_type', ['purchase'])]);

        $result = (new Builder())
            ->with('buyers', $cteQuery)
            ->from('users')
            ->select(['name', 'email'])
            ->filterWhereIn('id', (new Builder())->from('buyers')->select(['user_id']))
            ->build();

        $this->assertSame(
            'WITH `buyers` AS (SELECT `user_id` FROM `events` WHERE `event_type` IN (?)) SELECT `name`, `email` FROM `users` WHERE `id` IN (SELECT `user_id` FROM `buyers`)',
            $result->query
        );
        $this->assertSame(['purchase'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactUnionAll(): void
    {
        $archive = (new Builder())
            ->from('events_2023')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])]);

        $result = (new Builder())
            ->from('events_2024')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->unionAll($archive)
            ->build();

        $this->assertSame(
            '(SELECT `id`, `name` FROM `events_2024` WHERE `status` IN (?)) UNION ALL (SELECT `id`, `name` FROM `events_2023` WHERE `status` IN (?))',
            $result->query
        );
        $this->assertSame(['active', 'active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactWindowFunction(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['employee_id', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['department_id'], ['-amount'])
            ->build();

        $this->assertSame(
            'SELECT `employee_id`, `amount`, ROW_NUMBER() OVER (PARTITION BY `department_id` ORDER BY `amount` DESC) AS `rn` FROM `sales`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAggregationGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->count('*', 'order_count')
            ->select(['customer_id'])
            ->groupBy(['customer_id'])
            ->having([Query::greaterThan('order_count', 5)])
            ->sortDesc('order_count')
            ->build();

        $this->assertSame(
            'SELECT COUNT(*) AS `order_count`, `customer_id` FROM `orders` GROUP BY `customer_id` HAVING COUNT(*) > ? ORDER BY `order_count` DESC',
            $result->query
        );
        $this->assertSame([5], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSubqueryWhereIn(): void
    {
        $sub = (new Builder())
            ->from('blacklist')
            ->select(['user_id'])
            ->filter([Query::equal('active', [1])]);

        $result = (new Builder())
            ->from('events')
            ->select(['id', 'user_id', 'action'])
            ->filterWhereNotIn('user_id', $sub)
            ->build();

        $this->assertSame(
            'SELECT `id`, `user_id`, `action` FROM `events` WHERE `user_id` NOT IN (SELECT `user_id` FROM `blacklist` WHERE `active` IN (?))',
            $result->query
        );
        $this->assertSame([1], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactExistsSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select('1')
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filterExists($sub)
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE EXISTS (SELECT 1 FROM `orders` WHERE `orders`.`user_id` = `users`.`id`)',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactFromSubquery(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->select(['user_id'])
            ->count('*', 'cnt')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'sub')
            ->select(['user_id', 'cnt'])
            ->filter([Query::greaterThan('cnt', 10)])
            ->build();

        $this->assertSame(
            'SELECT `user_id`, `cnt` FROM (SELECT COUNT(*) AS `cnt`, `user_id` FROM `events` GROUP BY `user_id`) AS `sub` WHERE `cnt` > ?',
            $result->query
        );
        $this->assertSame([10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactSelectSubquery(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->count('*', 'cnt')
            ->filter([Query::raw('`orders`.`user_id` = `users`.`id`')]);

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->selectSub($sub, 'order_count')
            ->build();

        $this->assertSame(
            'SELECT `id`, `name`, (SELECT COUNT(*) AS `cnt` FROM `orders` WHERE `orders`.`user_id` = `users`.`id`) AS `order_count` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactNestedWhereGroups(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name', 'price'])
            ->filter([
                Query::and([
                    Query::or([
                        Query::equal('category', ['electronics']),
                        Query::equal('category', ['books']),
                    ]),
                    Query::greaterThan('price', 10),
                    Query::lessThan('price', 1000),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name`, `price` FROM `products` WHERE ((`category` IN (?) OR `category` IN (?)) AND `price` > ? AND `price` < ?)',
            $result->query
        );
        $this->assertSame(['electronics', 'books', 10, 1000], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactInsertSelect(): void
    {
        $source = (new Builder())
            ->from('events')
            ->select(['user_id', 'event_type'])
            ->filter([Query::equal('year', [2024])]);

        $result = (new Builder())
            ->into('events_archive')
            ->fromSelect(['user_id', 'event_type'], $source)
            ->insertSelect();

        $this->assertSame(
            'INSERT INTO `events_archive` (`user_id`, `event_type`) SELECT `user_id`, `event_type` FROM `events` WHERE `year` IN (?)',
            $result->query
        );
        $this->assertSame([2024], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactDistinctWithOffset(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->distinct()
            ->select(['source', 'level'])
            ->limit(20)
            ->offset(40)
            ->build();

        $this->assertSame(
            'SELECT DISTINCT `source`, `level` FROM `logs` LIMIT ? OFFSET ?',
            $result->query
        );
        $this->assertSame([20, 40], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactCaseInSelect(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'Active')
            ->when('status', Operator::Equal, 'inactive', 'Inactive')
            ->else('Unknown')
            ->alias('status_label');

        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->selectCase($case)
            ->build();

        $this->assertSame(
            'SELECT `id`, `name`, CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `status_label` FROM `users`',
            $result->query
        );
        $this->assertSame(['active', 'Active', 'inactive', 'Inactive', 'Unknown'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactHintSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->filter([Query::equal('type', ['click'])])
            ->settings([
                'max_threads' => '4',
                'max_memory_usage' => '10000000000',
            ])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` WHERE `type` IN (?) SETTINGS max_threads=4, max_memory_usage=10000000000',
            $result->query
        );
        $this->assertSame(['click'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactPrewhereWithJoin(): void
    {
        $result = (new Builder())
            ->from('events')
            ->join('users', 'events.user_id', 'users.id')
            ->select(['events.id', 'users.name'])
            ->prewhere([Query::equal('events.event_type', ['purchase'])])
            ->filter([Query::greaterThan('users.age', 21)])
            ->sortDesc('events.created_at')
            ->limit(50)
            ->build();

        $this->assertSame(
            'SELECT `events`.`id`, `users`.`name` FROM `events` JOIN `users` ON `events`.`user_id` = `users`.`id` PREWHERE `events`.`event_type` IN (?) WHERE `users`.`age` > ? ORDER BY `events`.`created_at` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame(['purchase', 21, 50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedWhenTrue(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(true, fn (Builder $b) => $b->filter([Query::equal('status', ['active'])]))
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedWhenFalse(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->when(false, fn (Builder $b) => $b->filter([Query::equal('status', ['active'])]))
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedExplain(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', ['active'])])
            ->explain();

        $this->assertSame(
            'EXPLAIN SELECT `id`, `name` FROM `events` WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedCursorAfterWithFilters(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->filter([Query::greaterThan('age', 18)])
            ->cursorAfter('abc123')
            ->sortDesc('created_at')
            ->limit(25)
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` WHERE `age` > ? AND `_cursor` > ? ORDER BY `created_at` DESC LIMIT ?',
            $result->query
        );
        $this->assertSame([18, 'abc123', 25], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedCursorBefore(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->cursorBefore('xyz789')
            ->sortAsc('id')
            ->limit(10)
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` WHERE `_cursor` < ? ORDER BY `id` ASC LIMIT ?',
            $result->query
        );
        $this->assertSame(['xyz789', 10], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedMultipleCtes(): void
    {
        $cteA = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->filter([Query::greaterThan('total', 100)]);

        $cteB = (new Builder())
            ->from('customers')
            ->select(['id', 'name'])
            ->filter([Query::equal('tier', ['gold'])]);

        $result = (new Builder())
            ->with('a', $cteA)
            ->with('b', $cteB)
            ->from('a')
            ->select(['customer_id'])
            ->build();

        $this->assertSame(
            'WITH `a` AS (SELECT `customer_id` FROM `orders` WHERE `total` > ?), `b` AS (SELECT `id`, `name` FROM `customers` WHERE `tier` IN (?)) SELECT `customer_id` FROM `a`',
            $result->query
        );
        $this->assertSame([100, 'gold'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedMultipleWindowFunctions(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->select(['employee_id', 'amount'])
            ->selectWindow('ROW_NUMBER()', 'rn', ['department_id'], ['-amount'])
            ->selectWindow('SUM(`amount`)', 'running_total', ['department_id'], ['created_at'])
            ->build();

        $this->assertSame(
            'SELECT `employee_id`, `amount`, ROW_NUMBER() OVER (PARTITION BY `department_id` ORDER BY `amount` DESC) AS `rn`, SUM(`amount`) OVER (PARTITION BY `department_id` ORDER BY `created_at` ASC) AS `running_total` FROM `sales`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedUnionWithOrderAndLimit(): void
    {
        $archive = (new Builder())
            ->from('events_archive')
            ->select(['id', 'name']);

        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->sortAsc('id')
            ->limit(50)
            ->union($archive)
            ->build();

        $this->assertSame(
            '(SELECT `id`, `name` FROM `events` ORDER BY `id` ASC LIMIT ?) UNION (SELECT `id`, `name` FROM `events_archive`)',
            $result->query
        );
        $this->assertSame([50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedDeeplyNestedConditions(): void
    {
        $result = (new Builder())
            ->from('products')
            ->select(['id', 'name'])
            ->filter([
                Query::and([
                    Query::or([
                        Query::and([
                            Query::equal('brand', ['acme']),
                            Query::greaterThan('price', 50),
                        ]),
                        Query::and([
                            Query::equal('brand', ['globex']),
                            Query::lessThan('price', 20),
                        ]),
                    ]),
                    Query::equal('in_stock', [true]),
                ]),
            ])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `products` WHERE (((`brand` IN (?) AND `price` > ?) OR (`brand` IN (?) AND `price` < ?)) AND `in_stock` IN (?))',
            $result->query
        );
        $this->assertSame(['acme', 50, 'globex', 20, true], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedStartsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::startsWith('name', 'John')])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE startsWith(`name`, ?)',
            $result->query
        );
        $this->assertSame(['John'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedEndsWith(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'email'])
            ->filter([Query::endsWith('email', '@example.com')])
            ->build();

        $this->assertSame(
            'SELECT `id`, `email` FROM `users` WHERE endsWith(`email`, ?)',
            $result->query
        );
        $this->assertSame(['@example.com'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedContainsSingle(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['id', 'title'])
            ->filter([Query::containsString('title', ['php'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `title` FROM `articles` WHERE position(`title`, ?) > 0',
            $result->query
        );
        $this->assertSame(['php'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['id', 'title'])
            ->filter([Query::containsString('title', ['php', 'laravel'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `title` FROM `articles` WHERE (position(`title`, ?) > 0 OR position(`title`, ?) > 0)',
            $result->query
        );
        $this->assertSame(['php', 'laravel'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedContainsAll(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['id', 'title'])
            ->filter([Query::containsAll('title', ['php', 'laravel'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `title` FROM `articles` WHERE (position(`title`, ?) > 0 AND position(`title`, ?) > 0)',
            $result->query
        );
        $this->assertSame(['php', 'laravel'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedNotContainsSingle(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['id', 'title'])
            ->filter([Query::notContains('title', ['spam'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `title` FROM `articles` WHERE position(`title`, ?) = 0',
            $result->query
        );
        $this->assertSame(['spam'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedNotContainsMultiple(): void
    {
        $result = (new Builder())
            ->from('articles')
            ->select(['id', 'title'])
            ->filter([Query::notContains('title', ['spam', 'junk'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `title` FROM `articles` WHERE (position(`title`, ?) = 0 AND position(`title`, ?) = 0)',
            $result->query
        );
        $this->assertSame(['spam', 'junk'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedRegex(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->select(['id', 'message'])
            ->filter([Query::regex('message', '^ERROR.*timeout$')])
            ->build();

        $this->assertSame(
            'SELECT `id`, `message` FROM `logs` WHERE match(`message`, ?)',
            $result->query
        );
        $this->assertSame(['^ERROR.*timeout$'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedPrewhereMultipleConditions(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->prewhere([
                Query::equal('event_type', ['click']),
                Query::greaterThan('timestamp', 1000000),
            ])
            ->filter([Query::equal('status', ['active'])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` PREWHERE `event_type` IN (?) AND `timestamp` > ? WHERE `status` IN (?)',
            $result->query
        );
        $this->assertSame(['click', 1000000, 'active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedFinalWithFiltersAndOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->final()
            ->filter([Query::equal('status', ['active'])])
            ->sortDesc('created_at')
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` FINAL WHERE `status` IN (?) ORDER BY `created_at` DESC',
            $result->query
        );
        $this->assertSame(['active'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSampleWithPrewhereAndWhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->sample(0.1)
            ->prewhere([Query::equal('event_type', ['purchase'])])
            ->filter([Query::greaterThan('amount', 50)])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` SAMPLE 0.1 PREWHERE `event_type` IN (?) WHERE `amount` > ?',
            $result->query
        );
        $this->assertSame(['purchase', 50], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedSettingsMultiple(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->settings([
                'max_threads' => '4',
                'max_memory_usage' => '10000000',
            ])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `events` SETTINGS max_threads=4, max_memory_usage=10000000',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedAlterTableUpdateWithSetRaw(): void
    {
        $result = (new Builder())
            ->from('events')
            ->setRaw('views', '`views` + 1')
            ->filter([Query::equal('id', [42])])
            ->update();

        $this->assertSame(
            'ALTER TABLE `events` UPDATE `views` = `views` + 1 WHERE `id` IN (?)',
            $result->query
        );
        $this->assertSame([42], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedAlterTableDeleteWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('events')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([
                Query::equal('status', ['deleted']),
                Query::lessThan('created_at', '2023-01-01'),
            ])
            ->delete();

        $this->assertSame(
            'ALTER TABLE `events` DELETE WHERE `status` IN (?) AND `created_at` < ?',
            $result->query
        );
        $this->assertSame(['deleted', '2023-01-01'], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedEmptyInClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->select(['id', 'name'])
            ->filter([Query::equal('status', [])])
            ->build();

        $this->assertSame(
            'SELECT `id`, `name` FROM `users` WHERE 1 = 0',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testExactAdvancedResetClearsPrewhereAndFinal(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->select(['id', 'name'])
            ->prewhere([Query::equal('event_type', ['click'])])
            ->final()
            ->filter([Query::equal('status', ['active'])]);

        $builder->reset();

        $result = $builder
            ->from('users')
            ->select(['id', 'email'])
            ->build();

        $this->assertSame(
            'SELECT `id`, `email` FROM `users`',
            $result->query
        );
        $this->assertSame([], $result->bindings);
        $this->assertBindingCount($result);
    }

    public function testImplementsConditionalAggregates(): void
    {
        $this->assertInstanceOf(ConditionalAggregates::class, new Builder());
    }

    public function testImplementsTableSampling(): void
    {
        $this->assertInstanceOf(TableSampling::class, new Builder());
    }

    public function testImplementsFullOuterJoins(): void
    {
        $this->assertInstanceOf(FullOuterJoins::class, new Builder());
    }

    public function testHintValidSetting(): void
    {
        $result = (new Builder())
            ->from('events')
            ->hint('max_threads=4')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=4', $result->query);
    }

    public function testHintInvalidThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('events')
            ->hint('DROP TABLE;--')
            ->build();
    }

    public function testSettingsMultiple(): void
    {
        $result = (new Builder())
            ->from('events')
            ->settings(['max_threads' => '4', 'max_memory_usage' => '1000000'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SETTINGS max_threads=4, max_memory_usage=1000000', $result->query);
    }

    public function testSettingsInvalidKeyThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('events')
            ->settings(['1invalid' => '4'])
            ->build();
    }

    public function testSettingsInvalidValueThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())
            ->from('events')
            ->settings(['max_threads' => 'DROP;'])
            ->build();
    }

    public function testTableSampleDelegatesToSample(): void
    {
        $result = (new Builder())
            ->from('events')
            ->tablesample(10.0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1', $result->query);
    }

    public function testCountWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->countWhen('status = ?', 'active_count', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT countIf(status = ?) AS `active_count` FROM `events`', $result->query);
        $this->assertSame(['active'], $result->bindings);
    }

    public function testCountWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->countWhen('status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT countIf(status = ?) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testSumWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sumWhen('amount', 'status = ?', 'active_total', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT sumIf(`amount`, status = ?) AS `active_total` FROM `events`', $result->query);
    }

    public function testSumWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sumWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAvgWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->avgWhen('amount', 'status = ?', 'avg_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT avgIf(`amount`, status = ?) AS `avg_active` FROM `events`', $result->query);
    }

    public function testAvgWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->avgWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMinWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->minWhen('amount', 'status = ?', 'min_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT minIf(`amount`, status = ?) AS `min_active` FROM `events`', $result->query);
    }

    public function testMinWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->minWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMaxWhenWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->maxWhen('amount', 'status = ?', 'max_active', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT maxIf(`amount`, status = ?) AS `max_active` FROM `events`', $result->query);
    }

    public function testMaxWhenWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->maxWhen('amount', 'status = ?', '', 'active')
            ->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testFullOuterJoinBasic(): void
    {
        $result = (new Builder())
            ->from('users')
            ->fullOuterJoin('orders', 'users.id', 'orders.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FULL OUTER JOIN `orders` ON `users`.`id` = `orders`.`user_id`', $result->query);
    }

    public function testFullOuterJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->fullOuterJoin('orders', 'users.id', 'o.user_id', '=', 'o')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` FULL OUTER JOIN `orders` AS `o` ON `users`.`id` = `o`.`user_id`', $result->query);
    }

    public function testSampleValidationZeroThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->from('events')->sample(0.0)->build();
    }

    public function testSampleValidationOneThrows(): void
    {
        $this->expectException(ValidationException::class);

        (new Builder())->from('events')->sample(1.0)->build();
    }

    public function testSettingsWithBuild(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` WHERE `status` IN (?) SETTINGS max_threads=2',
            $result->query
        );
    }

    public function testLikeFallback(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::containsString('name', ['mid'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE position(`name`, ?) > 0', $result->query);
    }

    public function testResetClearsSettings(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->settings(['max_threads' => '4']);

        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('SETTINGS', $result->query);
    }

    public function testCteJoinWhereGroupByHavingOrderLimit(): void
    {
        $cte = (new Builder())
            ->from('raw_events')
            ->select(['user_id', 'amount'])
            ->filter([Query::greaterThan('amount', 0)]);

        $result = (new Builder())
            ->with('filtered', $cte)
            ->from('filtered')
            ->join('users', 'filtered.user_id', 'users.id')
            ->filter([Query::equal('users.status', ['active'])])
            ->sum('filtered.amount', 'total')
            ->groupBy(['users.country'])
            ->having([Query::greaterThan('total', 100)])
            ->sortDesc('total')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `filtered` AS (SELECT `user_id`, `amount` FROM `raw_events` WHERE `amount` > ?) SELECT SUM(`filtered`.`amount`) AS `total` FROM `filtered` JOIN `users` ON `filtered`.`user_id` = `users`.`id` WHERE `users`.`status` IN (?) GROUP BY `users`.`country` HAVING SUM(`filtered`.`amount`) > ? ORDER BY `total` DESC LIMIT ?', $result->query);
    }

    public function testMultipleCTEsWithComplexQuery(): void
    {
        $cte1 = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->sum('total', 'order_total')
            ->groupBy(['customer_id']);

        $cte2 = (new Builder())
            ->from('customers')
            ->select(['id', 'name'])
            ->filter([Query::equal('active', [1])]);

        $result = (new Builder())
            ->with('order_totals', $cte1)
            ->with('active_customers', $cte2)
            ->from('order_totals')
            ->join('active_customers', 'order_totals.customer_id', 'active_customers.id')
            ->filter([Query::greaterThan('order_total', 500)])
            ->sortDesc('order_total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('WITH `order_totals` AS (SELECT SUM(`total`) AS `order_total`, `customer_id` FROM `orders` GROUP BY `customer_id`), `active_customers` AS (SELECT `id`, `name` FROM `customers` WHERE `active` IN (?)) SELECT * FROM `order_totals` JOIN `active_customers` ON `order_totals`.`customer_id` = `active_customers`.`id` WHERE `order_total` > ? ORDER BY `order_total` DESC', $result->query);
    }

    public function testWindowFunctionWithJoinAndWhere(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->join('products', 'sales.product_id', 'products.id')
            ->selectWindow('ROW_NUMBER()', 'rn', ['products.category'], ['sales.amount'])
            ->select(['products.name', 'sales.amount'])
            ->filter([Query::greaterThan('sales.amount', 0)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `products`.`name`, `sales`.`amount`, ROW_NUMBER() OVER (PARTITION BY `products`.`category` ORDER BY `sales`.`amount` ASC) AS `rn` FROM `sales` JOIN `products` ON `sales`.`product_id` = `products`.`id` WHERE `sales`.`amount` > ?', $result->query);
    }

    public function testWindowFunctionWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->selectWindow('SUM(amount)', 'running_total', ['category'], ['date'])
            ->select(['category', 'date'])
            ->groupBy(['category', 'date'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `category`, `date`, SUM(amount) OVER (PARTITION BY `category` ORDER BY `date` ASC) AS `running_total` FROM `sales` GROUP BY `category`, `date`', $result->query);
    }

    public function testMultipleWindowFunctionsInSameQuery(): void
    {
        $result = (new Builder())
            ->from('employees')
            ->selectWindow('ROW_NUMBER()', 'rn', ['department'], ['salary'])
            ->selectWindow('RANK()', 'rnk', ['department'], ['-salary'])
            ->selectWindow('SUM(salary)', 'dept_total', ['department'])
            ->select(['name', 'department', 'salary'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name`, `department`, `salary`, ROW_NUMBER() OVER (PARTITION BY `department` ORDER BY `salary` ASC) AS `rn`, RANK() OVER (PARTITION BY `department` ORDER BY `salary` DESC) AS `rnk`, SUM(salary) OVER (PARTITION BY `department`) AS `dept_total` FROM `employees`', $result->query);
    }

    public function testNamedWindowDefinitionWithSelectWindow(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->window('w', ['category'], ['date'])
            ->selectWindow('SUM(amount)', 'running', null, null, 'w')
            ->selectWindow('ROW_NUMBER()', 'rn', null, null, 'w')
            ->select(['category', 'date', 'amount'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `category`, `date`, `amount`, SUM(amount) OVER `w` AS `running`, ROW_NUMBER() OVER `w` AS `rn` FROM `sales` WINDOW `w` AS (PARTITION BY `category` ORDER BY `date` ASC)', $result->query);
    }

    public function testJoinAggregateGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->count('*', 'order_count')
            ->sum('orders.total', 'revenue')
            ->groupBy(['customers.country'])
            ->having([Query::greaterThan('order_count', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `order_count`, SUM(`orders`.`total`) AS `revenue` FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` GROUP BY `customers`.`country` HAVING COUNT(*) > ?', $result->query);
    }

    public function testSelfJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('employees', 'e')
            ->leftJoin('employees', 'e.manager_id', 'm.id', '=', 'm')
            ->select(['e.name', 'm.name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `e`.`name`, `m`.`name` FROM `employees` AS `e` LEFT JOIN `employees` AS `m` ON `e`.`manager_id` = `m`.`id`', $result->query);
    }

    public function testTripleJoin(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->join('customers', 'orders.customer_id', 'customers.id')
            ->join('products', 'orders.product_id', 'products.id')
            ->leftJoin('categories', 'products.category_id', 'categories.id')
            ->select(['customers.name', 'products.title', 'categories.label'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `customers`.`name`, `products`.`title`, `categories`.`label` FROM `orders` JOIN `customers` ON `orders`.`customer_id` = `customers`.`id` JOIN `products` ON `orders`.`product_id` = `products`.`id` LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`', $result->query);
    }

    public function testUnionAllWithOrderLimit(): void
    {
        $archive = (new Builder())
            ->from('events_archive')
            ->select(['id', 'name', 'ts'])
            ->filter([Query::greaterThan('ts', '2023-01-01')]);

        $result = (new Builder())
            ->from('events')
            ->select(['id', 'name', 'ts'])
            ->unionAll($archive)
            ->sortDesc('ts')
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('(SELECT `id`, `name`, `ts` FROM `events` ORDER BY `ts` DESC LIMIT ?) UNION ALL (SELECT `id`, `name`, `ts` FROM `events_archive` WHERE `ts` > ?)', $result->query);
    }

    public function testMultipleUnionAlls(): void
    {
        $q2 = (new Builder())->from('archive_2023')->filter([Query::equal('year', [2023])]);
        $q3 = (new Builder())->from('archive_2022')->filter([Query::equal('year', [2022])]);
        $q4 = (new Builder())->from('archive_2021')->filter([Query::equal('year', [2021])]);

        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('year', [2024])])
            ->unionAll($q2)
            ->unionAll($q3)
            ->unionAll($q4)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(3, substr_count($result->query, 'UNION ALL'));
        $this->assertSame([2024, 2023, 2022, 2021], $result->bindings);
    }

    public function testSubSelectWithJoinAndWhere(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->select(['customer_id'])
            ->sum('total', 'customer_total')
            ->groupBy(['customer_id']);

        $result = (new Builder())
            ->from('customers')
            ->selectSub($sub, 'order_summary')
            ->filter([Query::equal('active', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT (SELECT SUM(`total`) AS `customer_total`, `customer_id` FROM `orders` GROUP BY `customer_id`) AS `order_summary` FROM `customers` WHERE `active` IN (?)', $result->query);
    }

    public function testFromSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('events')
            ->select(['user_id'])
            ->count('*', 'event_count')
            ->groupBy(['user_id']);

        $result = (new Builder())
            ->fromSub($sub, 'user_events')
            ->filter([Query::greaterThan('event_count', 10)])
            ->sortDesc('event_count')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM (SELECT COUNT(*) AS `event_count`, `user_id` FROM `events` GROUP BY `user_id`) AS `user_events` WHERE `event_count` > ? ORDER BY `event_count` DESC', $result->query);
    }

    public function testFilterWhereInSubqueryWithOtherFilters(): void
    {
        $sub = (new Builder())
            ->from('premium_users')
            ->select(['id'])
            ->filter([Query::equal('tier', ['gold'])]);

        $result = (new Builder())
            ->from('orders')
            ->filterWhereIn('user_id', $sub)
            ->filter([Query::greaterThan('total', 100)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `orders` WHERE `total` > ? AND `user_id` IN (SELECT `id` FROM `premium_users` WHERE `tier` IN (?))', $result->query);
    }

    public function testExistsSubqueryWithFilter(): void
    {
        $sub = (new Builder())
            ->from('orders')
            ->filter([Query::raw('orders.customer_id = customers.id')])
            ->filter([Query::greaterThan('total', 1000)]);

        $result = (new Builder())
            ->from('customers')
            ->filterExists($sub)
            ->filter([Query::equal('active', [1])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `customers` WHERE `active` IN (?) AND EXISTS (SELECT * FROM `orders` WHERE orders.customer_id = customers.id AND `total` > ?)', $result->query);
    }

    public function testConditionalAggregatesCountIfGroupByHaving(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->countWhen('status = ?', 'completed_count', 'completed')
            ->countWhen('status = ?', 'pending_count', 'pending')
            ->groupBy(['region'])
            ->having([Query::greaterThan('completed_count', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT countIf(status = ?) AS `completed_count`, countIf(status = ?) AS `pending_count` FROM `orders` GROUP BY `region` HAVING `completed_count` > ?', $result->query);
    }

    public function testSumIfWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('transactions')
            ->sumWhen('amount', 'type = ?', 'credit_total', 'credit')
            ->sumWhen('amount', 'type = ?', 'debit_total', 'debit')
            ->groupBy(['account_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT sumIf(`amount`, type = ?) AS `credit_total`, sumIf(`amount`, type = ?) AS `debit_total` FROM `transactions` GROUP BY `account_id`', $result->query);
    }

    public function testTableSamplingWithWhereAndOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->tablesample(10.0)
            ->filter([Query::equal('type', ['click'])])
            ->sortDesc('timestamp')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` SAMPLE 0.1 WHERE `type` IN (?) ORDER BY `timestamp` DESC', $result->query);
    }

    public function testSettingsWithComplexQuery(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->filter([Query::greaterThan('count', 10)])
            ->count('*', 'total')
            ->groupBy(['users.country'])
            ->settings(['max_threads' => '4', 'max_memory_usage' => '1000000000'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` FINAL JOIN `users` ON `events`.`uid` = `users`.`id` WHERE `count` > ? GROUP BY `users`.`country` SETTINGS max_threads=4, max_memory_usage=1000000000', $result->query);
    }

    public function testInsertSelectFromSubquery(): void
    {
        $source = (new Builder())
            ->from('staging')
            ->select(['name', 'email'])
            ->filter([Query::equal('imported', [0])]);

        $result = (new Builder())
            ->into('users')
            ->fromSelect(['name', 'email'], $source)
            ->insertSelect();
        $this->assertBindingCount($result);

        $this->assertSame('INSERT INTO `users` (`name`, `email`) SELECT `name`, `email` FROM `staging` WHERE `imported` IN (?)', $result->query);
    }

    public function testCaseExpressionWithAggregate(): void
    {
        $case = (new CaseExpression())
            ->when('status', Operator::Equal, 'active', 'active')
            ->when('status', Operator::Equal, 'inactive', 'inactive')
            ->else('unknown')
            ->alias('status_label');

        $result = (new Builder())
            ->from('users')
            ->selectCase($case)
            ->count('*', 'total')
            ->groupBy(['status'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total`, CASE WHEN `status` = ? THEN ? WHEN `status` = ? THEN ? ELSE ? END AS `status_label` FROM `users` GROUP BY `status`', $result->query);
    }

    public function testExplainWithComplexQuery(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->join('users', 'events.uid', 'users.id')
            ->filter([Query::greaterThan('count', 5)])
            ->count('*', 'total')
            ->groupBy(['users.country'])
            ->having([Query::greaterThan('total', 10)])
            ->explain();

        $this->assertStringStartsWith('EXPLAIN SELECT', $result->query);
        $this->assertSame('EXPLAIN SELECT COUNT(*) AS `total` FROM `events` FINAL JOIN `users` ON `events`.`uid` = `users`.`id` WHERE `count` > ? GROUP BY `users`.`country` HAVING COUNT(*) > ?', $result->query);
        $this->assertTrue($result->readOnly);
    }

    public function testNestedOrAndFilters(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::or([
                    Query::and([
                        Query::equal('status', ['active']),
                        Query::greaterThan('age', 18),
                    ]),
                    Query::and([
                        Query::lessThan('score', 50),
                        Query::notEqual('role', 'admin'),
                    ]),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE ((`status` IN (?) AND `age` > ?) OR (`score` < ? AND `role` != ?))', $result->query);
    }

    public function testTripleNestedLogicalOperators(): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([
                Query::and([
                    Query::or([
                        Query::and([
                            Query::equal('a', [1]),
                            Query::equal('b', [2]),
                        ]),
                        Query::equal('c', [3]),
                    ]),
                    Query::greaterThan('d', 4),
                ]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([1, 2, 3, 4], $result->bindings);
    }

    public function testIsNullIsNotNullEqualCombined(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::isNull('deleted_at'),
                Query::isNotNull('email'),
                Query::equal('status', ['active']),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL AND `email` IS NOT NULL AND `status` IN (?)', $result->query);
    }

    public function testBetweenAndNotEqualCombined(): void
    {
        $result = (new Builder())
            ->from('products')
            ->filter([
                Query::between('price', 10, 100),
                Query::notEqual('status', 'discontinued'),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `products` WHERE `price` BETWEEN ? AND ? AND `status` != ?', $result->query);
        $this->assertSame([10, 100, 'discontinued'], $result->bindings);
    }

    public function testMultipleSortDirectionsInterleaved(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortAsc('category')
            ->sortDesc('priority')
            ->sortAsc('name')
            ->sortDesc('created_at')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(
            'SELECT * FROM `events` ORDER BY `category` ASC, `priority` DESC, `name` ASC, `created_at` DESC',
            $result->query
        );
    }

    public function testDistinctWithCount(): void
    {
        $result = (new Builder())
            ->from('events')
            ->distinct()
            ->countDistinct('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT DISTINCT COUNT(DISTINCT `user_id`) AS `unique_users` FROM `events`', $result->query);
    }

    public function testGroupByMultipleColumns(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'total')
            ->groupBy(['region', 'category', 'year'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total` FROM `events` GROUP BY `region`, `category`, `year`', $result->query);
    }

    public function testEmptySelect(): void
    {
        $result = (new Builder())
            ->from('events')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events`', $result->query);
    }

    public function testLimitOneOffsetZero(): void
    {
        $result = (new Builder())
            ->from('events')
            ->limit(1)
            ->offset(0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LIMIT ? OFFSET ?', $result->query);
        $this->assertSame([1, 0], $result->bindings);
    }

    public function testCloneAndModify(): void
    {
        $original = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])]);

        $cloned = $original->clone();
        $cloned->filter([Query::greaterThan('count', 10)]);

        $originalResult = $original->build();
        $clonedResult = $cloned->build();
        $this->assertBindingCount($originalResult);
        $this->assertBindingCount($clonedResult);

        $this->assertStringNotContainsString('`count`', $originalResult->query);
        $this->assertSame('SELECT * FROM `events` WHERE `status` IN (?) AND `count` > ?', $clonedResult->query);
    }

    public function testResetAndRebuild(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->settings(['max_threads' => '4']);

        $builder->build();
        $builder->reset();

        $result = $builder
            ->from('logs')
            ->filter([Query::equal('level', ['error'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE `level` IN (?)', $result->query);
        $this->assertSame(['error'], $result->bindings);
    }

    public function testReadOnlyFlagOnBuild(): void
    {
        $result = (new Builder())
            ->from('events')
            ->build();
        $this->assertBindingCount($result);

        $this->assertTrue($result->readOnly);
    }

    public function testReadOnlyFlagOnInsert(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['name' => 'test'])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertFalse($result->readOnly);
    }

    public function testBindingOrderCteWhereHaving(): void
    {
        $cte = (new Builder())
            ->from('raw_data')
            ->filter([Query::greaterThan('amount', 0)]);

        $result = (new Builder())
            ->with('filtered', $cte)
            ->from('filtered')
            ->filter([Query::equal('status', ['active'])])
            ->count('*', 'cnt')
            ->groupBy(['region'])
            ->having([Query::greaterThan('cnt', 5)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame(0, $result->bindings[0]);
        $this->assertSame('active', $result->bindings[1]);
        $this->assertSame(5, $result->bindings[2]);
    }

    public function testContainsWithSpecialCharacters(): void
    {
        $result = (new Builder())
            ->from('logs')
            ->filter([Query::containsString('message', ["it's a test"])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `logs` WHERE position(`message`, ?) > 0', $result->query);
        $this->assertSame(["it's a test"], $result->bindings);
    }

    public function testStartsWithSqlWildcardChars(): void
    {
        $result = (new Builder())
            ->from('files')
            ->filter([Query::startsWith('path', '/tmp/%test_')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `files` WHERE startsWith(`path`, ?)', $result->query);
        $this->assertSame(['/tmp/%test_'], $result->bindings);
    }

    public function testMultipleSetForMultiRowInsert(): void
    {
        $result = (new Builder())
            ->into('events')
            ->set(['name' => 'a', 'value' => 1])
            ->set(['name' => 'b', 'value' => 2])
            ->set(['name' => 'c', 'value' => 3])
            ->insert();
        $this->assertBindingCount($result);

        $this->assertSame(
            'INSERT INTO `events` (`name`, `value`) VALUES (?, ?), (?, ?), (?, ?)',
            $result->query
        );
        $this->assertSame(['a', 1, 'b', 2, 'c', 3], $result->bindings);
    }

    public function testBooleanFilterValues(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([
                Query::equal('active', [true]),
                Query::equal('deleted', [false]),
            ])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([true, false], $result->bindings);
    }

    public function testNullFilterViaRaw(): void
    {
        $result = (new Builder())
            ->from('users')
            ->filter([Query::isNull('email')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `email` IS NULL', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testBeforeBuildCallback(): void
    {
        $callbackCalled = false;
        $result = (new Builder())
            ->from('events')
            ->beforeBuild(function (Builder $b) use (&$callbackCalled) {
                $callbackCalled = true;
                $b->filter([Query::equal('injected', ['yes'])]);
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertTrue($callbackCalled);
        $this->assertSame('SELECT * FROM `events` WHERE `injected` IN (?)', $result->query);
    }

    public function testAfterBuildCallback(): void
    {
        $capturedQuery = '';
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->afterBuild(function (Statement $r) use (&$capturedQuery) {
                $capturedQuery = 'callback_executed';
                return $r;
            })
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('callback_executed', $capturedQuery);
    }

    public function testFullOuterJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('left_table')
            ->fullOuterJoin('right_table', 'left_table.id', 'right_table.ref_id')
            ->filter([Query::isNotNull('left_table.id')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `left_table` FULL OUTER JOIN `right_table` ON `left_table`.`id` = `right_table`.`ref_id` WHERE `left_table`.`id` IS NOT NULL', $result->query);
    }

    public function testAvgIfWithAlias(): void
    {
        $result = (new Builder())
            ->from('orders')
            ->avgWhen('amount', 'region = ?', 'avg_east', 'east')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT avgIf(`amount`, region = ?) AS `avg_east` FROM `orders`', $result->query);
        $this->assertSame(['east'], $result->bindings);
    }

    public function testMinIfWithAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->minWhen('price', 'category = ?', 'min_electronics', 'electronics')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT minIf(`price`, category = ?) AS `min_electronics` FROM `products`', $result->query);
    }

    public function testMaxIfWithAlias(): void
    {
        $result = (new Builder())
            ->from('products')
            ->maxWhen('price', 'in_stock = ?', 'max_available', 1)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT maxIf(`price`, in_stock = ?) AS `max_available` FROM `products`', $result->query);
    }

    public function testSampleValidationZeroBoundary(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(0.0);
    }

    public function testSampleValidationOneBoundary(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->sample(1.0);
    }

    public function testSettingsInvalidKeyThrowsOnSpecialChars(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->settings(['invalid key!' => '1']);
    }

    public function testSettingsInvalidValueThrowsOnSqlInjection(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->settings(['max_threads' => 'DROP TABLE']);
    }

    public function testUpdateWithoutWhereThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ClickHouse UPDATE requires a WHERE clause.');

        (new Builder())
            ->from('events')
            ->set(['name' => 'updated'])
            ->update();
    }

    public function testDeleteWithoutWhereThrowsValidation(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ClickHouse DELETE requires a WHERE clause.');

        (new Builder())
            ->from('events')
            ->delete();
    }

    public function testUpdateAlterTableWithMultipleAssignments(): void
    {
        $result = (new Builder())
            ->from('events')
            ->set(['status' => 'archived', 'updated_at' => '2024-06-01'])
            ->filter([Query::equal('status', ['old'])])
            ->update();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('ALTER TABLE `events` UPDATE', $result->query);
        $this->assertSame('ALTER TABLE `events` UPDATE `status` = ?, `updated_at` = ? WHERE `status` IN (?)', $result->query);
    }

    public function testDeleteAlterTableWithMultipleFilters(): void
    {
        $result = (new Builder())
            ->from('events')
            ->deleteMode(Builder::DELETE_MODE_MUTATION)
            ->filter([
                Query::lessThan('created_at', '2020-01-01'),
                Query::equal('archived', [1]),
            ])
            ->delete();
        $this->assertBindingCount($result);

        $this->assertStringStartsWith('ALTER TABLE `events` DELETE', $result->query);
        $this->assertSame('ALTER TABLE `events` DELETE WHERE `created_at` < ? AND `archived` IN (?)', $result->query);
    }

    public function testPrewhereWithSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->prewhere([Query::equal('type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` PREWHERE `type` IN (?) WHERE `count` > ? SETTINGS max_threads=2', $result->query);
    }

    public function testHintValidation(): void
    {
        $this->expectException(ValidationException::class);
        (new Builder())->from('t')->hint('DROP TABLE; --');
    }

    public function testSelectRawWithBindings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select('toDate(?) AS ref_date', ['2024-01-01'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT toDate(?) AS ref_date FROM `events`', $result->query);
        $this->assertSame(['2024-01-01'], $result->bindings);
    }

    public function testFilterWhereNotInSubquery(): void
    {
        $sub = (new Builder())
            ->from('blocked_users')
            ->select(['id']);

        $result = (new Builder())
            ->from('users')
            ->filterWhereNotIn('id', $sub)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `users` WHERE `id` NOT IN (SELECT `id` FROM `blocked_users`)', $result->query);
    }

    public function testImplementsLimitBy(): void
    {
        $this->assertInstanceOf(LimitBy::class, new Builder());
    }

    public function testImplementsArrayJoins(): void
    {
        $this->assertInstanceOf(ArrayJoins::class, new Builder());
    }

    public function testImplementsAsofJoins(): void
    {
        $this->assertInstanceOf(AsofJoins::class, new Builder());
    }

    public function testImplementsWithFill(): void
    {
        $this->assertInstanceOf(WithFill::class, new Builder());
    }

    public function testImplementsGroupByModifiers(): void
    {
        $builder = new Builder();

        $this->assertInstanceOf(Rollup::class, $builder);
        $this->assertInstanceOf(Cube::class, $builder);
        $this->assertInstanceOf(Totals::class, $builder);
    }

    public function testImplementsApproximateAggregates(): void
    {
        $this->assertInstanceOf(ApproximateAggregates::class, new Builder());
    }

    public function testImplementsStringAggregates(): void
    {
        $this->assertInstanceOf(StringAggregates::class, new Builder());
    }

    public function testImplementsStatisticalAggregates(): void
    {
        $this->assertInstanceOf(StatisticalAggregates::class, new Builder());
    }

    public function testImplementsBitwiseAggregates(): void
    {
        $this->assertInstanceOf(BitwiseAggregates::class, new Builder());
    }

    public function testLimitByBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['user_id', 'event_type'])
            ->sortDesc('timestamp')
            ->limitBy(3, ['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `user_id`, `event_type` FROM `events` ORDER BY `timestamp` DESC LIMIT ? BY `user_id`', $result->query);
    }

    public function testLimitByMultipleColumns(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('timestamp')
            ->limitBy(5, ['user_id', 'event_type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `timestamp` DESC LIMIT ? BY `user_id`, `event_type`', $result->query);
    }

    public function testLimitByWithLimit(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('timestamp')
            ->limitBy(3, ['user_id'])
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `timestamp` DESC LIMIT ? BY `user_id` LIMIT ?', $result->query);
        // LIMIT BY count binding should come before the final LIMIT binding
        $limitByIdx = \array_search(3, $result->bindings, true);
        $limitIdx = \array_search(100, $result->bindings, true);
        $this->assertNotFalse($limitByIdx);
        $this->assertNotFalse($limitIdx);
        $this->assertLessThan($limitIdx, $limitByIdx);
    }

    public function testLimitByWithLimitAndOffset(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('timestamp')
            ->limitBy(3, ['user_id'])
            ->limit(100)
            ->offset(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `timestamp` DESC LIMIT ? BY `user_id` LIMIT ? OFFSET ?', $result->query);
    }

    public function testLimitByWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('created_at')
            ->limitBy(2, ['category'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `created_at` DESC LIMIT ? BY `category`', $result->query);
        $orderPos = \strpos($result->query, 'ORDER BY');
        $limitByPos = \strpos($result->query, 'LIMIT ? BY');
        $this->assertLessThan($limitByPos, $orderPos);
    }

    public function testLimitByWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->sortDesc('timestamp')
            ->limitBy(3, ['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `status` IN (?) ORDER BY `timestamp` DESC LIMIT ? BY `user_id`', $result->query);
    }

    public function testLimitByWithSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->limitBy(3, ['user_id'])
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LIMIT ? BY `user_id` SETTINGS max_threads=2', $result->query);
        $limitByPos = \strpos($result->query, 'LIMIT ? BY');
        $settingsPos = \strpos($result->query, 'SETTINGS');
        $this->assertLessThan($settingsPos, $limitByPos);
    }

    public function testLimitByFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->limitBy(1, ['a']));
    }

    public function testLimitByReset(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->limitBy(3, ['user_id']);

        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('LIMIT ? BY', $result->query);
    }

    public function testArrayJoinBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags`', $result->query);
    }

    public function testArrayJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag`', $result->query);
    }

    public function testLeftArrayJoinBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->leftArrayJoin('tags')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LEFT ARRAY JOIN `tags`', $result->query);
    }

    public function testLeftArrayJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->leftArrayJoin('tags', 'tag')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LEFT ARRAY JOIN `tags` AS `tag`', $result->query);
    }

    public function testArrayJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->filter([Query::equal('tag', ['important'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag` WHERE `tag` IN (?)', $result->query);
        $arrayJoinPos = \strpos($result->query, 'ARRAY JOIN');
        $wherePos = \strpos($result->query, 'WHERE');
        $this->assertLessThan($wherePos, $arrayJoinPos);
    }

    public function testArrayJoinWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->prewhere([Query::equal('status', ['active'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag` PREWHERE `status` IN (?)', $result->query);
        $arrayJoinPos = \strpos($result->query, 'ARRAY JOIN');
        $prewherePos = \strpos($result->query, 'PREWHERE');
        $this->assertLessThan($prewherePos, $arrayJoinPos);
    }

    public function testArrayJoinWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->count('*', 'cnt')
            ->groupBy(['tag'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` ARRAY JOIN `tags` AS `tag` GROUP BY `tag`', $result->query);
    }

    public function testMultipleArrayJoins(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->leftArrayJoin('metadata', 'meta')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag` LEFT ARRAY JOIN `metadata` AS `meta`', $result->query);
    }

    public function testArrayJoinFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->arrayJoin('a'));
        $this->assertSame($builder, $builder->leftArrayJoin('b'));
    }

    public function testArrayJoinReset(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->arrayJoin('tags');

        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('ARRAY JOIN', $result->query);
    }

    public function testAsofJoinBasic(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts`', $result->query);
    }

    public function testAsofJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'q.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'q.ts',
                'q',
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` AS `q` ON `trades`.`symbol` = `q`.`symbol` AND `trades`.`ts` >= `q`.`ts`', $result->query);
    }

    public function testAsofLeftJoinBasic(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofLeftJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF LEFT JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts`', $result->query);
    }

    public function testAsofLeftJoinWithAlias(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofLeftJoin(
                'quotes',
                ['trades.symbol' => 'q.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'q.ts',
                'q',
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF LEFT JOIN `quotes` AS `q` ON `trades`.`symbol` = `q`.`symbol` AND `trades`.`ts` >= `q`.`ts`', $result->query);
    }

    public function testAsofJoinWithFilter(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->filter([Query::equal('trades.symbol', ['AAPL'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts` WHERE `trades`.`symbol` IN (?)', $result->query);
        $joinPos = \strpos($result->query, 'ASOF JOIN');
        $wherePos = \strpos($result->query, 'WHERE');
        $this->assertLessThan($wherePos, $joinPos);
    }

    public function testAsofJoinFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->asofJoin('q', ['t.k' => 'q.k'], 't.ts', AsofOperator::GreaterThanEqual, 'q.ts'));
        $this->assertSame($builder, $builder->asofLeftJoin('r', ['t.k' => 'r.k'], 't.ts', AsofOperator::LessThanEqual, 'r.ts'));
    }

    public function testAsofJoinReset(): void
    {
        $builder = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            );

        $builder->build();
        $builder->reset();

        $result = $builder->from('trades')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('ASOF', $result->query);
    }

    public function testOrderWithFillBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('date')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `date` ASC WITH FILL', $result->query);
    }

    public function testOrderWithFillDesc(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('date', 'DESC')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `date` DESC WITH FILL', $result->query);
    }

    public function testOrderWithFillFrom(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('value', 'ASC', 0)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `value` ASC WITH FILL FROM ?', $result->query);
        $this->assertContains(0, $result->bindings);
    }

    public function testOrderWithFillFromTo(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('value', 'ASC', 0, 100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `value` ASC WITH FILL FROM ? TO ?', $result->query);
        $this->assertContains(0, $result->bindings);
        $this->assertContains(100, $result->bindings);
    }

    public function testOrderWithFillFromToStep(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('value', 'ASC', 0, 100, 10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `value` ASC WITH FILL FROM ? TO ? STEP ?', $result->query);
        $this->assertSame([0, 100, 10], $result->bindings);
    }

    public function testOrderWithFillWithRegularSort(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('date', 'ASC', '2024-01-01', '2024-12-31')
            ->sortDesc('count')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `date` ASC WITH FILL FROM ? TO ?, `count` DESC', $result->query);
    }

    public function testOrderWithFillFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->orderWithFill('a'));
    }

    public function testWithTotalsBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['event_type'])
            ->withTotals()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `event_type` WITH TOTALS', $result->query);
    }

    public function testWithRollupBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['year', 'month'])
            ->withRollup()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `year`, `month` WITH ROLLUP', $result->query);
    }

    public function testWithCubeBasic(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['city', 'category'])
            ->withCube()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `city`, `category` WITH CUBE', $result->query);
    }

    public function testWithTotalsWithHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['event_type'])
            ->withTotals()
            ->having([Query::greaterThan('cnt', 10)])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `event_type` WITH TOTALS HAVING COUNT(*) > ?', $result->query);
        $groupByPos = \strpos($result->query, 'GROUP BY');
        $totalsPos = \strpos($result->query, 'WITH TOTALS');
        $havingPos = \strpos($result->query, 'HAVING');
        $this->assertLessThan($totalsPos, $groupByPos);
        $this->assertLessThan($havingPos, $totalsPos);
    }

    public function testWithTotalsWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['event_type'])
            ->withTotals()
            ->sortDesc('cnt')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `event_type` WITH TOTALS ORDER BY `cnt` DESC', $result->query);
        $totalsPos = \strpos($result->query, 'WITH TOTALS');
        $orderByPos = \strpos($result->query, 'ORDER BY');
        $this->assertLessThan($orderByPos, $totalsPos);
    }

    public function testGroupByModifierFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->withTotals());
        $builder->reset();
        $this->assertSame($builder, $builder->from('t')->withRollup());
        $builder->reset();
        $this->assertSame($builder, $builder->from('t')->withCube());
    }

    public function testGroupByModifierReset(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['event_type'])
            ->withTotals();

        $builder->build();
        $builder->reset();

        $result = $builder->from('events')->build();
        $this->assertBindingCount($result);

        $this->assertStringNotContainsString('WITH TOTALS', $result->query);
        $this->assertStringNotContainsString('WITH ROLLUP', $result->query);
        $this->assertStringNotContainsString('WITH CUBE', $result->query);
    }

    public function testWithTotalsWithLimitBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['event_type', 'user_id'])
            ->withTotals()
            ->sortDesc('cnt')
            ->limitBy(3, ['user_id'])
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `event_type`, `user_id` WITH TOTALS ORDER BY `cnt` DESC LIMIT ? BY `user_id` LIMIT ?', $result->query);
    }

    public function testQuantileWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantile(0.95, 'latency', 'p95')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT quantile(0.95)(`latency`) AS `p95` FROM `events`', $result->query);
    }

    public function testQuantileWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantile(0.5, 'latency')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT quantile(0.5)(`latency`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testQuantileExactWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantileExact(0.99, 'response_time', 'p99_exact')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT quantileExact(0.99)(`response_time`) AS `p99_exact` FROM `events`', $result->query);
    }

    public function testQuantileExactWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantileExact(0.5, 'latency')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT quantileExact(0.5)(`latency`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testMedianWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->median('latency', 'med')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT median(`latency`) AS `med` FROM `events`', $result->query);
    }

    public function testMedianWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->median('latency')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT median(`latency`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testUniqWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniq('user_id', 'unique_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniq(`user_id`) AS `unique_users` FROM `events`', $result->query);
    }

    public function testUniqWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniq('user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniq(`user_id`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testUniqExactWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniqExact('user_id', 'exact_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniqExact(`user_id`) AS `exact_users` FROM `events`', $result->query);
    }

    public function testUniqExactWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniqExact('user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniqExact(`user_id`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testUniqCombinedWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniqCombined('user_id', 'approx_users')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniqCombined(`user_id`) AS `approx_users` FROM `events`', $result->query);
    }

    public function testUniqCombinedWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniqCombined('user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniqCombined(`user_id`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testArgMinWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->argMin('url', 'timestamp', 'first_url')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT argMin(`url`, `timestamp`) AS `first_url` FROM `events`', $result->query);
    }

    public function testArgMinWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->argMin('url', 'timestamp')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT argMin(`url`, `timestamp`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testArgMaxWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->argMax('url', 'timestamp', 'last_url')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT argMax(`url`, `timestamp`) AS `last_url` FROM `events`', $result->query);
    }

    public function testArgMaxWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->argMax('url', 'timestamp')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT argMax(`url`, `timestamp`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testTopKWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->topK(10, 'user_agent', 'top_agents')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT topK(10)(`user_agent`) AS `top_agents` FROM `events`', $result->query);
    }

    public function testTopKWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->topK(5, 'path')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT topK(5)(`path`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testTopKWeightedWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->topKWeighted(10, 'path', 'visits', 'top_paths')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT topKWeighted(10)(`path`, `visits`) AS `top_paths` FROM `events`', $result->query);
    }

    public function testTopKWeightedWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->topKWeighted(3, 'url', 'weight')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT topKWeighted(3)(`url`, `weight`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAnyValueWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->anyValue('name', 'sample_name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT any(`name`) AS `sample_name` FROM `events`', $result->query);
    }

    public function testAnyValueWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->anyValue('name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT any(`name`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testAnyLastValueWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->anyLastValue('name', 'last_name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT anyLast(`name`) AS `last_name` FROM `events`', $result->query);
    }

    public function testAnyLastValueWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->anyLastValue('name')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT anyLast(`name`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testGroupUniqArrayWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupUniqArray('tag', 'unique_tags')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupUniqArray(`tag`) AS `unique_tags` FROM `events` GROUP BY `user_id`', $result->query);
    }

    public function testGroupUniqArrayWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupUniqArray('tag')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupUniqArray(`tag`) FROM `events` GROUP BY `user_id`', $result->query);
    }

    public function testGroupArrayMovingAvgWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupArrayMovingAvg('value', 'moving_avg')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupArrayMovingAvg(`value`) AS `moving_avg` FROM `events`', $result->query);
    }

    public function testGroupArrayMovingAvgWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupArrayMovingAvg('value')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupArrayMovingAvg(`value`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testGroupArrayMovingSumWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupArrayMovingSum('value', 'running_total')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupArrayMovingSum(`value`) AS `running_total` FROM `events`', $result->query);
    }

    public function testGroupArrayMovingSumWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupArrayMovingSum('value')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupArrayMovingSum(`value`) FROM `events`', $result->query);
        $this->assertStringNotContainsString(' AS ', $result->query);
    }

    public function testQuantileWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->quantile(0.95, 'latency', 'p95')
            ->groupBy(['endpoint'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT quantile(0.95)(`latency`) AS `p95` FROM `events` GROUP BY `endpoint`', $result->query);
    }

    public function testMultipleAggregatesCombined(): void
    {
        $result = (new Builder())
            ->from('requests')
            ->quantile(0.5, 'latency', 'p50')
            ->quantile(0.95, 'latency', 'p95')
            ->quantile(0.99, 'latency', 'p99')
            ->uniq('user_id', 'unique_users')
            ->count('*', 'total')
            ->groupBy(['endpoint'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total`, quantile(0.5)(`latency`) AS `p50`, quantile(0.95)(`latency`) AS `p95`, quantile(0.99)(`latency`) AS `p99`, uniq(`user_id`) AS `unique_users` FROM `requests` GROUP BY `endpoint`', $result->query);
    }

    public function testArgMinWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->argMin('url', 'timestamp', 'first_url')
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT argMin(`url`, `timestamp`) AS `first_url` FROM `events` GROUP BY `user_id`', $result->query);
    }

    public function testTopKWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->topK(10, 'user_agent', 'top_agents')
            ->filter([Query::greaterThan('timestamp', '2024-01-01')])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT topK(10)(`user_agent`) AS `top_agents` FROM `events` WHERE `timestamp` > ?', $result->query);
    }

    public function testClickHouseAggregateFluentChaining(): void
    {
        $builder = new Builder();
        $this->assertSame($builder, $builder->from('t')->quantile(0.5, 'a'));
        $this->assertSame($builder, $builder->quantileExact(0.5, 'a'));
        $this->assertSame($builder, $builder->median('a'));
        $this->assertSame($builder, $builder->uniq('a'));
        $this->assertSame($builder, $builder->uniqExact('a'));
        $this->assertSame($builder, $builder->uniqCombined('a'));
        $this->assertSame($builder, $builder->argMin('a', 'b'));
        $this->assertSame($builder, $builder->argMax('a', 'b'));
        $this->assertSame($builder, $builder->topK(5, 'a'));
        $this->assertSame($builder, $builder->topKWeighted(5, 'a', 'b'));
        $this->assertSame($builder, $builder->anyValue('a'));
        $this->assertSame($builder, $builder->anyLastValue('a'));
        $this->assertSame($builder, $builder->groupUniqArray('a'));
        $this->assertSame($builder, $builder->groupArrayMovingAvg('a'));
        $this->assertSame($builder, $builder->groupArrayMovingSum('a'));
    }

    public function testAllFeaturesCombined(): void
    {
        $result = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.1)
            ->arrayJoin('tags', 'tag')
            ->prewhere([Query::equal('event_type', ['click'])])
            ->filter([Query::greaterThan('count', 5)])
            ->count('*', 'total')
            ->quantile(0.95, 'latency', 'p95')
            ->groupBy(['tag'])
            ->withTotals()
            ->having([Query::greaterThan('total', 10)])
            ->sortDesc('total')
            ->limitBy(3, ['tag'])
            ->limit(50)
            ->settings(['max_threads' => '4'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `total`, quantile(0.95)(`latency`) AS `p95` FROM `events` FINAL SAMPLE 0.1 ARRAY JOIN `tags` AS `tag` PREWHERE `event_type` IN (?) WHERE `count` > ? GROUP BY `tag` WITH TOTALS HAVING COUNT(*) > ? ORDER BY `total` DESC LIMIT ? BY `tag` LIMIT ? SETTINGS max_threads=4', $result->query);
    }

    public function testLimitByNoFinalLimit(): void
    {
        $result = (new Builder())
            ->from('events')
            ->sortDesc('timestamp')
            ->limitBy(5, ['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `timestamp` DESC LIMIT ? BY `user_id`', $result->query);
        $this->assertSame([5], $result->bindings);
    }

    public function testArrayJoinWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->sortAsc('tag')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag` ORDER BY `tag` ASC', $result->query);
        $arrayPos = \strpos($result->query, 'ARRAY JOIN');
        $orderPos = \strpos($result->query, 'ORDER BY');
        $this->assertLessThan($orderPos, $arrayPos);
    }

    public function testAsofJoinWithPrewhere(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->prewhere([Query::equal('trades.exchange', ['NYSE'])])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts` PREWHERE `trades`.`exchange` IN (?)', $result->query);
    }

    public function testWithRollupWithOrderBy(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->sum('amount', 'total_amount')
            ->groupBy(['region', 'product'])
            ->withRollup()
            ->sortDesc('total_amount')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total_amount` FROM `sales` GROUP BY `region`, `product` WITH ROLLUP ORDER BY `total_amount` DESC', $result->query);
    }

    public function testWithCubeWithLimit(): void
    {
        $result = (new Builder())
            ->from('sales')
            ->sum('amount', 'total')
            ->groupBy(['region', 'product'])
            ->withCube()
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT SUM(`amount`) AS `total` FROM `sales` GROUP BY `region`, `product` WITH CUBE LIMIT ?', $result->query);
    }

    public function testOrderWithFillWithLimitBy(): void
    {
        $result = (new Builder())
            ->from('metrics')
            ->orderWithFill('date', 'ASC', '2024-01-01', '2024-12-31', 1)
            ->limitBy(10, ['metric_name'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `metrics` ORDER BY `date` ASC WITH FILL FROM ? TO ? STEP ? LIMIT ? BY `metric_name`', $result->query);
    }

    public function testGroupByModifierOverwrite(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->withTotals()
            ->withRollup();

        $result = $builder->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `type` WITH ROLLUP', $result->query);
        $this->assertStringNotContainsString('WITH TOTALS', $result->query);
    }

    public function testLimitByBindingCount(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->sortDesc('timestamp')
            ->limitBy(3, ['user_id'])
            ->limit(100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertCount(3, $result->bindings);
        $this->assertSame('active', $result->bindings[0]);
        $this->assertSame(3, $result->bindings[1]);
        $this->assertSame(100, $result->bindings[2]);
    }

    public function testDottedColumnInArrayJoin(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('nested.tags', 'tag')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `nested`.`tags` AS `tag`', $result->query);
    }

    public function testAsofJoinWithRegularJoin(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->join('instruments', 'trades.symbol', 'instruments.symbol')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` JOIN `instruments` ON `trades`.`symbol` = `instruments`.`symbol` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts`', $result->query);
    }

    public function testWithTotalsNoHavingNoOrderBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->withTotals()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `type` WITH TOTALS', $result->query);
    }

    public function testWithRollupNoLimit(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->withRollup()
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `type` WITH ROLLUP', $result->query);
    }

    public function testArrayJoinNoFilterNoOrder(): void
    {
        $result = (new Builder())
            ->from('events')
            ->select(['name'])
            ->arrayJoin('tags', 'tag')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT `name` FROM `events` ARRAY JOIN `tags` AS `tag`', $result->query);
    }

    public function testLimitByWithGroupByAndHaving(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['user_id', 'event_type'])
            ->having([Query::greaterThan('cnt', 5)])
            ->sortDesc('cnt')
            ->limitBy(2, ['user_id'])
            ->limit(50)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `user_id`, `event_type` HAVING COUNT(*) > ? ORDER BY `cnt` DESC LIMIT ? BY `user_id` LIMIT ?', $result->query);
    }

    public function testGroupConcatWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupConcat('name', ',', 'names')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT arrayStringConcat(groupArray(`name`), ?) AS `names` FROM `events` GROUP BY `type`', $result->query);
    }

    public function testGroupConcatWithoutAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupConcat('name', ',')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT arrayStringConcat(groupArray(`name`), ?) FROM `events` GROUP BY `type`', $result->query);
        $this->assertSame([','], $result->bindings);
    }

    public function testJsonArrayAggWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->jsonArrayAgg('value', 'values_json')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT toJSONString(groupArray(`value`)) AS `values_json` FROM `events` GROUP BY `type`', $result->query);
    }

    public function testJsonObjectAggWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->jsonObjectAgg('key', 'value', 'kv_json')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT toJSONString(CAST((groupArray(`key`), groupArray(`value`)) AS Map(String, String))) AS `kv_json` FROM `events` GROUP BY `type`', $result->query);
    }

    public function testStddevWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->stddev('value', 'sd')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT stddevPop(`value`) AS `sd` FROM `events`', $result->query);
        $this->assertStringNotContainsString('STDDEV(', $result->query);
    }

    public function testStddevPopWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->stddevPop('value', 'sd_pop')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT STDDEV_POP(`value`) AS `sd_pop` FROM `events`', $result->query);
    }

    public function testStddevSampWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->stddevSamp('value', 'sd_samp')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT STDDEV_SAMP(`value`) AS `sd_samp` FROM `events`', $result->query);
    }

    public function testVarianceWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->variance('value', 'var')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT varPop(`value`) AS `var` FROM `events`', $result->query);
        $this->assertStringNotContainsString('VARIANCE(', $result->query);
    }

    public function testVarPopWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->varPop('value', 'vp')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT VAR_POP(`value`) AS `vp` FROM `events`', $result->query);
    }

    public function testVarSampWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->varSamp('value', 'vs')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT VAR_SAMP(`value`) AS `vs` FROM `events`', $result->query);
    }

    public function testBitAndWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->bitAnd('flags', 'and_flags')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT BIT_AND(`flags`) AS `and_flags` FROM `events`', $result->query);
    }

    public function testBitOrWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->bitOr('flags', 'or_flags')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT BIT_OR(`flags`) AS `or_flags` FROM `events`', $result->query);
    }

    public function testBitXorWithAlias(): void
    {
        $result = (new Builder())
            ->from('events')
            ->bitXor('flags', 'xor_flags')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT BIT_XOR(`flags`) AS `xor_flags` FROM `events`', $result->query);
    }

    public function testUniqWithGroupByAndFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->uniq('user_id', 'unique_users')
            ->filter([Query::greaterThan('timestamp', '2024-01-01')])
            ->groupBy(['event_type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT uniq(`user_id`) AS `unique_users` FROM `events` WHERE `timestamp` > ? GROUP BY `event_type`', $result->query);
    }

    public function testAnyValueWithGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->anyValue('name', 'any_name')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt`, any(`name`) AS `any_name` FROM `events` GROUP BY `type`', $result->query);
    }

    public function testGroupUniqArrayWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->groupUniqArray('tag', 'unique_tags')
            ->filter([Query::equal('status', ['active'])])
            ->groupBy(['user_id'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT groupUniqArray(`tag`) AS `unique_tags` FROM `events` WHERE `status` IN (?) GROUP BY `user_id`', $result->query);
    }

    public function testOrderWithFillToOnly(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('value', 'ASC', null, 100)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `value` ASC WITH FILL TO ?', $result->query);
        $this->assertStringNotContainsString('FROM ?', $result->query);
        $this->assertSame([100], $result->bindings);
    }

    public function testOrderWithFillStepOnly(): void
    {
        $result = (new Builder())
            ->from('events')
            ->orderWithFill('value', 'ASC', null, null, 5)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ORDER BY `value` ASC WITH FILL STEP ?', $result->query);
        $this->assertStringNotContainsString('FROM ?', $result->query);
        $this->assertStringNotContainsString('TO ?', $result->query);
        $this->assertSame([5], $result->bindings);
    }

    public function testWithTotalsWithSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'cnt')
            ->groupBy(['type'])
            ->withTotals()
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT COUNT(*) AS `cnt` FROM `events` GROUP BY `type` WITH TOTALS SETTINGS max_threads=2', $result->query);
        $totalsPos = \strpos($result->query, 'WITH TOTALS');
        $settingsPos = \strpos($result->query, 'SETTINGS');
        $this->assertLessThan($settingsPos, $totalsPos);
    }

    public function testArrayJoinWithSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->arrayJoin('tags', 'tag')
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` ARRAY JOIN `tags` AS `tag` SETTINGS max_threads=2', $result->query);
    }

    public function testAsofJoinWithSettings(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`ts` >= `quotes`.`ts` SETTINGS max_threads=2', $result->query);
    }

    public function testLimitByWithLimitAndSettings(): void
    {
        $result = (new Builder())
            ->from('events')
            ->limitBy(3, ['user_id'])
            ->limit(100)
            ->settings(['max_threads' => '2'])
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` LIMIT ? BY `user_id` LIMIT ? SETTINGS max_threads=2', $result->query);
    }

    public function testResetClearsAllNewState(): void
    {
        $builder = (new Builder())
            ->from('events')
            ->final()
            ->sample(0.5)
            ->prewhere([Query::equal('type', ['click'])])
            ->arrayJoin('tags', 'tag')
            ->asofJoin('quotes', ['t.k' => 'q.k'], 't.ts', AsofOperator::GreaterThanEqual, 'q.ts')
            ->limitBy(3, ['user_id'])
            ->withTotals()
            ->settings(['max_threads' => '4']);

        $builder->build();
        $builder->reset();

        $result = $builder->from('clean')->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `clean`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testFromNoneEmitsEmptyPlaceholder(): void
    {
        // ClickHouse always emits a FROM clause; fromNone() produces an empty backtick-quoted placeholder.
        $result = (new Builder())
            ->fromNone()
            ->selectRaw('1 + 1')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT 1 + 1 FROM ``', $result->query);
    }

    public function testSelectCastEmitsCastExpression(): void
    {
        $result = (new Builder())
            ->from('products')
            ->selectCast('price', 'DECIMAL(10, 2)', 'price_decimal')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT CAST(`price` AS DECIMAL(10, 2)) AS `price_decimal` FROM `products`', $result->query);
    }

    public function testSelectCastRejectsInvalidType(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid cast type');

        (new Builder())
            ->from('t')
            ->selectCast('c', 'INT); DROP TABLE x;--', 'a');
    }

    public function testSelectWindowRejectsInvalidFunction(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid window function');

        (new Builder())
            ->from('t')
            ->selectWindow('ROW_NUMBER()); DROP --', 'w');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function reservedWordsProvider(): array
    {
        return [
            ['select'],
            ['from'],
            ['where'],
            ['order'],
            ['group'],
            ['having'],
            ['user'],
            ['table'],
            ['insert'],
            ['update'],
            ['delete'],
            ['join'],
            ['on'],
            ['and'],
            ['or'],
            ['not'],
            ['in'],
            ['between'],
            ['like'],
            ['is'],
            ['null'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInSelect(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$word])
            ->build();

        $this->assertStringContainsString('`' . $word . '`', $result->query);
        $stripped = \preg_replace('/`[^`]+`/', '', $result->query) ?? '';
        // Lowercase reserved word must not appear bare outside quotes
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])' . \preg_quote($word, '/') . '(?![A-Za-z0-9_])/',
            $stripped
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFrom(string $word): void
    {
        $result = (new Builder())
            ->from($word)
            ->build();

        $this->assertStringContainsString('FROM `' . $word . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedWordsProvider')]
    public function testReservedWordInFilter(string $word): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($word, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $word . '`', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unicodeIdentifiersProvider(): array
    {
        return [
            ['café'],
            ['日本'],
            ['column_with_émoji'],
            ['Ω_omega'],
            ['данные'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInSelect(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->select([$identifier])
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFrom(string $identifier): void
    {
        $result = (new Builder())
            ->from($identifier)
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeIdentifiersProvider')]
    public function testUnicodeIdentifierInFilter(string $identifier): void
    {
        $result = (new Builder())
            ->from('t')
            ->filter([Query::equal($identifier, ['x'])])
            ->build();

        $this->assertStringContainsString('`' . $identifier . '`', $result->query);
        $this->assertSame(['x'], $result->bindings);
    }

    public function testStructuredSlotsDoNotMutateIdentifiersOrLiterals(): void
    {
        // Table/column identifiers and bound string literals containing
        // SQL keywords (ARRAY JOIN, LIMIT, SETTINGS, PREWHERE, WHERE, ORDER
        // BY, GROUP BY) must pass through the builder untouched. The legacy
        // regex-based afterBuild() could in principle match these keywords
        // inside identifiers/literals; the structured slots cannot.
        $result = (new Builder())
            ->from('settings_table', 'settings_alias')
            ->select(['id', 'array_join_col', 'limit_by_col'])
            ->filter([
                Query::equal('label', ['LIMIT 1 SETTINGS foo']),
                Query::equal('description', ['ARRAY JOIN tags']),
                Query::equal('note', ['PREWHERE condition']),
            ])
            ->sortAsc('order_by_col')
            ->limit(10)
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame([
            'LIMIT 1 SETTINGS foo',
            'ARRAY JOIN tags',
            'PREWHERE condition',
            10,
        ], $result->bindings);

        $this->assertSame('SELECT `id`, `array_join_col`, `limit_by_col` FROM `settings_table` AS `settings_alias` WHERE `label` IN (?) AND `description` IN (?) AND `note` IN (?) ORDER BY `order_by_col` ASC LIMIT ?', $result->query);

        // Literals must not appear un-parameterised in the SQL.
        $this->assertStringNotContainsString('LIMIT 1 SETTINGS foo', $result->query);
        $this->assertStringNotContainsString('ARRAY JOIN tags', $result->query);
        $this->assertStringNotContainsString('PREWHERE condition', $result->query);

        // No spurious clause keywords were injected anywhere.
        $this->assertStringNotContainsString('ARRAY JOIN `', $result->query);
        $this->assertStringNotContainsString(' SETTINGS ', $result->query);
        $this->assertStringNotContainsString('PREWHERE ', $result->query);
        $this->assertStringNotContainsString(' LIMIT BY ', $result->query);
    }

    public function testWhereColumnEmitsQualifiedIdentifiers(): void
    {
        $result = (new Builder())
            ->from('events')
            ->whereColumn('events.user_id', '=', 'sessions.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `events`.`user_id` = `sessions`.`user_id`', $result->query);
        $this->assertSame([], $result->bindings);
    }

    public function testWhereColumnRejectsUnknownOperator(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid whereColumn operator: NOT_AN_OP');

        (new Builder())
            ->from('events')
            ->whereColumn('a', 'NOT_AN_OP', 'b');
    }

    public function testWhereColumnCombinesWithFilter(): void
    {
        $result = (new Builder())
            ->from('events')
            ->filter([Query::equal('status', ['active'])])
            ->whereColumn('events.user_id', '=', 'sessions.user_id')
            ->build();
        $this->assertBindingCount($result);

        $this->assertSame('SELECT * FROM `events` WHERE `status` IN (?) AND `events`.`user_id` = `sessions`.`user_id`', $result->query);
        $this->assertContains('active', $result->bindings);
    }

}
