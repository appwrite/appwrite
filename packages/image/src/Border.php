<?php

declare(strict_types=1);

namespace Utopia\Image;

final readonly class Border
{
    /**
     * @param int $width In pixels.
     * @param string $color A CSS colour.
     */
    public function __construct(
        public int $width,
        public string $color,
    ) {
        if ($width < 1) {
            throw new \InvalidArgumentException('Border width must be positive');
        }
    }
}
