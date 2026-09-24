<?php

namespace Tests\Query\API;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Method;
use Utopia\Query\Query;

class AggregationTest extends TestCase
{
    public function testCountDefaultAttribute(): void
    {
        $query = Query::count();
        $this->assertSame(Method::Count, $query->getMethod());
        $this->assertSame('*', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testCountWithAttribute(): void
    {
        $query = Query::count('id');
        $this->assertSame(Method::Count, $query->getMethod());
        $this->assertSame('id', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testCountWithAlias(): void
    {
        $query = Query::count('*', 'total');
        $this->assertSame('*', $query->getAttribute());
        $this->assertSame(['total'], $query->getValues());
        $this->assertSame('total', $query->getValue());
    }

    public function testSum(): void
    {
        $query = Query::sum('price');
        $this->assertSame(Method::Sum, $query->getMethod());
        $this->assertSame('price', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testSumWithAlias(): void
    {
        $query = Query::sum('price', 'total_price');
        $this->assertSame(['total_price'], $query->getValues());
    }

    public function testAvg(): void
    {
        $query = Query::avg('score');
        $this->assertSame(Method::Avg, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testMin(): void
    {
        $query = Query::min('price');
        $this->assertSame(Method::Min, $query->getMethod());
        $this->assertSame('price', $query->getAttribute());
    }

    public function testMax(): void
    {
        $query = Query::max('price');
        $this->assertSame(Method::Max, $query->getMethod());
        $this->assertSame('price', $query->getAttribute());
    }

    public function testGroupBy(): void
    {
        $query = Query::groupBy(['status', 'country']);
        $this->assertSame(Method::GroupBy, $query->getMethod());
        $this->assertSame('', $query->getAttribute());
        $this->assertSame(['status', 'country'], $query->getValues());
    }

    public function testHaving(): void
    {
        $inner = [
            Query::greaterThan('count', 5),
        ];
        $query = Query::having($inner);
        $this->assertSame(Method::Having, $query->getMethod());
        $this->assertCount(1, $query->getValues());
        $this->assertInstanceOf(Query::class, $query->getValues()[0]);
    }

    public function testAggregateMethodsAreAggregate(): void
    {
        $this->assertTrue(Method::Count->isAggregate());
        $this->assertTrue(Method::Sum->isAggregate());
        $this->assertTrue(Method::Avg->isAggregate());
        $this->assertTrue(Method::Min->isAggregate());
        $this->assertTrue(Method::Max->isAggregate());
        $this->assertTrue(Method::CountDistinct->isAggregate());
        $this->assertTrue(Method::Stddev->isAggregate());
        $this->assertTrue(Method::StddevPop->isAggregate());
        $this->assertTrue(Method::StddevSamp->isAggregate());
        $this->assertTrue(Method::Variance->isAggregate());
        $this->assertTrue(Method::VarPop->isAggregate());
        $this->assertTrue(Method::VarSamp->isAggregate());
        $this->assertTrue(Method::BitAnd->isAggregate());
        $this->assertTrue(Method::BitOr->isAggregate());
        $this->assertTrue(Method::BitXor->isAggregate());
        $aggMethods = array_filter(Method::cases(), fn (Method $m) => $m->isAggregate());
        $this->assertCount(15, $aggMethods);
    }

    public function testCountWithEmptyStringAttribute(): void
    {
        $query = Query::count('');
        $this->assertSame('', $query->getAttribute());
        $this->assertSame([], $query->getValues());
    }

    public function testSumWithEmptyAlias(): void
    {
        $query = Query::sum('price', '');
        $this->assertSame([], $query->getValues());
    }

    public function testAvgWithAlias(): void
    {
        $query = Query::avg('score', 'avg_score');
        $this->assertSame(['avg_score'], $query->getValues());
        $this->assertSame('avg_score', $query->getValue());
    }

    public function testMinWithAlias(): void
    {
        $query = Query::min('price', 'min_price');
        $this->assertSame(['min_price'], $query->getValues());
    }

    public function testMaxWithAlias(): void
    {
        $query = Query::max('price', 'max_price');
        $this->assertSame(['max_price'], $query->getValues());
    }

    public function testGroupByEmpty(): void
    {
        $query = Query::groupBy([]);
        $this->assertSame(Method::GroupBy, $query->getMethod());
        $this->assertSame([], $query->getValues());
    }

    public function testGroupBySingleColumn(): void
    {
        $query = Query::groupBy(['status']);
        $this->assertSame(['status'], $query->getValues());
    }

    public function testGroupByManyColumns(): void
    {
        $cols = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
        $query = Query::groupBy($cols);
        $this->assertCount(7, $query->getValues());
    }

    public function testGroupByDuplicateColumns(): void
    {
        $query = Query::groupBy(['status', 'status']);
        $this->assertSame(['status', 'status'], $query->getValues());
    }

    public function testHavingEmpty(): void
    {
        $query = Query::having([]);
        $this->assertSame(Method::Having, $query->getMethod());
        $this->assertSame([], $query->getValues());
    }

    public function testHavingMultipleConditions(): void
    {
        $inner = [
            Query::greaterThan('count', 5),
            Query::lessThan('total', 1000),
        ];
        $query = Query::having($inner);
        $this->assertCount(2, $query->getValues());
        $this->assertInstanceOf(Query::class, $query->getValues()[0]);
        $this->assertInstanceOf(Query::class, $query->getValues()[1]);
    }

    public function testHavingWithLogicalOr(): void
    {
        $inner = [
            Query::or([
                Query::greaterThan('count', 5),
                Query::lessThan('count', 1),
            ]),
        ];
        $query = Query::having($inner);
        $this->assertCount(1, $query->getValues());
    }

    public function testHavingIsNested(): void
    {
        $query = Query::having([Query::greaterThan('x', 1)]);
        $this->assertTrue($query->isNested());
    }

    public function testDistinctIsNotNested(): void
    {
        $query = Query::distinct();
        $this->assertFalse($query->isNested());
    }

    public function testCountCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::count('id');
        $sql = $query->compile($builder);
        $this->assertSame('COUNT(`id`)', $sql);
    }

    public function testSumCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::sum('price', 'total');
        $sql = $query->compile($builder);
        $this->assertSame('SUM(`price`) AS `total`', $sql);
    }

    public function testAvgCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::avg('score');
        $sql = $query->compile($builder);
        $this->assertSame('AVG(`score`)', $sql);
    }

    public function testMinCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::min('price');
        $sql = $query->compile($builder);
        $this->assertSame('MIN(`price`)', $sql);
    }

    public function testMaxCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::max('price');
        $sql = $query->compile($builder);
        $this->assertSame('MAX(`price`)', $sql);
    }

    public function testStddev(): void
    {
        $query = Query::stddev('score');
        $this->assertSame(Method::Stddev, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testStddevCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::stddev('score');
        $sql = $query->compile($builder);
        $this->assertSame('STDDEV(`score`)', $sql);
    }

    public function testStddevPop(): void
    {
        $query = Query::stddevPop('score');
        $this->assertSame(Method::StddevPop, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testStddevPopCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::stddevPop('score', 'sd');
        $sql = $query->compile($builder);
        $this->assertSame('STDDEV_POP(`score`) AS `sd`', $sql);
    }

    public function testStddevSamp(): void
    {
        $query = Query::stddevSamp('score');
        $this->assertSame(Method::StddevSamp, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testStddevSampCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::stddevSamp('score', 'sd');
        $sql = $query->compile($builder);
        $this->assertSame('STDDEV_SAMP(`score`) AS `sd`', $sql);
    }

    public function testVariance(): void
    {
        $query = Query::variance('score');
        $this->assertSame(Method::Variance, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testVarianceCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::variance('score');
        $sql = $query->compile($builder);
        $this->assertSame('VARIANCE(`score`)', $sql);
    }

    public function testVarPop(): void
    {
        $query = Query::varPop('score');
        $this->assertSame(Method::VarPop, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testVarPopCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::varPop('score', 'vp');
        $sql = $query->compile($builder);
        $this->assertSame('VAR_POP(`score`) AS `vp`', $sql);
    }

    public function testVarSamp(): void
    {
        $query = Query::varSamp('score');
        $this->assertSame(Method::VarSamp, $query->getMethod());
        $this->assertSame('score', $query->getAttribute());
    }

    public function testVarSampCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::varSamp('score', 'vs');
        $sql = $query->compile($builder);
        $this->assertSame('VAR_SAMP(`score`) AS `vs`', $sql);
    }

    public function testBitAnd(): void
    {
        $query = Query::bitAnd('flags');
        $this->assertSame(Method::BitAnd, $query->getMethod());
        $this->assertSame('flags', $query->getAttribute());
    }

    public function testBitAndCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::bitAnd('flags', 'result');
        $sql = $query->compile($builder);
        $this->assertSame('BIT_AND(`flags`) AS `result`', $sql);
    }

    public function testBitOr(): void
    {
        $query = Query::bitOr('flags');
        $this->assertSame(Method::BitOr, $query->getMethod());
        $this->assertSame('flags', $query->getAttribute());
    }

    public function testBitOrCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::bitOr('flags', 'result');
        $sql = $query->compile($builder);
        $this->assertSame('BIT_OR(`flags`) AS `result`', $sql);
    }

    public function testBitXor(): void
    {
        $query = Query::bitXor('flags');
        $this->assertSame(Method::BitXor, $query->getMethod());
        $this->assertSame('flags', $query->getAttribute());
    }

    public function testBitXorCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::bitXor('flags', 'result');
        $sql = $query->compile($builder);
        $this->assertSame('BIT_XOR(`flags`) AS `result`', $sql);
    }

    public function testGroupByCompileDispatch(): void
    {
        $builder = new MySQL();
        $query = Query::groupBy(['status', 'country']);
        $sql = $query->compile($builder);
        $this->assertSame('`status`, `country`', $sql);
    }

    public function testHavingCompileDispatchUsesCompileFilter(): void
    {
        $builder = new MySQL();
        $query = Query::having([Query::greaterThan('total', 5)]);
        $sql = $query->compile($builder);
        $this->assertSame('(`total` > ?)', $sql);
        $this->assertSame([5], $builder->getBindings());
    }
}
