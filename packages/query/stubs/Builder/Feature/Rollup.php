<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Rollup
{
    /**
     * Add hierarchical subtotal rows for each grouping level, plus a grand total.
     */
    public function withRollup(): static;
}
