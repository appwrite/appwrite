<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Adapter;

use Appwrite\Database\Adapter\Postgres;
use PHPUnit\Framework\TestCase;
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
        [$sql, $binds] = $this->compile(Query::distanceLessThan('loc', [10.0, 51.0], 1000, true));

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

    public function testDistanceLessThanInMetersUsesTheVertexClosestToAPole(): void
    {
        [$sql, $binds] = $this->compile(Query::distanceLessThan('loc', [[10.0, 51.0], [10.2, 80.0]], 1000, true));

        $this->assertStringStartsWith('ST_DWithin(', $sql);
        $this->assertSame('LINESTRING(10 51, 10.2 80)', $binds[':q_0']);
        $this->assertGreaterThan($this->minimumDegreeRadius(80.0, 1000.0), $binds[':q_2']);
    }

    public function testMeterRadiusGrowsTowardThePole(): void
    {
        [, $equator] = $this->compile(Query::distanceLessThan('loc', [10.0, 0.0], 1000, true));
        [, $north] = $this->compile(Query::distanceLessThan('loc', [10.0, 80.0], 1000, true));

        $this->assertGreaterThan($equator[':q_2'], $north[':q_2']);
    }

    public function testDistanceLessThanInMetersNearAntimeridianStaysExact(): void
    {
        [$sql, $binds] = $this->compile(Query::distanceLessThan('loc', [179.99, 51.0], 1000, true));

        $this->assertSame(
            'ST_Distance((main."loc"::geography), ST_SetSRID(ST_GeomFromText(:q_0, 4326), 4326)::geography) < :q_1',
            $sql,
        );
        $this->assertArrayNotHasKey(':q_2', $binds);
    }

    public function testDistanceLessThanInMetersAcrossAntimeridianEdgeStaysExact(): void
    {
        [$sql] = $this->compile(Query::distanceLessThan('loc', [[-170.0, 0.0], [170.0, 0.0]], 1000, true));

        $this->assertStringStartsWith('ST_Distance(', $sql);
        $this->assertStringNotContainsString('ST_DWithin', $sql);
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
            [$sql] = $this->compile($query);
            $this->assertSame($expected, $sql);
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function compile(Query $query): array
    {
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
