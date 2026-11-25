<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Builder\Feature;

/**
 * Unqualified joins -- CROSS JOIN and NATURAL JOIN.
 *
 * Separate from {@see Joins} because MongoDB's $lookup always joins on a
 * field pair, so it has no way to express either form.
 */
interface CrossJoins
{
    public function crossJoin(string $table, string $alias = ''): static;
    public function naturalJoin(string $table, string $alias = ''): static;
}
