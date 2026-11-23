<?php

namespace Utopia\Query\Builder;

readonly class UnionClause
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public UnionType $type,
        public string $query,
        public array $bindings,
    ) {
    }
}
