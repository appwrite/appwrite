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

    private const MAJOR_BRAND_OFFSET = 8;

    private const MINOR_VERSION_OFFSET = 12;

    private const BRAND_LENGTH = 4;

    /**
     * Upper bound on the bytes of a file type box that are inspected; real boxes hold a handful of brands.
     */
    private const FILE_TYPE_BOX_LIMIT = 4096;

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
        $end = \min($size, self::FILE_TYPE_BOX_LIMIT, \strlen($data));

        $heic = false;
        for ($offset = self::MAJOR_BRAND_OFFSET; $offset + self::BRAND_LENGTH <= $end; $offset += self::BRAND_LENGTH) {
            if ($offset === self::MINOR_VERSION_OFFSET) {
                continue;
            }

            $brand = \substr($data, $offset, self::BRAND_LENGTH);
            if (\in_array($brand, self::AVIF_BRANDS, true)) {
                return self::Avif;
            }

            $heic = $heic || \in_array($brand, self::HEIC_BRANDS, true);
        }

        return $heic ? self::Heic : throw new Exception('Unsupported image format');
    }
}
