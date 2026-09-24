<?php

namespace Utopia\Query\Builder\Feature;

interface Rollup
{
    /**
     * Add hierarchical subtotal rows for each grouping level, plus a grand total.
     */
    public function withRollup(): static;
}
