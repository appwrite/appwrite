<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class GroupByTimeBucketTest extends TestCase
{
    use AssertsBindingCount;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function intervalProvider(): array
    {
        return [
            '1m' => ['1m', 'toStartOfMinute'],
            '5m' => ['5m', 'toStartOfFiveMinutes'],
            '15m' => ['15m', 'toStartOfFifteenMinutes'],
            '1h' => ['1h', 'toStartOfHour'],
            '1d' => ['1d', 'toStartOfDay'],
            '1w' => ['1w', 'toStartOfWeek'],
            '1M' => ['1M', 'toStartOfMonth'],
        ];
    }

    #[DataProvider('intervalProvider')]
    public function testGroupByTimeBucketEmitsToStartOfFunction(string $interval, string $function): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'count')
            ->groupByTimeBucket('time', $interval)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT COUNT(*) AS `count` FROM `events` GROUP BY ' . $function . '(`time`)',
            $result->query
        );
    }

    public function testGroupByTimeBucketComposesWithSelectRawAndOrderRaw(): void
    {
        $result = (new Builder())
            ->from('events')
            ->selectRaw('toStartOfHour(`time`) AS `bucket`')
            ->count('*', 'count')
            ->groupByTimeBucket('time', '1h')
            ->orderByRaw('`bucket` ASC')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(
            'SELECT COUNT(*) AS `count`, toStartOfHour(`time`) AS `bucket`'
            . ' FROM `events`'
            . ' GROUP BY toStartOfHour(`time`)'
            . ' ORDER BY `bucket` ASC',
            $result->query
        );
    }

    public function testGroupByTimeBucketComposesWithPlainGroupBy(): void
    {
        $result = (new Builder())
            ->from('events')
            ->count('*', 'count')
            ->groupBy(['tenant'])
            ->groupByTimeBucket('time', '1d')
            ->build();

        $this->assertSame(
            'SELECT COUNT(*) AS `count` FROM `events` GROUP BY `tenant`, toStartOfDay(`time`)',
            $result->query
        );
    }

    public function testGroupByTimeBucketRejectsUnknownInterval(): void
    {
        $this->expectException(ValidationException::class);

        Query::groupByTimeBucket('time', '2h');
    }

    public function testQueryGroupByTimeBucketParsedQueryShape(): void
    {
        $queries = [Query::groupByTimeBucket('time', '1h')];

        $parsed = Query::groupByType($queries);

        $this->assertSame(
            [['attribute' => 'time', 'interval' => '1h']],
            $parsed->timeBuckets
        );
        $this->assertSame([], $parsed->groupBy);
    }
}
