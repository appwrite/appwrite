<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Overriding the declared width of a vector column. PostgreSQL emits
 * VECTOR(n); ClickHouse's Array(Float64) and MongoDB's array bsonType are
 * unsized, so neither exposes this.
 */
trait Dimensions
{
    public function dimensions(int $dimensions): static
    {
        $this->dimensions = $dimensions;

        return $this;
    }
}
