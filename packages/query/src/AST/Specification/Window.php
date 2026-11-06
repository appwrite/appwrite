<?php

namespace Utopia\Query\AST\Specification;

use Utopia\Query\AST\Expression;
use Utopia\Query\AST\OrderByItem;

readonly class Window
{
    /**
     * @param Expression[] $partitionBy
     * @param OrderByItem[] $orderBy
     */
    public function __construct(
        public array $partitionBy = [],
        public array $orderBy = [],
        public ?string $frameType = null,
        public ?string $frameStart = null,
        public ?string $frameEnd = null,
    ) {
    }
}
