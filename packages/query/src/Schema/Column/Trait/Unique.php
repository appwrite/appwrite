<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Column-level UNIQUE. ClickHouse enforces no uniqueness constraints, and
 * MongoDB expresses it as a unique index rather than in the validator.
 */
trait Unique
{
    public function unique(): static
    {
        $this->isUnique = true;

        return $this;
    }
}
