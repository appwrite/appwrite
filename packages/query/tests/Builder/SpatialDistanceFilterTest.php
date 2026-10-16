<?php

namespace Utopia\Query\Tests\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\SpatialDistanceFilter;
use Utopia\Query\Exception\ValidationException;

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

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public static function malformedTupleProvider(): array
    {
        return [
            'empty' => [[], 'the geometry must be a WKT string or a coordinate array'],
            'numeric geometry' => [[5, 1.0, false], 'the geometry must be a WKT string or a coordinate array'],
            'missing distance' => [[[1.0, 2.0]], 'the distance must be a finite number'],
            'non-numeric distance' => [[[1.0, 2.0], 'far', false], 'the distance must be a finite number'],
            'infinite distance' => [[[1.0, 2.0], '1e999', false], 'the distance must be a finite number'],
            'array distance' => [[[1.0, 2.0], [1], false], 'the distance must be a finite number'],
            'non-boolean meters' => [[[1.0, 2.0], 1.0, 'sometimes'], 'meters must be a boolean'],
            'array meters' => [[[1.0, 2.0], 1.0, [true]], 'meters must be a boolean'],
        ];
    }

    /**
     * @param  array<mixed>  $tuple
     */
    #[DataProvider('malformedTupleProvider')]
    public function testFromTupleRejectsMalformedTuples(array $tuple, string $reason): void
    {
        $warnings = [];
        \set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            SpatialDistanceFilter::fromTuple($tuple);
            $this->fail('A malformed distance tuple must be rejected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        } finally {
            \restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }

    public function testFromTupleDefaultsMissingMetersToFalse(): void
    {
        $filter = SpatialDistanceFilter::fromTuple([[1.0, 2.0], '7']);

        $this->assertSame(7.0, $filter->distance);
        $this->assertFalse($filter->meters);
    }
}
