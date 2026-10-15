<?php

namespace Utopia\Query\Builder;

readonly class WindowDefinition
{
    /**
     * @param ?list<string> $partitionBy
     * @param ?list<string> $orderBy
     */
    public function __construct(
        public string $name,
        public ?array $partitionBy,
        public ?array $orderBy,
        public ?WindowFrame $frame = null,
    ) {
    }
}
