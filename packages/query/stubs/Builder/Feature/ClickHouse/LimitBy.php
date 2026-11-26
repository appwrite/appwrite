<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature\ClickHouse;

interface LimitBy
{
    /**
     * ClickHouse-specific LIMIT n BY col1, col2 clause.
     * Limits the number of rows per group defined by the given columns.
     *
     * @param  list<string>  $columns
     */
    public function limitBy(int $count, array $columns): static;
}
