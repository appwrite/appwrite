<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Column-level COLLATE. ClickHouse has no per-column collation (it applies
 * collation in ORDER BY) and MongoDB sets it per collection, so neither
 * exposes this.
 */
trait Collation
{
    public function collation(string $collation): static
    {
        $this->collation = $collation;

        return $this;
    }
}
