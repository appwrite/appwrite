<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * One line of text. Glyphs are placed by their advances, without kerning or
 * shaping, and a character the font has no glyph for takes no space.
 */
final readonly class Text
{
    /**
     * @param float $size Pixels an em.
     * @param string $color A CSS colour.
     * @param float $x The horizontal centre of the line, in pixels.
     * @param float $y The baseline, in pixels.
     */
    public function __construct(
        public string $text,
        public Font $font,
        public float $size,
        public string $color,
        public float $x,
        public float $y,
    ) {
        if ($size <= 0 || ! \is_finite($size) || ! \is_finite($x) || ! \is_finite($y)) {
            throw new \InvalidArgumentException('Size must be positive, and the position finite');
        }
    }
}
