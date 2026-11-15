<?php

declare(strict_types=1);

namespace Utopia\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Image\Canvas;
use Utopia\Image\Exception\CorruptImageException;
use Utopia\Image\Exception\UnsupportedFormatException;
use Utopia\Image\Format;
use Utopia\Image\Limits;

// A format is read through a canvas, which the utopia extension provides.
#[RequiresPhpExtension('utopia')]
final class FormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Format}>
     */
    public static function signatureProvider(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0", Format::Jpeg];
        yield 'png' => ["\x89PNG\r\n\x1A\n", Format::Png];
        yield 'gif87a' => ['GIF87a', Format::Gif];
        yield 'gif89a' => ['GIF89a', Format::Gif];
        yield 'webp' => ["RIFF\0\0\0\0WEBPVP8 ", Format::Webp];
        yield 'avif' => [self::fileType('avif', ['avif', 'mif1', 'miaf']), Format::Avif];
        yield 'avif sequence' => [self::fileType('avis', ['avis', 'msf1']), Format::Avif];
        yield 'avif compatible with a heif major brand' => [self::fileType('mif1', ['avif', 'mif1']), Format::Avif];
        yield 'heic' => [self::fileType('heic', ['mif1', 'heic']), Format::Heic];
        yield 'heif' => [self::fileType('mif1', ['mif1', 'heic']), Format::Heic];
        yield 'avif with an extended box size' => [self::extendedFileType('avif', ['avif', 'mif1']), Format::Avif];
        yield 'heic with an extended box size' => [self::extendedFileType('heic', ['mif1', 'heic']), Format::Heic];
    }

    #[DataProvider('signatureProvider')]
    public function testDetect(string $data, Format $expected): void
    {
        $this->assertDetected($expected, $data);
    }

    /**
     * A canvas reads the size with the format, so each signature opens a whole picture here.
     *
     * @return iterable<string, array{string, Format}>
     */
    public static function pictureProvider(): iterable
    {
        $avif = self::picture('avif');
        $heic = \file_get_contents(__DIR__ . '/resources/resize/100x100.heic') ?: '';

        yield 'jpeg' => [self::picture('jpeg'), Format::Jpeg];
        yield 'png' => [self::picture('png'), Format::Png];
        yield 'gif87a' => ['GIF87a' . \substr(self::picture('gif'), 6), Format::Gif];
        yield 'gif89a' => ['GIF89a' . \substr(self::picture('gif'), 6), Format::Gif];
        yield 'webp' => [self::picture('webp'), Format::Webp];
        yield 'avif' => [self::withFileType(self::fileType('avif', ['avif', 'mif1', 'miaf']), $avif), Format::Avif];
        yield 'avif compatible with a heif major brand' => [self::withFileType(self::fileType('mif1', ['avif', 'mif1']), $avif), Format::Avif];
        yield 'heic' => [self::withFileType(self::fileType('heic', ['mif1', 'heic']), $heic), Format::Heic];
        yield 'heif' => [self::withFileType(self::fileType('mif1', ['mif1', 'heic']), $heic), Format::Heic];
        yield 'avif with an extended box size' => [self::withFileType(self::extendedFileType('avif', ['avif', 'mif1']), $avif), Format::Avif];
        yield 'heic with an extended box size' => [self::withFileType(self::extendedFileType('heic', ['mif1', 'heic']), $heic), Format::Heic];
    }

    #[DataProvider('pictureProvider')]
    public function testFormatOfACanvas(string $data, Format $expected): void
    {
        $this->assertSame($expected, (new Canvas($data))->format);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'riff without webp' => ["RIFF\0\0\0\0AVI LIST"];
        yield 'mp4' => [self::fileType('isom', ['isom', 'mp41'])];
        yield 'brand past the file type box' => [self::fileType('isom', ['isom']) . 'avif'];
        yield 'brand past an extended file type box' => [self::extendedFileType('isom', ['isom']) . 'avif'];
    }

    #[DataProvider('unsupportedProvider')]
    public function testDetectRejects(string $data): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('Unsupported image format');

        new Canvas($data);
    }

    public function testDetectReadsOnlyTheStartOfAnOversizedFileTypeBox(): void
    {
        $brands = \str_repeat('isom', 5_000_000);
        $data = \pack('N', 8 + \strlen($brands) + 4) . 'ftyp' . $brands . 'avif';

        \memory_reset_peak_usage();
        $baseline = \memory_get_peak_usage();

        try {
            new Canvas($data);
            $this->fail('An oversized file type box without a supported brand near its start must be refused');
        } catch (UnsupportedFormatException $exception) {
            $this->assertSame('Unsupported image format', $exception->getMessage());
        }

        $this->assertLessThan(1024 * 1024, \memory_get_peak_usage() - $baseline);
    }

    public function testDetectFindsABrandAtTheStartOfAnOversizedFileTypeBox(): void
    {
        $box = 'mif1' . "\0\0\0\0" . 'avif' . \str_repeat('isom', 5_000_000);

        $this->assertDetected(Format::Avif, \pack('N', \strlen($box) + 8) . 'ftyp' . $box);
    }

    /**
     * A signature with no picture behind it makes no canvas to ask, so its format shows in
     * which allow-list lets the data through to the header.
     */
    private function assertDetected(Format $expected, string $signature): void
    {
        $others = \array_values(\array_filter(Format::cases(), fn (Format $format) => $format !== $expected));

        foreach ([[[$expected], CorruptImageException::class], [$others, UnsupportedFormatException::class]] as [$formats, $refusal]) {
            try {
                new Canvas($signature, new Limits(formats: $formats));
                $this->fail('A signature alone is not a picture');
            } catch (CorruptImageException|UnsupportedFormatException $exception) {
                $this->assertInstanceOf($refusal, $exception);
            }
        }
    }

    /**
     * A 4x4 picture, written by GD.
     */
    private static function picture(string $type): string
    {
        $picture = \imagecreatetruecolor(4, 4);
        \ob_start();
        match ($type) {
            'jpeg' => \imagejpeg($picture),
            'gif' => \imagegif($picture),
            'webp' => \imagewebp($picture),
            'avif' => \imageavif($picture),
            default => \imagepng($picture),
        };

        return \ob_get_clean() ?: '';
    }

    /**
     * The file with another file type box in place of its own.
     */
    private static function withFileType(string $fileType, string $file): string
    {
        $size = \unpack('N', $file);
        self::assertIsArray($size);
        self::assertIsInt($size[1]);

        return $fileType . \substr($file, $size[1]);
    }

    /**
     * @param list<string> $compatible
     */
    private static function fileType(string $major, array $compatible): string
    {
        $box = $major . "\0\0\0\0" . \implode('', $compatible);

        return \pack('N', \strlen($box) + 8) . 'ftyp' . $box;
    }

    /**
     * @param list<string> $compatible
     */
    private static function extendedFileType(string $major, array $compatible): string
    {
        $box = $major . "\0\0\0\0" . \implode('', $compatible);

        return \pack('N', 1) . 'ftyp' . \pack('J', \strlen($box) + 16) . $box;
    }
}
