<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Schema\ColumnType;

trait Spatial
{
    /**
     * @param  array<mixed>  $geometry  [x, y] for a point, a list of points for a line, a list of rings for a polygon.
     *
     * @throws ValidationException
     */
    protected function geometryToWkt(array $geometry): string
    {
        if ($this->isCoordinate($geometry)) {
            return 'POINT(' . $this->coordinateToWkt($geometry) . ')';
        }

        if ($geometry === [] || ! \array_is_list($geometry) || ! \is_array($geometry[0])) {
            throw new ValidationException('Invalid geometry: expected an [x, y] point, a list of points or a list of rings');
        }

        if (\is_array($geometry[0][0] ?? null)) {
            $rings = [];
            foreach ($geometry as $ring) {
                $rings[] = '(' . $this->ringToWkt($ring) . ')';
            }

            return 'POLYGON(' . \implode(', ', $rings) . ')';
        }

        if (\count($geometry) === 1) {
            return 'POINT(' . $this->coordinateToWkt($geometry[0]) . ')';
        }

        return 'LINESTRING(' . $this->coordinatesToWkt($geometry) . ')';
    }

    /**
     * @throws ValidationException
     */
    private function ringToWkt(mixed $ring): string
    {
        if (! \is_array($ring) || $ring === [] || ! \array_is_list($ring)) {
            throw new ValidationException('Invalid geometry: a polygon ring must be a list of points');
        }

        $wkt = $this->coordinatesToWkt($ring);

        if (\count($ring) < 4) {
            throw new ValidationException('Invalid geometry: a polygon ring must have at least 4 points');
        }

        /** @var array{0: int|float|string, 1: int|float|string} $first */
        $first = $ring[0];
        /** @var array{0: int|float|string, 1: int|float|string} $last */
        $last = $ring[\count($ring) - 1];
        if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
            throw new ValidationException('Invalid geometry: a polygon ring must be closed, with its first point equal to its last');
        }

        return $wkt;
    }

    /**
     * @param  list<mixed>  $coordinates
     *
     * @throws ValidationException
     */
    private function coordinatesToWkt(array $coordinates): string
    {
        return \implode(', ', \array_map($this->coordinateToWkt(...), $coordinates));
    }

    /**
     * @throws ValidationException
     */
    private function coordinateToWkt(mixed $coordinate): string
    {
        if (! $this->isCoordinate($coordinate)) {
            throw new ValidationException('Invalid geometry: every point must be an [x, y] pair of finite numbers');
        }

        /** @var array{0: int|float|string, 1: int|float|string} $coordinate */
        return (float) $coordinate[0] . ' ' . (float) $coordinate[1];
    }

    private function isCoordinate(mixed $value): bool
    {
        return \is_array($value)
            && \count($value) === 2
            && \array_is_list($value)
            && \is_numeric($value[0])
            && \is_numeric($value[1])
            && \is_finite((float) $value[0])
            && \is_finite((float) $value[1]);
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
        $wkt = 'POINT(' . $this->coordinateToWkt($point) . ')';
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
