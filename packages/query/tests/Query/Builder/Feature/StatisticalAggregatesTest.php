<?php

namespace Tests\Query\Builder\Feature;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\ClickHouse as ClickHouseBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Query;

class StatisticalAggregatesTest extends TestCase
{
    use AssertsBindingCount;

    public function testStddevEmitsStddevFunctionForMySQL(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->stddev('value', 'sd')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT STDDEV(`value`) AS `sd` FROM `scores`', $result->query);
    }

    public function testStddevPopAndSampEmitSeparateFunctions(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->stddevPop('v', 'sp')
            ->stddevSamp('v', 'ss')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT STDDEV_POP(`v`) AS `sp`, STDDEV_SAMP(`v`) AS `ss` FROM `scores`', $result->query);
    }

    public function testVarianceAndVarPopAndVarSampEmitCorrectFunctions(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->variance('v', 'a')
            ->varPop('v', 'b')
            ->varSamp('v', 'c')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT VARIANCE(`v`) AS `a`, VAR_POP(`v`) AS `b`, VAR_SAMP(`v`) AS `c` FROM `scores`', $result->query);
    }

    public function testStddevOnPostgreSQLUsesDoubleQuoting(): void
    {
        $result = (new PostgreSQLBuilder())
            ->from('scores')
            ->stddev('value', 'sd')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT STDDEV("value") AS "sd" FROM "scores"', $result->query);
    }

    public function testStddevOnClickHouseUsesBacktickQuoting(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('scores')
            ->stddev('value', 'sd')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT stddevPop(`value`) AS `sd` FROM `scores`', $result->query);
    }

    public function testVarianceOnClickHouseEmitsVarPop(): void
    {
        $result = (new ClickHouseBuilder())
            ->from('scores')
            ->variance('value', 'var')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT varPop(`value`) AS `var` FROM `scores`', $result->query);
        $this->assertStringNotContainsString('VARIANCE(', $result->query);
    }

    public function testStatisticalAggregateDoesNotAddBindings(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->stddev('value', 'sd')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([], $result->bindings);
    }

    public function testStatisticalAggregateWithWhereUsesCorrectBindingOrder(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->stddev('value', 'sd')
            ->filter([Query::equal('category', ['a'])])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame(['a'], $result->bindings);
    }

    public function testStddevWithoutAliasOmitsAs(): void
    {
        $result = (new MySQLBuilder())
            ->from('scores')
            ->stddev('value')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT STDDEV(`value`) FROM `scores`', $result->query);
        $this->assertStringNotContainsString('AS ``', $result->query);
    }
}
