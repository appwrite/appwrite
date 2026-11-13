<?php

declare(strict_types=1);

namespace Utopia\Image;

/**
 * A raster format a canvas can be read from or written as.
 */
enum Format: string
{
    case Jpeg = 'jpg';
    case Png = 'png';
    case Gif = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';
    case Heic = 'heic';

    /**
     * The media type the format is served with.
     */
    public function mime(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Gif => 'image/gif',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
            self::Heic => 'image/heic',
        };
    }
}
