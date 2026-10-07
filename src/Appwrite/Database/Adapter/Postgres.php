<?php

namespace Appwrite\Database\Adapter;

use Utopia\Database\Adapter\Postgres as UtopiaPostgres;
use Utopia\Database\Query;

/**
 * Prefixes distanceLessThan with ST_DWithin so a GIST index can serve it.
 */
class Postgres extends UtopiaPostgres
{
    /**
     * Minimum meters in one degree of latitude on WGS84.
     * Real degrees are longer, so a radius based on this is a superset.
     */
    private const float LATITUDE_METERS_PER_DEGREE = 110574.0;

    /**
     * Meters in one degree of longitude at the equator, rounded down.
     */
    private const float LONGITUDE_METERS_PER_DEGREE = 111195.0;

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

        // Geography edges are geodesic. A planar radius around a point contains
        // the meter circle; a line or polygon does not, so those stay exact.
        $degrees = $this->isPoint($values[0])
            ? $this->degreesCoveringPoint($values[0], (float) $values[1])
            : null;
        if ($degrees === null) {
            return $sql;
        }

        $binds[":{$placeholder}_2"] = $degrees;

        return "ST_DWithin({$column}, {$geometry}, :{$placeholder}_2) AND {$sql}";
    }

    /**
     * Degree radius that contains every location within $meters of a point.
     * Null when that circle reaches a pole or the antimeridian.
     *
     * @param array<mixed> $point
     */
    private function degreesCoveringPoint(array $point, float $meters): ?float
    {
        if ($meters <= 0.0) {
            return 0.0;
        }

        $longitude = (float) $point[0];
        $absLatitude = abs((float) $point[1]);
        $latitudeDelta = $meters / self::LATITUDE_METERS_PER_DEGREE;
        $poleward = $absLatitude + $latitudeDelta;
        if ($poleward >= 90.0) {
            return null;
        }

        $cosine = cos(deg2rad($poleward));
        if ($cosine <= 0.0) {
            return null;
        }

        $longitudeDelta = $meters / (self::LONGITUDE_METERS_PER_DEGREE * $cosine);
        $degrees = hypot($latitudeDelta, $longitudeDelta) * self::DEGREE_MARGIN;
        if ($longitude - $degrees < -180.0 || $longitude + $degrees > 180.0) {
            return null;
        }

        return $degrees;
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
