<?php

declare(strict_types=1);

// Calls from a file declaring strict_types: Query's methods coerce nothing for it.

use Utopia\Query\Query;

function strict_factory(string $name, array $args): Query
{
    return Query::$name(...$args);
}

function strict_new(array $args): Query
{
    return new Query(...$args);
}
