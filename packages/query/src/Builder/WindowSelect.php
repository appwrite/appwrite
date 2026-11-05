<?php

namespace Utopia\Query\Builder;

readonly class WindowSelect
{
    /**
     * @param  ?list<string>  $partitionBy
     * @param  ?list<string>  $orderBy
     */
    public function __construct(
        public string $function,
        public string $alias,
        public ?array $partitionBy,
        public ?array $orderBy,
        public ?string $windowName = null,
        public ?WindowFrame $frame = null,
    ) {
    }
}
