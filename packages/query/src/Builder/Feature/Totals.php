<?php

namespace Utopia\Query\Builder\Feature;

interface Totals
{
    /**
     * Add a grand total row to GROUP BY results (no intermediate subtotals).
     */
    public function withTotals(): static;
}
