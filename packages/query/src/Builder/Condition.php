<?php

namespace Utopia\Query\Builder;

readonly class Condition
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $expression,
        public array $bindings = [],
    ) {
    }

}
