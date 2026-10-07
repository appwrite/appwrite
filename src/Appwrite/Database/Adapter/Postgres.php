<?php

namespace Appwrite\Database\Adapter;

use Utopia\Database\Adapter\Postgres as UtopiaPostgres;
use Utopia\Database\Query;

/**
 * Postgres adapter that keeps distanceLessThan on the spatial index.
 *
 * utopia-php/database compares ST_Distance to the radius. PostGIS cannot
 * serve that from a GIST index, so a radius query walks the primary key.
 * ST_DWithin on the geometry column can. Meter queries still apply the
 * geography distance, so the radius stays meters and the boundary stays
 * exclusive.
 */
class Postgres extends UtopiaPostgres
{
    /**
     * Minimum meters in one degree of latitude on WGS84 (equator).
     * Real degrees are slightly longer, so a radius based on this is a superset.
     */
    private const float LATITUDE_METERS_PER_DEGREE = 110574.0;

    /**
     * Meters in one degree of longitude at the equator, rounded down.
     */
    private const float LONGITUDE_METERS_PER_DEGREE = 111195.0;

    /**
     * Extra room for the spheroid and for the search window sitting
     * closer to a pole than the vertex used above.
     */
    private const float DEGREE_MARGIN = 1.1;

    /**
     * @param array<string, mixed> $binds
     */
    protected function handleDistanceSpatialQueries(
        Query $query,
        array &$binds,
        string $attribute,
        string $alias,
        string $placeholder,
    ): string {
        $sql = parent::handleDistanceSpatialQueries($query, $binds, $attribute, $alias, $placeholder);

        if ($query->getMethod() !== Query::TYPE_DISTANCE_LESS_THAN) {
            return $sql;
        }

        $column = "{$alias}.{$attribute}";
        $geometry = $this->getSpatialGeomFromText(":{$placeholder}_0");
        $values = $query->getValues()[0];
        $meters = isset($values[2]) && $values[2] === true;

        if (!$meters) {
            return "ST_DWithin({$column}, {$geometry}, :{$placeholder}_1) AND {$sql}";
        }

        if (!is_array($values[0]) || !is_numeric($values[1] ?? null)) {
            return $sql;
        }

        $degrees = $this->degreesCoveringMeters($values[0], (float) $values[1]);
        if ($degrees === null) {
            return $sql;
        }

        $binds[":{$placeholder}_2"] = $degrees;

        return "ST_DWithin({$column}, {$geometry}, :{$placeholder}_2) AND {$sql}";
    }

    /**
     * Degree radius that contains every point within $meters of $geometry.
     *
     * Null when a planar degree box would cross the antimeridian and drop matches that geography distance would keep.
     *
     * @param array<mixed> $geometry
     */
    private function degreesCoveringMeters(array $geometry, float $meters): ?float
    {
        $rings = $this->rings($geometry);
        if ($rings === null) {
            return null;
        }

        if ($meters <= 0.0) {
            return 0.0;
        }

        $maxAbsLatitude = 0.0;
        $minLongitude = 180.0;
        $maxLongitude = -180.0;

        foreach ($rings as $ring) {
            $previousLongitude = null;
            foreach ($ring as [$longitude, $latitude]) {
                $maxAbsLatitude = max($maxAbsLatitude, abs($latitude));
                $minLongitude = min($minLongitude, $longitude);
                $maxLongitude = max($maxLongitude, $longitude);

                if ($previousLongitude !== null && abs($longitude - $previousLongitude) > 180.0) {
                    return null;
                }

                $previousLongitude = $longitude;
            }
        }

        $latitudeDelta = $meters / self::LATITUDE_METERS_PER_DEGREE;
        $latitude = min(89.9, $maxAbsLatitude + $latitudeDelta);
        $cosine = cos(deg2rad($latitude));
        if ($cosine < 0.0001) {
            $cosine = 0.0001;
        }

        $longitudeDelta = $meters / (self::LONGITUDE_METERS_PER_DEGREE * $cosine);
        $degrees = hypot($latitudeDelta, $longitudeDelta) * self::DEGREE_MARGIN;

        if ($minLongitude - $degrees < -180.0 || $maxLongitude + $degrees > 180.0) {
            return null;
        }

        return $degrees;
    }

    /**
     * @param array<mixed> $geometry
     * @return list<list<array{0: float, 1: float}>>|null
     */
    private function rings(array $geometry): ?array
    {
        if ($this->isPoint($geometry)) {
            return [[[(float) $geometry[0], (float) $geometry[1]]]];
        }

        if (!isset($geometry[0]) || !is_array($geometry[0])) {
            return null;
        }

        if ($this->isPoint($geometry[0])) {
            $ring = [];
            foreach ($geometry as $point) {
                if (!$this->isPoint($point)) {
                    return null;
                }
                $ring[] = [(float) $point[0], (float) $point[1]];
            }

            return [$ring];
        }

        $rings = [];
        foreach ($geometry as $ring) {
            if (!is_array($ring) || $ring === []) {
                return null;
            }

            $points = [];
            foreach ($ring as $point) {
                if (!$this->isPoint($point)) {
                    return null;
                }
                $points[] = [(float) $point[0], (float) $point[1]];
            }
            $rings[] = $points;
        }

        return $rings === [] ? null : $rings;
    }

    private function isPoint(mixed $value): bool
    {
        return is_array($value)
            && count($value) === 2
            && isset($value[0], $value[1])
            && is_numeric($value[0])
            && is_numeric($value[1]);
    }
}
