<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Adapter;

use Appwrite\Database\Adapter\Postgres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Query;

final class PostgresTest extends TestCase
{
    public function testDistanceLessThanUsesSpatialIndex(): void
    {
        [$sql, $binds] = $this->compile(Query::distanceLessThan('loc', [10.0, 51.0], 0.5));

        $this->assertSame(
            'ST_DWithin(main."loc", ST_GeomFromText(:q_0, 4326), :q_1)'
            . ' AND ST_Distance(main."loc", ST_GeomFromText(:q_0, 4326)) < :q_1',
            $sql,
        );
        $this->assertSame([
            ':q_0' => 'POINT(10 51)',
            ':q_1' => 0.5,
        ], $binds);
    }

    public function testDistanceLessThanInMetersUsesSpatialIndex(): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', [10.0, 51.0], 1000, true),
            Database::VAR_POINT,
        );

        $this->assertSame(
            'ST_DWithin(main."loc", ST_GeomFromText(:q_0, 4326), :q_2)'
            . ' AND ST_Distance((main."loc"::geography),'
            . ' ST_SetSRID(ST_GeomFromText(:q_0, 4326), 4326)::geography) < :q_1',
            $sql,
        );
        $this->assertSame('POINT(10 51)', $binds[':q_0']);
        $this->assertSame(1000, $binds[':q_1']);
        $this->assertGreaterThan($this->minimumDegreeRadius(51.0, 1000.0), $binds[':q_2']);
        $this->assertLessThan(0.05, $binds[':q_2']);
    }

    public function testMeterRadiusNearThePoleCoversNearbyLongitude(): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', [0.0, 89.999], 1, true),
            Database::VAR_POINT,
        );

        $this->assertStringStartsWith('ST_DWithin(', $sql);
        $this->assertGreaterThan(0.1, $binds[':q_2']);
    }

    public function testMeterCircleThatReachesThePoleStaysExact(): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', [0.0, 89.999], 500, true),
            Database::VAR_POINT,
        );

        $this->assertSame($this->exactMeterDistance(), $sql);
        $this->assertArrayNotHasKey(':q_2', $binds);
    }

    public function testDistanceLessThanInMetersNearAntimeridianStaysExact(): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', [179.99, 51.0], 1000, true),
            Database::VAR_POINT,
        );

        $this->assertSame($this->exactMeterDistance(), $sql);
        $this->assertArrayNotHasKey(':q_2', $binds);
    }

    /**
     * @param array<mixed> $geometry
     */
    #[DataProvider('nonPointGeometries')]
    public function testMeterDistanceOnNonPointQueriesStaysExact(array $geometry): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', $geometry, 20000, true),
            Database::VAR_POINT,
        );

        $this->assertSame($this->exactMeterDistance(), $sql);
        $this->assertArrayNotHasKey(':q_2', $binds);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function nonPointGeometries(): array
    {
        return [
            'line' => [[[-60.0, 60.0], [60.0, 60.0]]],
            'polygon' => [[[[-60.0, 60.0], [60.0, 60.0], [0.0, 70.0], [-60.0, 60.0]]]],
        ];
    }

    #[DataProvider('nonPointColumns')]
    public function testMeterDistanceOnNonPointColumnsStaysExact(string $type): void
    {
        [$sql, $binds] = $this->compile(
            Query::distanceLessThan('loc', [0.0, 74.0], 20000, true),
            $type,
        );

        $this->assertSame($this->exactMeterDistance(), $sql);
        $this->assertArrayNotHasKey(':q_2', $binds);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPointColumns(): array
    {
        return [
            'linestring' => [Database::VAR_LINESTRING],
            'polygon' => [Database::VAR_POLYGON],
            'unknown' => [''],
        ];
    }

    public function testOtherDistanceOperatorsDoNotUseSpatialIndex(): void
    {
        $cases = [
            [
                Query::distanceGreaterThan('loc', [1.0, 2.0], 5),
                'ST_Distance(main."loc", ST_GeomFromText(:q_0, 4326)) > :q_1',
            ],
            [
                Query::distanceEqual('loc', [1.0, 2.0], 0, true),
                'ST_Distance((main."loc"::geography), ST_SetSRID(ST_GeomFromText(:q_0, 4326), 4326)::geography) = :q_1',
            ],
            [
                Query::distanceNotEqual('loc', [1.0, 2.0], 5),
                'ST_Distance(main."loc", ST_GeomFromText(:q_0, 4326)) != :q_1',
            ],
        ];

        foreach ($cases as [$query, $expected]) {
            [$sql] = $this->compile($query, Database::VAR_POINT);
            $this->assertSame($expected, $sql);
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function compile(Query $query, string $attributeType = ''): array
    {
        if ($attributeType !== '') {
            $query->setAttributeType($attributeType);
        }

        $probe = new class () extends Postgres {
            public function __construct()
            {
                parent::__construct(null);
            }

            /**
             * @return array{0: string, 1: array<string, mixed>}
             */
            public function compile(Query $query): array
            {
                $binds = [];
                $sql = $this->handleDistanceSpatialQueries($query, $binds, '"loc"', 'main', 'q');

                return [$sql, $binds];
            }
        };

        return $probe->compile($query);
    }

    private function exactMeterDistance(): string
    {
        return 'ST_Distance((main."loc"::geography), ST_SetSRID(ST_GeomFromText(:q_0, 4326), 4326)::geography) < :q_1';
    }

    /**
     * Smallest degree radius that still contains a meter circle at $latitude.
     */
    private function minimumDegreeRadius(float $latitude, float $meters): float
    {
        $latitudeDelta = $meters / 111694.0;
        $longitudeDelta = $meters / (111319.5 * cos(deg2rad(min(89.9, abs($latitude) + $latitudeDelta))));

        return hypot($latitudeDelta, $longitudeDelta);
    }
}
