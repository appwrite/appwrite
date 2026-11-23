<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * One preview: a crop, then opacity, a background, a border, rounded corners
 * and a rotation, in that order. A part left at its default is skipped.
 */
final readonly class Transform
{
    /**
     * @param int $width The width to crop to, in pixels; 0 follows the aspect ratio.
     * @param int $height The height to crop to, in pixels; 0 follows the aspect ratio.
     * @param Gravity $gravity Which part of the image a crop keeps.
     * @param Point|null $focus A point to keep in view, which overrides the gravity.
     * @param float $opacity From 0 (transparent) to 1 (unchanged).
     * @param string|null $background A CSS colour to flatten the image onto.
     * @param int $radius The corner radius, in pixels.
     * @param int $rotation Degrees clockwise.
     */
    public function __construct(
        public int $width = 0,
        public int $height = 0,
        public Gravity $gravity = Gravity::Center,
        public ?Point $focus = null,
        public float $opacity = 1.0,
        public ?string $background = null,
        public ?Border $border = null,
        public int $radius = 0,
        public int $rotation = 0,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Width and height must not be negative');
        }
        if (! \is_finite($opacity) || $opacity < 0 || $opacity > 1) {
            throw new \InvalidArgumentException('Opacity must be between 0 and 1');
        }
        if ($radius < 0) {
            throw new \InvalidArgumentException('Corner radius must not be negative');
        }
    }
}
