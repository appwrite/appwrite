<?php

namespace Tests\Query\Builder\Feature\PostgreSQL;

use PHPUnit\Framework\TestCase;
use Tests\Query\AssertsBindingCount;
use Utopia\Query\Builder\PostgreSQL as Builder;

class OrderedSetAggregatesTest extends TestCase
{
    use AssertsBindingCount;

    public function testArrayAggQuotesColumnAndAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->arrayAgg('name', 'names')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT ARRAY_AGG("name") AS "names" FROM "users"', $result->query);
    }

    public function testBoolAndBoolOrAndEveryEmitCorrectFunctions(): void
    {
        $result = (new Builder())
            ->from('t')
            ->boolAnd('a', 'ba')
            ->boolOr('b', 'bo')
            ->every('c', 'ev')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT BOOL_AND("a") AS "ba", BOOL_OR("b") AS "bo", EVERY("c") AS "ev" FROM "t"', $result->query);
    }

    public function testPercentileContBindsFractionFirst(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->percentileCont(0.5, 'value', 'median')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT PERCENTILE_CONT(?) WITHIN GROUP (ORDER BY "value") AS "median" FROM "scores"', $result->query);
        $this->assertSame([0.5], $result->bindings);
    }

    public function testPercentileDiscUsesPercentileDiscFunction(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->percentileDisc(0.95, 'value', 'p95')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT PERCENTILE_DISC(?) WITHIN GROUP (ORDER BY "value") AS "p95" FROM "scores"', $result->query);
        $this->assertSame([0.95], $result->bindings);
    }

    public function testArrayAggWithoutAliasOmitsAsClause(): void
    {
        $result = (new Builder())
            ->from('users')
            ->arrayAgg('name')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT ARRAY_AGG("name") FROM "users"', $result->query);
        $this->assertStringNotContainsString('AS ""', $result->query);
    }

    public function testModeEmitsModeWithinGroup(): void
    {
        $result = (new Builder())
            ->from('users')
            ->mode('city')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT MODE() WITHIN GROUP (ORDER BY "city") FROM "users"', $result->query);
        $this->assertStringNotContainsString('AS ""', $result->query);
    }

    public function testModeWithAlias(): void
    {
        $result = (new Builder())
            ->from('users')
            ->mode('city', 'top_city')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT MODE() WITHIN GROUP (ORDER BY "city") AS "top_city" FROM "users"', $result->query);
    }

    public function testModeWithQualifiedColumn(): void
    {
        $result = (new Builder())
            ->from('users')
            ->mode('users.city', 'top_city')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT MODE() WITHIN GROUP (ORDER BY "users"."city") AS "top_city" FROM "users"', $result->query);
    }

    public function testTwoPercentilesBindFractionsInCallOrder(): void
    {
        $result = (new Builder())
            ->from('scores')
            ->percentileCont(0.25, 'value', 'p25')
            ->percentileCont(0.75, 'value', 'p75')
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([0.25, 0.75], $result->bindings);
    }
}
