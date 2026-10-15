<?php

namespace Utopia\Query\Builder;

readonly class CteClause
{
    /**
     * @param  list<mixed>  $bindings
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public string $query,
        public array $bindings,
        public bool $recursive,
        public array $columns = [],
    ) {
    }
}
