<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * A filled shape, as SVG path data.
 */
final readonly class Path
{
    /**
     * @param string $data The path, as in an SVG `d` attribute.
     * @param string $color A CSS colour to fill it with.
     * @param float $x How far right to move the scaled path, in pixels.
     * @param float $y How far down to move the scaled path, in pixels.
     * @param float $scale The factor the path's own units are multiplied by.
     */
    public function __construct(
        public string $data,
        public string $color,
        public float $x = 0.0,
        public float $y = 0.0,
        public float $scale = 1.0,
    ) {
        if ($scale <= 0 || ! \is_finite($scale) || ! \is_finite($x) || ! \is_finite($y)) {
            throw new \InvalidArgumentException('Scale must be positive, and the offset finite');
        }
    }
}
