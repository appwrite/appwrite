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
    private const MARK = 'M7.5 6a4.5 4.5 0 1 1 9 0a4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z';

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
     * Draw the person mark centred on the neutral surface, as a PNG.
     *
     * The mark the console draws for a user without a photo: heroicons 'user'
     * solid (MIT), authored on a 24x24 grid. Scaled to just over half the
     * avatar and centred, so it sits like an icon rather than filling the tile.
     *
     * @param int $width  Output width in pixels.
     * @param int $height Output height in pixels.
     * @return string Raw PNG bytes.
     */
    public function render(int $width, int $height): string
    {
        $box = \min($width, $height) * 0.55;

        return (new Canvas(new Blank($width, $height, self::SURFACE)))
            ->draw(new Path(self::MARK, self::FIGURE, ($width - $box) / 2, ($height - $box) / 2, $box / 24))
            ->encode(Format::Png);
    }
}
