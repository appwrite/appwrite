<?php

namespace Utopia\Query\Builder\Feature\PostgreSQL;

interface Returning
{
    /**
     * @param  list<string>  $columns
     */
    public function returning(array $columns = ['*']): static;
}
