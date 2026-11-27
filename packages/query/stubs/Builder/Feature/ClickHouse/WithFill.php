<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\ClickHouse;

interface WithFill
{
    /**
     * ClickHouse ORDER BY ... WITH FILL — fills gaps in ordered results.
     */
    public function orderWithFill(string $column, string $direction = 'ASC', mixed $from = null, mixed $to = null, mixed $step = null): static;
}
