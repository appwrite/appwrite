<?php

namespace Utopia\Query\Builder\PostgreSQL;

use Utopia\Query\Builder;

readonly class MergeTarget
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $target,
        public ?Builder $source = null,
        public string $alias = '',
        public string $condition = '',
        public array $bindings = [],
    ) {
    }
}
