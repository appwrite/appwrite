<?php

namespace Utopia\Usage;

use InvalidArgumentException;

final readonly class SampleGap
{
    public function __construct(
        public int $first,
        public int $last,
    ) {
        if ($first < 0 || $last < $first) {
            throw new InvalidArgumentException('Invalid sample gap');
        }
    }
}
