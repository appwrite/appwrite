<?php

namespace Utopia\Query\Builder;

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
     * @param  array{0: string|array<mixed>, 1: float|int, 2: bool}  $tuple
     */
    public static function fromTuple(array $tuple): self
    {
        return new self(
            $tuple[0],
            (float) $tuple[1],
            $tuple[2],
        );
    }
}
