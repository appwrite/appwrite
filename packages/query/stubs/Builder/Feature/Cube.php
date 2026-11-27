<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Cube
{
    /**
     * Add subtotal rows for every combination of grouping columns, plus a grand total.
     */
    public function withCube(): static;
}
