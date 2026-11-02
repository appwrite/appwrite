<?php

namespace Utopia\Query\Builder;

use Utopia\Query\Exception\ValidationException;

final readonly class SpatialDistanceFilter
{
    /**
     * @param  string|array<mixed>  $geometry  WKT string, or a coordinate array ([x, y] / ring / polygon).
     * @param  float  $distance  Distance threshold to compare against.
     * @param  bool  $meters  Whether the distance is expressed in meters (sphere / geography path).
     */
    public function __construct(
        public string|array $geometry,
        public float $distance,
        public bool $meters,
    ) {
    }

    /**
     * Normalize the raw 3-tuple produced by Query::distance*() into a typed DTO.
     *
     * @param  array<mixed>  $tuple
     *
     * @throws ValidationException
     */
    public static function fromTuple(array $tuple): self
    {
        $geometry = $tuple[0] ?? null;
        $distance = $tuple[1] ?? null;
        $meters = \filter_var($tuple[2] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! \is_string($geometry) && ! \is_array($geometry)) {
            throw new ValidationException('Invalid distance query: the geometry must be a WKT string or a coordinate array');
        }

        if (! \is_numeric($distance) || ! \is_finite((float) $distance)) {
            throw new ValidationException('Invalid distance query: the distance must be a finite number');
        }

        if ($meters === null) {
            throw new ValidationException('Invalid distance query: meters must be a boolean');
        }

        return new self($geometry, (float) $distance, $meters);
    }
}
