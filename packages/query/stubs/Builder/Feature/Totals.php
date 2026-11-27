<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Totals
{
    /**
     * Add a grand total row to GROUP BY results (no intermediate subtotals).
     */
    public function withTotals(): static;
}
