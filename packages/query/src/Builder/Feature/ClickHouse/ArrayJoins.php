<?php

namespace Utopia\Query\Builder\Feature\ClickHouse;

interface ArrayJoins
{
    /**
     * ClickHouse ARRAY JOIN — unnests an array column into rows.
     */
    public function arrayJoin(string $column, string $alias = ''): static;

    /**
     * ClickHouse LEFT ARRAY JOIN — unnests an array column, keeping rows with empty arrays.
     */
    public function leftArrayJoin(string $column, string $alias = ''): static;
}
