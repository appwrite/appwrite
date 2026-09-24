<?php

namespace Tests\Query\Builder\Feature\ClickHouse;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as Builder;
use Utopia\Query\Builder\ClickHouse\AsofOperator;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;

class AsofJoinsTest extends TestCase
{
    use AssertsBindingCount;

    public function testAsofJoinEmitsEquiAndInequalityConditions(): void
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

    public function testAsofJoinWithAliasUsesAliasInOnClause(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'q.symbol'],
                'trades.ts',
                AsofOperator::GreaterThan,
                'q.ts',
                'q',
            )
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` AS `q` ON `trades`.`symbol` = `q`.`symbol` AND `trades`.`ts` > `q`.`ts`', $result->query);
    }

    public function testAsofJoinSupportsMultipleEquiPairs(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                [
                    'trades.symbol' => 'quotes.symbol',
                    'trades.exchange' => 'quotes.exchange',
                ],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `trades` ASOF JOIN `quotes` ON `trades`.`symbol` = `quotes`.`symbol` AND `trades`.`exchange` = `quotes`.`exchange` AND `trades`.`ts` >= `quotes`.`ts`', $result->query);
    }

    public function testAsofLeftJoinEmitsAsofLeftJoinKeyword(): void
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

    public function testAsofJoinRejectsEmptyEquiPairs(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ASOF JOIN requires at least one equi-join column pair.');

        (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                [],
                'trades.ts',
                AsofOperator::GreaterThanEqual,
                'quotes.ts',
            )
            ->build();
    }

    public function testAsofJoinPrecedesWhereClause(): void
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
        $this->assertLessThan(\strpos($result->query, 'WHERE'), \strpos($result->query, 'ASOF JOIN'));
        $this->assertSame(['AAPL'], $result->bindings);
    }

    public function testAsofJoinDoesNotAddBindings(): void
    {
        $result = (new Builder())
            ->from('trades')
            ->asofJoin(
                'quotes',
                ['trades.symbol' => 'quotes.symbol'],
                'trades.ts',
                AsofOperator::LessThanEqual,
                'quotes.ts',
            )
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }
}
