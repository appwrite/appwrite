<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Marking a column as server-incremented. ClickHouse has no auto-increment and
 * MongoDB assigns _id itself, so neither emits anything for it.
 */
trait AutoIncrement
{
    public function autoIncrement(): static
    {
        $this->isAutoIncrement = true;

        return $this;
    }
}
