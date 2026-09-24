<?php

namespace Tests\Query;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Method;

final class MethodTest extends TestCase
{
    public function testAggregationMethodsMapToSqlFunctions(): void
    {
        $this->assertSame('SUM', Method::Sum->sqlFunction());
        $this->assertSame('COUNT', Method::Count->sqlFunction());
        $this->assertSame('COUNT', Method::CountDistinct->sqlFunction());
        $this->assertSame('AVG', Method::Avg->sqlFunction());
        $this->assertSame('MIN', Method::Min->sqlFunction());
        $this->assertSame('MAX', Method::Max->sqlFunction());
    }

    public function testStatisticalMethodsMapToSqlFunctions(): void
    {
        $this->assertSame('STDDEV', Method::Stddev->sqlFunction());
        $this->assertSame('STDDEV_POP', Method::StddevPop->sqlFunction());
        $this->assertSame('STDDEV_SAMP', Method::StddevSamp->sqlFunction());
        $this->assertSame('VARIANCE', Method::Variance->sqlFunction());
        $this->assertSame('VAR_POP', Method::VarPop->sqlFunction());
        $this->assertSame('VAR_SAMP', Method::VarSamp->sqlFunction());
    }

    public function testBitwiseMethodsMapToSqlFunctions(): void
    {
        $this->assertSame('BIT_AND', Method::BitAnd->sqlFunction());
        $this->assertSame('BIT_OR', Method::BitOr->sqlFunction());
        $this->assertSame('BIT_XOR', Method::BitXor->sqlFunction());
    }

    public function testNonAggregationMethodsReturnNull(): void
    {
        $this->assertNull(Method::Equal->sqlFunction());
        $this->assertNull(Method::NotEqual->sqlFunction());
        $this->assertNull(Method::OrderAsc->sqlFunction());
        $this->assertNull(Method::Limit->sqlFunction());
        $this->assertNull(Method::GroupBy->sqlFunction());
        $this->assertNull(Method::Having->sqlFunction());
        $this->assertNull(Method::Select->sqlFunction());
        $this->assertNull(Method::Distinct->sqlFunction());
        $this->assertNull(Method::Join->sqlFunction());
        $this->assertNull(Method::Union->sqlFunction());
        $this->assertNull(Method::Raw->sqlFunction());
    }

    public function testEveryAggregateMethodHasSqlFunction(): void
    {
        foreach (Method::cases() as $method) {
            if ($method->isAggregate()) {
                $this->assertNotNull(
                    $method->sqlFunction(),
                    "Aggregate method {$method->value} must have a sqlFunction() mapping",
                );
            }
        }
    }
}
