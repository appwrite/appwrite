<?php

// Utopia\Image\Image is registered by the utopia PHP extension (crates/extension in
// appwrite/appwrite). This file only declares it for static analysis and editors; it is never loaded.

namespace Utopia\Image;

use Utopia\Image\Exception\CorruptImageException;
use Utopia\Image\Exception\ImageException;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Exception\UnsupportedFormatException;

class Image
{
    public const GRAVITY_CENTER = 'center';

    public const GRAVITY_TOP_LEFT = 'top-left';

    public const GRAVITY_TOP = 'top';

    public const GRAVITY_TOP_RIGHT = 'top-right';

    public const GRAVITY_LEFT = 'left';

    public const GRAVITY_RIGHT = 'right';

    public const GRAVITY_BOTTOM_LEFT = 'bottom-left';

    public const GRAVITY_BOTTOM = 'bottom';

    public const GRAVITY_BOTTOM_RIGHT = 'bottom-right';

    /**
     * @throws UnsupportedFormatException
     * @throws CorruptImageException
     * @throws LimitExceededException
     * @throws ImageException
     */
    public function __construct(string $data)
    {
    }

    /**
     * @return array<string>
     */
    public static function getGravityTypes(): array
    {
    }

    /**
     * A width or height of 0 keeps the aspect ratio. A focal point ($x and $y, each 0 to 1) overrides $gravity.
     *
     * @throws \InvalidArgumentException
     * @throws LimitExceededException
     * @throws ImageException
     */
    public function crop(int $width, int $height, string $gravity = self::GRAVITY_CENTER, ?float $x = null, ?float $y = null): static
    {
    }

    /**
     * @param  int  $borderWidth  The size of the border in pixels
     * @param  string  $borderColor  The color of the border in hex format
     *
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBorder(int $borderWidth, string $borderColor): static
    {
    }

    /**
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBorderRadius(int $cornerRadius): static
    {
    }

    /**
     * @param  float  $opacity  From 0 (transparent) to 1 (unchanged)
     */
    public function setOpacity(float $opacity): static
    {
    }

    /**
     * @throws ImageException
     */
    public function setRotation(int $degree): static
    {
    }

    /**
     * @throws \InvalidArgumentException
     * @throws ImageException
     */
    public function setBackground(string $color): static
    {
    }

    /**
     * @throws UnsupportedFormatException
     * @throws ImageException
     */
    public function output(string $type, int $quality = 75): ?string
    {
    }

    /**
     * Writes to $path and returns null, or returns the encoded image when $path is null.
     *
     * @throws UnsupportedFormatException
     * @throws ImageException
     */
    public function save(?string $path = null, string $type = '', int $quality = 75): ?string
    {
    }

    /**
     * @param  string  $type  One of area, disk, file, map, memory, thread; any other is ignored
     */
    public static function setResourceLimit(string $type, int $value): void
    {
    }
}
