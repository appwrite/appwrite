<?php

namespace Utopia\Query\Tests\Builder\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Builder\MariaDB as MariaDBBuilder;
use Utopia\Query\Builder\MySQL as MySQLBuilder;
use Utopia\Query\Builder\PostgreSQL as PostgreSQLBuilder;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Query;
use Utopia\Query\Tests\AssertsBindingCount;

class SpatialTest extends TestCase
{
    use AssertsBindingCount;

    public function testFilterDistanceBindsPointAndDistanceInOrder(): void
    {
        $result = new MySQLBuilder()
            ->from('places')
            ->filterDistance('coords', [10.5, 20.25], '<', 100.0)
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame([0 => 'POINT(10.5 20.25)', 1 => 100.0], $result->bindings);
    }

    public function testFilterIntersectsQuotesIdentifierForMySQL(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `zones` WHERE ST_Intersects(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterIntersectsQuotesIdentifierForPostgreSQL(): void
    {
        $result = new PostgreSQLBuilder()
            ->from('zones')
            ->filterIntersects('area', [1.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "zones" WHERE ST_Intersects("area", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterNotIntersectsWrapsWithNot(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterNotIntersects('area', [1.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `zones` WHERE NOT ST_Intersects(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterCoversProducesStCoversOnPostgreSQL(): void
    {
        $result = new PostgreSQLBuilder()
            ->from('zones')
            ->filterCovers('region', [1.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM "zones" WHERE ST_Covers("region", ST_GeomFromText(?, 4326))', $result->query);
    }

    public function testFilterSpatialEqualsProducesStEquals(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterSpatialEquals('area', [3.0, 4.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `zones` WHERE ST_Equals(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterTouchesProducesStTouches(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterTouches('area', [1.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertSame('SELECT * FROM `zones` WHERE ST_Touches(`area`, ST_GeomFromText(?, 4326, \'axis-order=long-lat\'))', $result->query);
    }

    public function testFilterCrossesLineStringBindingIsLinestringWkt(): void
    {
        $result = new MySQLBuilder()
            ->from('paths')
            ->filterCrosses('path', [[0.0, 0.0], [1.0, 1.0]])
            ->build();

        $this->assertBindingCount($result);
        $this->assertIsString($result->bindings[0]);
        $this->assertSame('LINESTRING(0 0, 1 1)', $result->bindings[0]);
    }

    public function testFilterOverlapsChainedAddsAllBindings(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterOverlaps('a', [1.0, 1.0])
            ->filterNotOverlaps('b', [2.0, 2.0])
            ->build();

        $this->assertBindingCount($result);
        $this->assertCount(2, $result->bindings);
        $this->assertSame('POINT(1 1)', $result->bindings[0]);
        $this->assertSame('POINT(2 2)', $result->bindings[1]);
    }

    /**
     * @return array<string, array{0: array<mixed>, 1: string}>
     */
    public static function malformedGeometryProvider(): array
    {
        return [
            'empty' => [[], 'expected an [x, y] point'],
            'single coordinate' => [[1.0], 'expected an [x, y] point'],
            'flat point with a z ordinate' => [[1.0, 2.0, 3.0], 'expected an [x, y] point'],
            'keyed point' => [['x' => 1.0, 'y' => 2.0], 'expected an [x, y] point'],
            'non-numeric point' => [['a', 'b'], 'expected an [x, y] point'],
            'infinite coordinate' => [['1e999', 1.0], 'expected an [x, y] point'],
            'wrapped point missing y' => [[[1.0]], 'every point must be an [x, y] pair'],
            'line point missing y' => [[[0.0, 0.0], [1.0]], 'every point must be an [x, y] pair'],
            'line point that is not a pair' => [[[0.0, 0.0], 5], 'every point must be an [x, y] pair'],
            'polygon point missing y' => [[[[0.0, 0.0], [1.0], [1.0, 1.0], [0.0, 0.0]]], 'every point must be an [x, y] pair'],
            'polygon ring that is not a list' => [[[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 0.0]], 'ring'], 'polygon ring must be a list of points'],
            'empty polygon ring' => [[[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 0.0]], []], 'polygon ring must be a list of points'],
            'one-point polygon ring' => [[[[1.0, 2.0]]], 'polygon ring must have at least 4 points'],
            'closed polygon ring of three points' => [[[[0.0, 0.0], [1.0, 0.0], [0.0, 0.0]]], 'polygon ring must have at least 4 points'],
            'open polygon ring' => [[[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]]], 'polygon ring must be closed'],
            'open polygon hole' => [[[[0.0, 0.0], [4.0, 0.0], [4.0, 4.0], [0.0, 0.0]], [[1.0, 1.0], [2.0, 1.0], [2.0, 2.0], [1.0, 2.0]]], 'polygon ring must be closed'],
        ];
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[DataProvider('malformedGeometryProvider')]
    public function testMySQLRejectsMalformedGeometryWithoutWarnings(array $geometry, string $reason): void
    {
        $this->assertRejectsWithoutWarnings(new MySQLBuilder(), $geometry, $reason);
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[DataProvider('malformedGeometryProvider')]
    public function testPostgreSQLRejectsMalformedGeometryWithoutWarnings(array $geometry, string $reason): void
    {
        $this->assertRejectsWithoutWarnings(new PostgreSQLBuilder(), $geometry, $reason);
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[DataProvider('malformedGeometryProvider')]
    public function testDistanceRejectsMalformedGeometryWithoutWarnings(array $geometry, string $reason): void
    {
        $warnings = $this->collectWarnings(function () use ($geometry, $reason): void {
            try {
                new MariaDBBuilder()
                    ->from('places')
                    ->filter([Query::distanceLessThan('coords', $geometry, 10)])
                    ->build();
                $this->fail('A malformed geometry must be rejected');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        });

        $this->assertSame([], $warnings);
    }

    public function testFilterDistanceRejectsAPointMissingItsY(): void
    {
        $this->expectException(ValidationException::class);

        new MySQLBuilder()->filterDistance('coords', [10.5], '<', 100.0);
    }

    public function testPolygonWithAClosedRingCompiles(): void
    {
        $result = new MySQLBuilder()
            ->from('zones')
            ->filterIntersects('area', [[[0, 0], [1, 0], [1, 1], ['0', '0']]])
            ->build();

        $this->assertSame('POLYGON((0 0, 1 0, 1 1, 0 0))', $result->bindings[0]);
    }

    public function testSingleWrappedPointCompilesToPoint(): void
    {
        $result = new PostgreSQLBuilder()
            ->from('zones')
            ->filterIntersects('area', [[3, '4.5']])
            ->build();

        $this->assertSame('POINT(3 4.5)', $result->bindings[0]);
    }

    /**
     * @param  array<mixed>  $geometry
     */
    private function assertRejectsWithoutWarnings(MySQLBuilder|PostgreSQLBuilder $builder, array $geometry, string $reason): void
    {
        $warnings = $this->collectWarnings(function () use ($builder, $geometry, $reason): void {
            try {
                $builder
                    ->from('zones')
                    ->filter([Query::intersects('area', $geometry)])
                    ->build();
                $this->fail('A malformed geometry must be rejected');
            } catch (ValidationException $e) {
                $this->assertStringContainsString($reason, $e->getMessage());
            }
        });

        $this->assertSame([], $warnings);
    }

    /**
     * @return list<string>
     */
    private function collectWarnings(callable $callback): array
    {
        $warnings = [];
        \set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $callback();
        } finally {
            \restore_error_handler();
        }

        return $warnings;
    }
}
