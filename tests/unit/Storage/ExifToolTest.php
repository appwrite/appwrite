<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Appwrite\Storage\ExifTool;
use Imagick;
use ImagickPixel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExifToolTest extends TestCase
{
    private string $dir;

    private ExifTool $exiftool;

    public function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/exiftool-test-' . \uniqid();
        \mkdir($this->dir);
        $this->exiftool = new ExifTool();
    }

    public function tearDown(): void
    {
        $this->exiftool->close();
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formats(): array
    {
        return [
            'jpeg' => ['jpeg'],
            'png' => ['png'],
            'webp' => ['webp'],
            // Same HEIF container as HEIC, which ImageMagick here can read but not encode.
            'avif' => ['avif'],
        ];
    }

    #[DataProvider('formats')]
    public function testStripRemovesPrivateMetadataAndKeepsOrientation(string $format): void
    {
        $path = $this->createPhoto($format);
        $before = $this->readTags($path);
        $this->assertArrayHasKey('GPSLatitude', $before);
        $this->assertArrayHasKey('Make', $before);

        $this->assertTrue($this->exiftool->strip($path));

        $after = $this->readTags($path);
        foreach (['GPSLatitude', 'GPSLongitude', 'Make', 'Model', 'DateTimeOriginal', 'Creator', 'By-line'] as $tag) {
            $this->assertArrayNotHasKey($tag, $after, "{$tag} was not removed from {$format}");
        }
        $this->assertSame('Rotate 90 CW', $after['Orientation'] ?? null);
    }

    public function testStripDoesNotChangePixels(): void
    {
        $path = $this->createPhoto('jpeg');
        $original = (new Imagick($path))->getImageSignature();

        $this->assertTrue($this->exiftool->strip($path));

        $this->assertSame($original, (new Imagick($path))->getImageSignature());
    }

    public function testStripKeepsColorProfile(): void
    {
        // Minimal ICC v2 display profile: a 128 byte header and an empty tag table.
        $icc = \pack('N', 132) . "\0\0\0\0" . \pack('N', 0x02100000) . 'mntr' . 'RGB ' . 'XYZ '
            . \pack('n6', 2026, 1, 1, 0, 0, 0) . 'acsp' . \str_repeat("\0", 24) . \pack('N', 0)
            . \pack('N3', 0x0000F6D6, 0x00010000, 0x0000D32D) . \str_repeat("\0", 48) . \pack('N', 0);

        $path = $this->createPhoto('jpeg', $icc);

        $this->assertTrue($this->exiftool->strip($path));

        $this->assertSame($icc, (new Imagick($path))->getImageProfile('icc'));
        $this->assertArrayNotHasKey('GPSLatitude', $this->readTags($path));
    }

    public function testCorruptFileReturnsFalseAndProcessKeepsWorking(): void
    {
        $corrupt = $this->dir . '/corrupt.jpg';
        \file_put_contents($corrupt, 'not an image');

        $this->assertFalse($this->exiftool->strip($corrupt));
        $this->assertTrue($this->exiftool->strip($this->createPhoto('jpeg')));
    }

    public function testRejectsRelativePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->exiftool->strip('photo.jpg');
    }

    public function testRejectsPathWithLineBreak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->exiftool->strip($this->dir . "/photo.jpg\n-all=");
    }

    public function testResetRecoversAfterProcessStops(): void
    {
        $photo = $this->createPhoto('jpeg');
        $this->exiftool->close();

        try {
            $this->exiftool->strip($photo);
            $this->fail('Expected an exception when the process is not running.');
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(InvalidArgumentException::class, $e);
        }

        $this->assertTrue($this->exiftool->reset());
        $this->assertTrue($this->exiftool->strip($photo));
    }

    private function createPhoto(string $format, ?string $icc = null): string
    {
        $path = $this->dir . '/' . \uniqid() . '.' . $format;

        $image = new Imagick();
        $image->newImage(64, 48, new ImagickPixel('skyblue'));
        $image->setImageFormat($format);
        if ($icc !== null) {
            $image->setImageProfile('icc', $icc);
        }
        $image->writeImage($path);

        \exec('exiftool -q -q -overwrite_original'
            . ' -GPSLatitude=37.7749 -GPSLatitudeRef=N -GPSLongitude=122.4194 -GPSLongitudeRef=W'
            . ' -Make=Apple ' . \escapeshellarg('-Model=iPhone 15') . ' ' . \escapeshellarg('-DateTimeOriginal=2026:10:04 18:22:07')
            . ' -Orientation#=6 -XMP:Creator=Jane -IPTC:By-line=Jane ' . \escapeshellarg($path), $output, $code);
        $this->assertSame(0, $code, 'Failed to write test metadata to ' . $format);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readTags(string $path): array
    {
        \exec('exiftool -j ' . \escapeshellarg($path), $output);

        return \json_decode(\implode('', $output), true)[0] ?? [];
    }
}
