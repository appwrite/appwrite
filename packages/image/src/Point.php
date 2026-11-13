<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * A point in an image, each coordinate from 0 (left, top) to 1 (right, bottom).
 */
final readonly class Point
{
    public function __construct(
        public float $x,
        public float $y,
    ) {
        if (! \is_finite($x) || ! \is_finite($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new \InvalidArgumentException('Coordinates must be between 0 and 1');
        }
    }
}
