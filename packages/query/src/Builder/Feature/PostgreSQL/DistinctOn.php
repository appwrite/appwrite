<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface DistinctOn
{
    /**
     * @param  list<string>  $columns
     */
    public function distinctOn(array $columns): static;
}
