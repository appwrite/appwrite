<?php

namespace Utopia\Query\Builder\Feature;

interface Spatial
{
    /**
     * @param  array<float>  $point  [longitude, latitude]
     */
    public function filterDistance(string $attribute, array $point, string $operator, float $distance, bool $meters = false): static;

    /**
     * @param  array<mixed>  $geometry  WKT-compatible geometry coordinates
     */
    public function filterIntersects(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotIntersects(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterCrosses(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotCrosses(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterOverlaps(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotOverlaps(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterTouches(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotTouches(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterCovers(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotCovers(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterSpatialEquals(string $attribute, array $geometry): static;

    /**
     * @param  array<mixed>  $geometry
     */
    public function filterNotSpatialEquals(string $attribute, array $geometry): static;
}
