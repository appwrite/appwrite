<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\CheckConstraint;

trait Checks
{
    /**
     * Add a table-level CHECK constraint.
     *
     * @throws ValidationException if $name is not a valid identifier.
     */
    public function check(string $name, string $expression): static
    {
        $this->checks[] = new CheckConstraint($name, $expression);

        return $this;
    }
}
