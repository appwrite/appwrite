<?php

namespace Utopia\Query\Builder\PostgreSQL;

readonly class UpdateFrom
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $table,
        public string $alias = '',
        public string $condition = '',
        public array $bindings = [],
    ) {
    }
}
