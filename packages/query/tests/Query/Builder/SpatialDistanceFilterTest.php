<?php

namespace Tests\Query\Builder;

use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\SpatialDistanceFilter;

final class SpatialDistanceFilterTest extends TestCase
{
    public function testConstructsFromStringGeometry(): void
    {
        $filter = new SpatialDistanceFilter('POINT(1 2)', 10.5, true);

        $this->assertSame('POINT(1 2)', $filter->geometry);
        $this->assertSame(10.5, $filter->distance);
        $this->assertTrue($filter->meters);
    }

    public function testConstructsFromArrayGeometry(): void
    {
        $filter = new SpatialDistanceFilter([1.0, 2.0], 42.0, false);

        $this->assertSame([1.0, 2.0], $filter->geometry);
        $this->assertSame(42.0, $filter->distance);
        $this->assertFalse($filter->meters);
    }

    public function testFromTupleNormalizesArrayGeometry(): void
    {
        $filter = SpatialDistanceFilter::fromTuple([[10, 20], 100.0, true]);

        $this->assertSame([10, 20], $filter->geometry);
        $this->assertSame(100.0, $filter->distance);
        $this->assertTrue($filter->meters);
    }

    public function testFromTupleCastsIntegerDistanceToFloat(): void
    {
        $filter = SpatialDistanceFilter::fromTuple(['POINT(0 0)', 50, false]);

        $this->assertSame(50.0, $filter->distance);
        $this->assertFalse($filter->meters);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(SpatialDistanceFilter::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}
