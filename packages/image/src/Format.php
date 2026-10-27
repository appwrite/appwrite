<?php

namespace Utopia\Image;

use Exception;

enum Format: string
{
    case Jpeg = 'JPEG';
    case Png = 'PNG';
    case Gif = 'GIF';
    case Webp = 'WEBP';
    case Avif = 'AVIF';
    case Heic = 'HEIC';

    private const AVIF_BRANDS = ['avif', 'avis'];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /**
     * Identify the format from the file signature, so only decoders for these formats ever see the data.
     *
     * @throws Exception
     */
    public static function detect(string $data): self
    {
        return match (true) {
            \str_starts_with($data, "\xFF\xD8\xFF") => self::Jpeg,
            \str_starts_with($data, "\x89PNG\r\n\x1A\n") => self::Png,
            \str_starts_with($data, 'GIF87a'), \str_starts_with($data, 'GIF89a') => self::Gif,
            \str_starts_with($data, 'RIFF') && \substr($data, 8, 4) === 'WEBP' => self::Webp,
            \substr($data, 4, 4) === 'ftyp' => self::fromBrands($data),
            default => throw new Exception('Unsupported image format'),
        };
    }

    /**
     * @throws Exception
     */
    private static function fromBrands(string $data): self
    {
        $header = \unpack('Nsize', $data);
        $size = \is_array($header) && \is_int($header['size'] ?? null) ? $header['size'] : 0;
        $box = \substr($data, 8, \max(0, $size - 8));
        $brands = \str_split(\substr($box, 0, 4) . \substr($box, 8), 4);

        return match (true) {
            \array_intersect($brands, self::AVIF_BRANDS) !== [] => self::Avif,
            \array_intersect($brands, self::HEIC_BRANDS) !== [] => self::Heic,
            default => throw new Exception('Unsupported image format'),
        };
    }
}
