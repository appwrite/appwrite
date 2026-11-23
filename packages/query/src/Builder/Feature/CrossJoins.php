<?php

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
