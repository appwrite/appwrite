<?php

namespace Utopia\Image;

use Utopia\Image\Exception\UnsupportedFormatException;

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

    private const HEADER_LENGTH = 8;

    /**
     * A box size of 1 means a 64-bit size follows the box type, so the brands start after it.
     */
    private const EXTENDED_SIZE = 1;

    private const EXTENDED_HEADER_LENGTH = 16;

    private const BRAND_LENGTH = 4;

    /**
     * Upper bound on the bytes of a file type box that are inspected; real boxes hold a handful of brands.
     */
    private const FILE_TYPE_BOX_LIMIT = 4096;

    /**
     * Identify the format from the file signature, so only decoders for these formats ever see the data.
     *
     * @throws UnsupportedFormatException
     */
    public static function detect(string $data): self
    {
        return match (true) {
            \str_starts_with($data, "\xFF\xD8\xFF") => self::Jpeg,
            \str_starts_with($data, "\x89PNG\r\n\x1A\n") => self::Png,
            \str_starts_with($data, 'GIF87a'), \str_starts_with($data, 'GIF89a') => self::Gif,
            \str_starts_with($data, 'RIFF') && \substr($data, 8, 4) === 'WEBP' => self::Webp,
            \substr($data, 4, 4) === 'ftyp' => self::fromBrands($data),
            default => throw new UnsupportedFormatException(),
        };
    }

    /**
     * @throws UnsupportedFormatException
     */
    private static function fromBrands(string $data): self
    {
        $size = self::readSize($data, 'N', 0);
        $start = self::HEADER_LENGTH;
        if ($size === self::EXTENDED_SIZE) {
            $size = self::readSize($data, 'J', self::HEADER_LENGTH);
            $start = self::EXTENDED_HEADER_LENGTH;
        }
        $end = \min($size, self::FILE_TYPE_BOX_LIMIT, \strlen($data));
        $minorVersion = $start + self::BRAND_LENGTH;

        $heic = false;
        for ($offset = $start; $offset + self::BRAND_LENGTH <= $end; $offset += self::BRAND_LENGTH) {
            if ($offset === $minorVersion) {
                continue;
            }

            $brand = \substr($data, $offset, self::BRAND_LENGTH);
            if (\in_array($brand, self::AVIF_BRANDS, true)) {
                return self::Avif;
            }

            $heic = $heic || \in_array($brand, self::HEIC_BRANDS, true);
        }

        return $heic ? self::Heic : throw new UnsupportedFormatException();
    }

    private static function readSize(string $data, string $format, int $offset): int
    {
        if (\strlen($data) < $offset + ($format === 'J' ? 8 : 4)) {
            return 0;
        }

        $size = \unpack($format . 'size', $data, $offset);

        return \is_array($size) && \is_int($size['size'] ?? null) ? $size['size'] : 0;
    }
}
