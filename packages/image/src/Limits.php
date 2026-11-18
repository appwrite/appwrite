<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * What one image may cost. The defaults are conservative; a caller that wants
 * more says so, and there is no "unlimited".
 */
final readonly class Limits
{
    /**
     * @param int $width The largest width of any image held, decoded or produced, in pixels.
     * @param int $height The largest height of any image held, decoded or produced, in pixels.
     * @param int $memory Bytes of decoded pixels held at once (four a pixel, every frame).
     * @param list<Format> $formats The formats a canvas may be read from.
     */
    public function __construct(
        public int $width = 16_000,
        public int $height = 16_000,
        public int $memory = 512 * 1024 * 1024,
        public array $formats = [Format::Jpeg, Format::Png, Format::Gif, Format::Webp, Format::Avif, Format::Heic],
    ) {
        if ($width < 1 || $height < 1 || $memory < 1) {
            throw new \InvalidArgumentException('Limits must be positive');
        }
        if ($formats === []) {
            throw new \InvalidArgumentException('At least one format must be accepted');
        }
    }
}
