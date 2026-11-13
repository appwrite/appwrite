<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * A canvas of one colour, to draw on.
 */
final readonly class Blank
{
    /**
     * @param string $background A CSS colour: a name, `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()` or `rgba()`.
     */
    public function __construct(
        public int $width,
        public int $height,
        public string $background = '#ffffff',
    ) {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Width and height must be positive');
        }
    }
}
