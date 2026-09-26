<?php

namespace Utopia\Query\Builder\Feature;

interface Cube
{
    /**
     * Add subtotal rows for every combination of grouping columns, plus a grand total.
     */
    public function withCube(): static;
}
