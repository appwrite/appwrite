<?php

namespace Utopia\Query\Schema\Column\Trait;

/**
 * Stored generated columns, for dialects whose DDL can compute a column from
 * an expression at write time.
 */
trait Generated
{
    /**
     * Mark the column as a generated column computed from the given expression.
     */
    public function generatedAs(string $expression): static
    {
        $this->generatedExpression = $expression;

        return $this;
    }

    public function stored(): static
    {
        $this->generatedStored = true;

        return $this;
    }
}
