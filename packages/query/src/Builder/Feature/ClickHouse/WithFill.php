<?php

namespace Utopia\Query\Builder\Feature\ClickHouse;

interface WithFill
{
    /**
     * ClickHouse ORDER BY ... WITH FILL — fills gaps in ordered results.
     */
    public function orderWithFill(string $column, string $direction = 'ASC', mixed $from = null, mixed $to = null, mixed $step = null): static;
}
