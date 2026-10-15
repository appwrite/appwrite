<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;

trait Spatial
{
    /**
     * Convert a geometry array to WKT string.
     *
     * @param  array<mixed>  $geometry
     */
    protected function geometryToWkt(array $geometry): string
    {
        // Simple array of [lon, lat] -> POINT
        if (\count($geometry) === 2 && \is_numeric($geometry[0]) && \is_numeric($geometry[1])) {
            return 'POINT(' . (float) $geometry[0] . ' ' . (float) $geometry[1] . ')';
        }

        // Array of points -> check depth
        if (isset($geometry[0]) && \is_array($geometry[0])) {
            // Array of arrays of arrays -> POLYGON
            if (isset($geometry[0][0]) && \is_array($geometry[0][0])) {
                $rings = [];
                foreach ($geometry as $ring) {
                    /** @var array<array<float>> $ring */
                    $points = \array_map(fn (array $p): string => (float) $p[0] . ' ' . (float) $p[1], $ring);
                    $rings[] = '(' . \implode(', ', $points) . ')';
                }

                return 'POLYGON(' . \implode(', ', $rings) . ')';
            }

            // Single [lon, lat] pair wrapped in array -> POINT
            if (\count($geometry) === 1) {
                /** @var array<float> $point */
                $point = $geometry[0];

                return 'POINT(' . (float) $point[0] . ' ' . (float) $point[1] . ')';
            }

            // Array of [lon, lat] pairs -> LINESTRING
            /** @var array<array<float>> $geometry */
            $points = \array_map(fn (array $p): string => (float) $p[0] . ' ' . (float) $p[1], $geometry);

            return 'LINESTRING(' . \implode(', ', $points) . ')';
        }

        /** @var int|float|string $rawX */
        $rawX = $geometry[0] ?? 0;
        /** @var int|float|string $rawY */
        $rawY = $geometry[1] ?? 0;

        return 'POINT(' . (float) $rawX . ' ' . (float) $rawY . ')';
    }

    protected function getSpatialTypeFromWkt(string $wkt): string
    {
        $upper = \strtoupper(\trim($wkt));
        if (\str_starts_with($upper, 'POINT')) {
            return ColumnType::Point->value;
        }
        if (\str_starts_with($upper, 'LINESTRING')) {
            return ColumnType::Linestring->value;
        }
        if (\str_starts_with($upper, 'POLYGON')) {
            return ColumnType::Polygon->value;
        }

        return 'unknown';
    }

    /**
     * @param  array<float|int>  $point
     */
    #[\Override]
    public function filterDistance(string $attribute, array $point, string $operator, float $distance, bool $meters = false): static
    {
        $wkt = 'POINT(' . (float) $point[0] . ' ' . (float) $point[1] . ')';
        $method = match ($operator) {
            '<' => Method::DistanceLessThan,
            '>' => Method::DistanceGreaterThan,
            '=' => Method::DistanceEqual,
            '!=' => Method::DistanceNotEqual,
            default => Method::DistanceLessThan,
        };

        $this->pendingQueries[] = new Query($method, $attribute, [[$wkt, $distance, $meters]]);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterIntersects(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::intersects($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotIntersects(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notIntersects($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterCrosses(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::crosses($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotCrosses(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notCrosses($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterOverlaps(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::overlaps($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotOverlaps(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notOverlaps($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterTouches(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::touches($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotTouches(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notTouches($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterCovers(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::covers($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotCovers(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notCovers($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterSpatialEquals(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::spatialEquals($attribute, $geometry);

        return $this;
    }

    /**
     * @param  array<mixed>  $geometry
     */
    #[\Override]
    public function filterNotSpatialEquals(string $attribute, array $geometry): static
    {
        $this->pendingQueries[] = Query::notSpatialEquals($attribute, $geometry);

        return $this;
    }
}
