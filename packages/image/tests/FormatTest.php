<?php

declare(strict_types=1);

namespace Utopia\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Image\Format;

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
    }

    #[DataProvider('signatureProvider')]
    public function testDetect(string $data, Format $expected): void
    {
        $this->assertSame($expected, Format::detect($data));
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
    }

    #[DataProvider('unsupportedProvider')]
    public function testDetectRejects(string $data): void
    {
        $this->expectExceptionMessage('Unsupported image format');

        Format::detect($data);
    }

    /**
     * @param list<string> $compatible
     */
    private static function fileType(string $major, array $compatible): string
    {
        $box = $major . "\0\0\0\0" . \implode('', $compatible);

        return \pack('N', \strlen($box) + 8) . 'ftyp' . $box;
    }
}
