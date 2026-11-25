<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

interface Windows
{
    /**
     * Add a window function to the SELECT clause.
     *
     * @param  string  $function  Raw expression: 'ROW_NUMBER()', 'RANK()', 'LAG(col, 1)', 'SUM(amount)'
     * @param  string  $alias  Column alias for the result
     * @param  list<string>|null  $partitionBy  Columns for PARTITION BY
     * @param  list<string>|null  $orderBy  Columns for ORDER BY (prefix with - for DESC)
     * @param  string|null  $windowName  Named window to reference instead of inline OVER (...)
     */
    public function selectWindow(string $function, string $alias, ?array $partitionBy = null, ?array $orderBy = null, ?string $windowName = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static;
    /**
     * Define a named window.
     *
     * @param  list<string>|null  $partitionBy  Columns for PARTITION BY
     * @param  list<string>|null  $orderBy  Columns for ORDER BY (prefix with - for DESC)
     */
    public function window(string $name, ?array $partitionBy = null, ?array $orderBy = null, ?\Utopia\Query\Builder\WindowFrame $frame = null): static;
}
