<?php

namespace Utopia\Query\Builder;

readonly class MergeClause
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $action,
        public bool $matched,
        public array $bindings = [],
    ) {
    }
}
