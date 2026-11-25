<?php

namespace Appwrite\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Photo;
use Utopia\Database\Document;
use Utopia\Image\Blank;
use Utopia\Image\Canvas;
use Utopia\Image\Format;
use Utopia\Image\Path;

/**
 * Static fallback provider, a minimal built-in avatar placeholder
 */
class Fallback extends Photo
{
    public const USER = 'M7.5 6a4.5 4.5 0 1 1 9 0a4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z';

    public const TEAM = 'M8.25 6.75a3.75 3.75 0 1 1 7.5 0a3.75 3.75 0 0 1-7.5 0ZM15.75 9.75a3 3 0 1 1 6 0a3 3 0 0 1-6 0ZM2.25 9.75a3 3 0 1 1 6 0a3 3 0 0 1-6 0ZM6.31 15.117A6.745 6.745 0 0 1 12 12a6.745 6.745 0 0 1 6.709 7.498a.75 .75 0 0 1-.372 .568A12.696 12.696 0 0 1 12 21.75c-2.305 0-4.47-.612-6.337-1.684a.75 .75 0 0 1-.372-.568a6.787 6.787 0 0 1 1.019-4.38ZM5.082 14.254a8.287 8.287 0 0 0-1.308 5.135a9.687 9.687 0 0 1-1.764-.44l-.115-.04a.563 .563 0 0 1-.373-.487l-.01-.121a3.75 3.75 0 0 1 3.57-4.047ZM20.226 19.389a8.287 8.287 0 0 0-1.308-5.135a3.75 3.75 0 0 1 3.57 4.047l-.01 .121a.563 .563 0 0 1-.373 .486l-.115 .04c-.567 .2-1.156 .349-1.764 .441Z';

    /**
     * @param string $mark SVG path on a 24x24 grid, heroicons 'user' or 'user-group' solid (MIT).
     */
    public function __construct(private readonly string $mark = self::USER)
    {
    }

    public function getName(): string
    {
        return 'fallback';
    }

    public function supports(Document $profile): bool
    {
        return true;
    }

    public function get(Document $profile, int $width, int $height, string $rating): ?string
    {
        return $this->render($width > 0 ? $width : 256, $height > 0 ? $height : 256);
    }

    /**
     * Draw the mark centred on the neutral surface, as a PNG.
     *
     * Scaled to just over half the avatar and centred, so it sits like an icon
     * rather than filling the tile.
     *
     * @param int $width  Output width in pixels.
     * @param int $height Output height in pixels.
     * @return string Raw PNG bytes.
     */
    public function render(int $width, int $height): string
    {
        $box = \min($width, $height) * 0.55;

        return (new Canvas(new Blank($width, $height, self::SURFACE)))
            ->draw(new Path($this->mark, self::FIGURE, ($width - $box) / 2, ($height - $box) / 2, $box / 24))
            ->encode(Format::Png);
    }
}
