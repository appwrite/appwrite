<?php

namespace Utopia\Query\Builder\PostgreSQL;

readonly class DeleteUsing
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $table,
        public string $condition = '',
        public array $bindings = [],
    ) {
    }
}
