<?php

declare(strict_types=1);

namespace Utopia\Image\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Utopia\Image\Exception\CorruptImageException;
use Utopia\Image\Exception\ImageException;
use Utopia\Image\Exception\LimitExceededException;
use Utopia\Image\Exception\UnsupportedFormatException;
use Utopia\Image\Image;

// The class under test comes from the utopia extension; CI's Extension job runs these with it loaded.
#[RequiresPhpExtension('utopia')]
final class ImageTest extends TestCase
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    private function requireEncoder(string $format): void
    {
        try {
            Image::create(2, 2, '#ffffff')->output(strtolower($format));
        } catch (UnsupportedFormatException $exception) {
            self::markTestSkipped("The {$format} encoder is not usable: {$exception->getMessage()}");
        }
    }

    private function requireDecoder(string $format, string $data): void
    {
        try {
            new Image($data);
        } catch (UnsupportedFormatException $exception) {
            self::markTestSkipped("The {$format} decoder is not usable: {$exception->getMessage()}");
        }
    }

    /**
     * A red picture with rectangles painted over it.
     *
     * @param  array<int, array{int, int, int, int}>  $rectangles  Left, top, right and bottom, keyed by colour
     */
    private function picture(int $width, int $height, array $rectangles = []): \GdImage
    {
        $picture = imagecreatetruecolor(max(1, $width), max(1, $height));
        $this->assertInstanceOf(\GdImage::class, $picture);
        foreach ([0xFF0000 => [0, 0, $width, $height]] + $rectangles as $color => [$left, $top, $right, $bottom]) {
            imagefilledrectangle($picture, $left, $top, $right, $bottom, $color);
        }

        return $picture;
    }

    private function encode(\GdImage $picture, string $type = 'png'): string
    {
        ob_start();
        match ($type) {
            'jpg' => imagejpeg($picture),
            'gif' => imagegif($picture),
            'avif' => imageavif($picture),
            default => imagepng($picture),
        };

        return ob_get_clean() ?: '';
    }

    /**
     * @param  non-empty-list<\GdImage>  $frames
     * @param  int  $delay  Of every frame, in hundredths of a second
     */
    private function animatedGif(array $frames, int $delay): string
    {
        $gif = 'GIF89a' . pack('vv', imagesx($frames[0]), imagesy($frames[0])) . "\0\0\0";
        foreach ($frames as $frame) {
            // GD writes one picture after a global palette, which becomes the frame's own here.
            $single = $this->encode($frame, 'gif');
            $bits = \ord($single[10]) & 7;
            $descriptor = 13 + (6 << $bits);
            $this->assertSame(',', $single[$descriptor]);
            $gif .= "!\xf9\x04\x04" . pack('v', $delay) . "\0\0"
                . substr($single, $descriptor, 9) . \chr(0x80 | $bits)
                . substr($single, 13, 6 << $bits)
                . substr($single, $descriptor + 10, -1);
        }

        return $gif . ';';
    }

    private function decode(string $data): \GdImage
    {
        // GD does not read HEIC, so that is looked at through the PNG the class makes of it.
        if ($this->format($data) === 'image/heic') {
            $data = (new Image($data))->output('png') ?? '';
        }
        $picture = imagecreatefromstring($data);
        $this->assertInstanceOf(\GdImage::class, $picture);

        return $picture;
    }

    private function format(string $data): string
    {
        if (substr($data, 4, 8) === 'ftypheic') {
            return 'image/heic';
        }
        $size = getimagesizefromstring($data);

        return $size === false ? '' : $size['mime'];
    }

    /**
     * @return array{r: int, g: int, b: int, a: int} The alpha is GD's: 0 is opaque and 127 transparent
     */
    private function color(\GdImage $picture, int $x, int $y): array
    {
        $color = imagecolorsforindex($picture, (int) imagecolorat($picture, $x, $y));

        return ['r' => $color['red'], 'g' => $color['green'], 'b' => $color['blue'], 'a' => $color['alpha']];
    }

    private function littleEndian(string $bytes): int
    {
        return (int) hexdec(bin2hex(strrev($bytes)));
    }

    /**
     * @return list<array{int, \GdImage}> Each frame's delay in hundredths of a second, and its picture
     */
    private function frames(string $data): array
    {
        return $this->format($data) === 'image/gif' ? $this->gifFrames($data) : $this->webpFrames($data);
    }

    /**
     * @return list<array{int, \GdImage}>
     */
    private function gifFrames(string $gif): array
    {
        $flags = \ord($gif[10]);
        $head = substr($gif, 0, 13 + (($flags & 0x80) !== 0 ? 6 << ($flags & 7) : 0));
        $frames = [];
        $delay = 0;
        for ($at = \strlen($head); $gif[$at] !== ';';) {
            $block = $at;
            if ($gif[$at] === '!') {
                if ($gif[$at + 1] === "\xf9") {
                    $delay = $this->littleEndian(substr($gif, $at + 4, 2));
                }
                $at += 2;
            } else {
                $flags = \ord($gif[$at + 9]);
                $at += 11 + (($flags & 0x80) !== 0 ? 6 << ($flags & 7) : 0);
            }
            while (($length = \ord($gif[$at++])) !== 0) {
                $at += $length;
            }
            if ($gif[$block] === ',') {
                $frames[] = [$delay, $this->decode($head . substr($gif, $block, $at - $block) . ';')];
            }
        }

        return $frames;
    }

    /**
     * @return list<array{int, \GdImage}>
     */
    private function webpFrames(string $webp): array
    {
        $frames = [];
        for ($at = 12; $at < \strlen($webp); $at += 8 + $size + ($size & 1)) {
            $size = $this->littleEndian(substr($webp, $at + 4, 4));
            if (substr($webp, $at, 4) !== 'ANMF') {
                continue;
            }
            // A frame is its offset, size, delay and flags, then the chunks of a still picture.
            $frame = substr($webp, $at + 8, $size);
            $this->assertSame(0, $this->littleEndian(substr($frame, 0, 6)), 'The frame covers the canvas');
            $still = "VP8X\x0a\0\0\0" . (substr($frame, 16, 4) === 'ALPH' ? "\x10" : "\0") . "\0\0\0" . substr($frame, 6, 6) . substr($frame, 16);
            $frames[] = [
                intdiv($this->littleEndian(substr($frame, 12, 3)), 10),
                $this->decode('RIFF' . pack('V', \strlen($still) + 4) . 'WEBP' . $still),
            ];
        }

        return $frames;
    }

    private function jpegWithExifOrientation(int $orientation): string
    {
        $jpeg = $this->encode($this->picture(20, 10), 'jpg');

        $exif = "Exif\0\0II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0"
            . pack('v', $orientation)
            . "\0\0\0\0\0\0";
        $segment = "\xff\xe1" . pack('n', \strlen($exif) + 2) . $exif;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedFormatProvider(): iterable
    {
        yield 'pixel enumeration text' => ["# ImageMagick pixel enumeration: 1,1,255,srgb\n0,0: (255,0,0)  #FF0000  red\n"];
        yield 'portable pixmap' => ["P3\n1 1\n255\n255 0 0\n"];
        yield 'vector graphics' => ["push graphic-context\nviewbox 0 0 1 1\npop graphic-context\n"];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'];
        yield 'postscript' => ["%!PS-Adobe-3.0\nshowpage\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('unsupportedFormatProvider')]
    public function testRejectsFormatsOutsideTheAllowList(string $data): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('Unsupported image format');

        new Image($data);
    }

    public function testAvifInput(): void
    {
        $this->requireEncoder('AVIF');

        $image = new Image($this->encode($this->picture(20, 10), 'avif'));
        $image->crop(10, 10);

        $output = $this->decode($image->output('png') ?? '');
        $this->assertSame(10, imagesx($output));
        $this->assertSame(10, imagesy($output));
    }

    public function testHeicInput(): void
    {
        $heic = file_get_contents(__DIR__ . '/resources/resize/100x100.heic') ?: '';
        $this->requireDecoder('HEIC', $heic);

        $image = new Image($heic);
        $image->crop(50, 50);

        $output = $this->decode($image->output('png') ?? '');
        $this->assertSame(50, imagesx($output));
        $this->assertSame(50, imagesy($output));
    }

    public function testJpeg(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testPng(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.png';

        $image->crop(100, 100);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    /**
     * @return array<string, array{int, int, int, int, string, int, int}>
     */
    public static function skewedCrops(): array
    {
        return [
            'landscape, 1px wide' => [6, 2, 1, 0, Image::GRAVITY_TOP_LEFT, 1, 1],
            'landscape, 1px wide, center' => [6, 2, 1, 0, Image::GRAVITY_CENTER, 1, 1],
            'landscape, 1px high' => [6, 2, 0, 1, Image::GRAVITY_BOTTOM_RIGHT, 3, 1],
            'portrait, 1px high' => [2, 6, 0, 1, Image::GRAVITY_TOP_LEFT, 1, 1],
            'portrait, 1px high, center' => [2, 6, 0, 1, Image::GRAVITY_CENTER, 1, 1],
            'portrait, 1px wide' => [2, 6, 1, 0, Image::GRAVITY_RIGHT, 1, 3],
        ];
    }

    #[DataProvider('skewedCrops')]
    public function testCropKeepsADerivedSideOfAtLeastOnePixel(int $sourceWidth, int $sourceHeight, int $width, int $height, string $gravity, int $expectedWidth, int $expectedHeight): void
    {
        $image = new Image($this->encode($this->picture($sourceWidth, $sourceHeight)));
        $image->crop($width, $height, $gravity);

        $result = $this->decode($image->output('png', 100) ?: '');

        $this->assertSame($expectedWidth, imagesx($result));
        $this->assertSame($expectedHeight, imagesy($result));
    }

    public function testCropFocalUsesNormalizedCoordinates(): void
    {
        $source = $this->picture(6, 2, [0x008000 => [2, 0, 3, 1], 0x0000FF => [4, 0, 5, 1]]);

        $image = new Image($this->encode($source));
        $image->crop(2, 2, x: 0.75, y: 0.5);

        $result = $this->decode($image->output('png', 100) ?: '');
        $color = $this->color($result, 1, 1);

        $this->assertGreaterThan($color['r'], $color['b']);
        $this->assertGreaterThan($color['g'], $color['b']);
    }

    public function testCropFocalRejectsCoordinatesOutsideTheImage(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: 1.1, y: 0.5);
    }

    public function testCropFocalRejectsNonFiniteCoordinates(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: NAN, y: 0.5);
    }

    public function testCropFocalRequiresBothCoordinates(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: 0.5);
    }

    public function testCropFocalPreservesAnimatedFramesAndDelay(): void
    {
        $frames = [];
        foreach ([0x0000FF, 0x008000] as $color) {
            $frames[] = $this->picture(6, 2, [$color => [4, 0, 5, 1]]);
        }

        $image = new Image($this->animatedGif($frames, 30));
        $image->crop(2, 2, x: 0.75, y: 0.5);

        $outputBlob = $image->output('gif', 100);
        $this->assertNotFalse($outputBlob);
        $this->assertNotNull($outputBlob);

        $frames = $this->frames($outputBlob);
        $this->assertCount(2, $frames);

        $totalDelay = 0;
        $expectedChannels = ['b', 'g'];
        foreach ($frames as $index => [$delay, $frame]) {
            $this->assertSame(2, imagesx($frame));
            $this->assertSame(2, imagesy($frame));
            $color = $this->color($frame, 1, 1);
            $this->assertGreaterThan(100, $color[$expectedChannels[$index]]);
            $totalDelay += $delay;
        }
        $this->assertSame(60, $totalDelay);
    }

    public function testCropGravityNw(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/NW.jpg';
        $original = __DIR__ . '/resources/resize/NW.jpg';

        $image->crop(50, 200, Image::GRAVITY_TOP_LEFT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityN(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/N.gif';
        $original = __DIR__ . '/resources/resize/N.gif';

        $image->crop(100, 50, Image::GRAVITY_TOP);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(50, imagesy($image));
        $this->assertSame('image/gif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityNe(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/NE.jpg';
        $original = __DIR__ . '/resources/resize/NE.jpg';

        $image->crop(50, 200, Image::GRAVITY_TOP_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravitySw(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/SW.jpg';
        $original = __DIR__ . '/resources/resize/SW.jpg';

        $image->crop(50, 200, Image::GRAVITY_BOTTOM_LEFT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityS(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/S.gif';
        $original = __DIR__ . '/resources/resize/S.gif';

        $image->crop(100, 50, Image::GRAVITY_BOTTOM);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(50, imagesy($image));
        $this->assertSame('image/gif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravitySe(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/SE.jpg';
        $original = __DIR__ . '/resources/resize/SE.jpg';

        $image->crop(50, 200, Image::GRAVITY_BOTTOM_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityC(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/C.jpg';
        $original = __DIR__ . '/resources/resize/C.jpg';

        $image->crop(150, 200, Image::GRAVITY_CENTER);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(150, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityW(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/W.gif';
        $original = __DIR__ . '/resources/resize/W.gif';

        $image->crop(50, 100, Image::GRAVITY_LEFT);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/gif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityE(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/E.jpg';
        $original = __DIR__ . '/resources/resize/E.jpg';

        $image->crop(50, 200, Image::GRAVITY_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = $this->decode(file_get_contents($target) ?: '');
        $originalImage = $this->decode(file_get_contents($original) ?: '');
        $this->assertSame(imagesx($originalImage), imagesx($generatedImage));
        $this->assertSame(imagesy($originalImage), imagesy($generatedImage));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($image));
        $this->assertSame(200, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCropGravityPreservesAspectRatio(): void
    {
        $source = $this->picture(2, 4, [0x0000FF => [0, 2, 1, 3]]);

        $image = new Image($this->encode($source));
        $image->crop(4, 2, Image::GRAVITY_TOP);

        $result = $this->decode($image->output('png', 100) ?: '');
        $color = $this->color($result, 2, 1);

        $this->assertGreaterThan($color['b'], $color['r']);
    }

    /**
     * @return \Iterator<string, array{string, bool, string}>
     */
    public static function gravityProvider(): \Iterator
    {
        yield 'horizontal top-left' => [Image::GRAVITY_TOP_LEFT, true, 'r'];
        yield 'horizontal top' => [Image::GRAVITY_TOP, true, 'g'];
        yield 'horizontal top-right' => [Image::GRAVITY_TOP_RIGHT, true, 'b'];
        yield 'horizontal left' => [Image::GRAVITY_LEFT, true, 'r'];
        yield 'horizontal center' => [Image::GRAVITY_CENTER, true, 'g'];
        yield 'horizontal right' => [Image::GRAVITY_RIGHT, true, 'b'];
        yield 'horizontal bottom-left' => [Image::GRAVITY_BOTTOM_LEFT, true, 'r'];
        yield 'horizontal bottom' => [Image::GRAVITY_BOTTOM, true, 'g'];
        yield 'horizontal bottom-right' => [Image::GRAVITY_BOTTOM_RIGHT, true, 'b'];
        yield 'vertical top-left' => [Image::GRAVITY_TOP_LEFT, false, 'r'];
        yield 'vertical top' => [Image::GRAVITY_TOP, false, 'r'];
        yield 'vertical top-right' => [Image::GRAVITY_TOP_RIGHT, false, 'r'];
        yield 'vertical left' => [Image::GRAVITY_LEFT, false, 'g'];
        yield 'vertical center' => [Image::GRAVITY_CENTER, false, 'g'];
        yield 'vertical right' => [Image::GRAVITY_RIGHT, false, 'g'];
        yield 'vertical bottom-left' => [Image::GRAVITY_BOTTOM_LEFT, false, 'b'];
        yield 'vertical bottom' => [Image::GRAVITY_BOTTOM, false, 'b'];
        yield 'vertical bottom-right' => [Image::GRAVITY_BOTTOM_RIGHT, false, 'b'];
    }

    #[DataProvider('gravityProvider')]
    public function testCropGravityPositions(string $gravity, bool $horizontal, string $expectedChannel): void
    {
        $source = $this->picture($horizontal ? 6 : 2, $horizontal ? 2 : 6, [
            0x008000 => [$horizontal ? 2 : 0, $horizontal ? 0 : 2, $horizontal ? 3 : 1, $horizontal ? 1 : 3],
            0x0000FF => [$horizontal ? 4 : 0, $horizontal ? 0 : 4, $horizontal ? 5 : 1, $horizontal ? 1 : 5],
        ]);

        $image = new Image($this->encode($source));
        $image->crop(2, 2, $gravity);

        $result = $this->decode($image->output('png', 100) ?: '');
        $color = $this->color($result, 1, 1);

        $this->assertSame(2, imagesx($result));
        $this->assertSame(2, imagesy($result));
        $this->assertGreaterThan($color[match ($expectedChannel) {
            'r' => 'g',
            default => 'r',
        }], $color[$expectedChannel]);
        $this->assertGreaterThan($color[match ($expectedChannel) {
            'b' => 'g',
            default => 'b',
        }], $color[$expectedChannel]);
    }

    public function testCrop100x400(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x400.jpg';

        $image->crop(100, 400);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(400, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop400x100(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/400x100.jpg';

        $image->crop(400, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(400, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100Webp(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.webp';

        $image->crop(100, 100);

        $image->save($target, 'webp', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');

        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/webp', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100WebpQuality30(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.webp';
        $original = __DIR__ . '/resources/resize/100x100-q30.webp';

        $image->crop(100, 100);

        $image->save($target, 'webp', 30);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(500, filesize($target));
        $this->assertLessThan(2000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');

        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/webp', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testWebpBlobOutput(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');

        $image->crop(100, 100);

        $blob = $image->output('webp', 75);

        $this->assertIsString($blob);
        $this->assertNotEmpty($blob);
        $this->assertSame('RIFF', substr($blob, 0, 4));
        $this->assertSame('WEBP', substr($blob, 8, 4));

        $probe = $this->decode($blob);
        $this->assertSame(100, imagesx($probe));
        $this->assertSame(100, imagesy($probe));
        $this->assertSame('image/webp', $this->format($blob));
    }

    public function testRepeatedOutputAppliesExifRotationOnce(): void
    {
        $image = new Image($this->jpegWithExifOrientation(6));

        $firstBlob = $image->output('png', 100);
        $secondBlob = $image->output('png', 100);
        $this->assertIsString($firstBlob);
        $this->assertIsString($secondBlob);

        $first = $this->decode($firstBlob);
        $second = $this->decode($secondBlob);

        $this->assertSame(10, imagesx($first));
        $this->assertSame(20, imagesy($first));
        $this->assertSame(imagesx($first), imagesx($second));
        $this->assertSame(imagesy($first), imagesy($second));
    }

    public function testSavePreservesImageForSubsequentExports(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/reusable.jpg';

        try {
            $image->save($target, 'jpg', 75);
            $blob = $image->output('png', 75);

            $this->assertIsString($blob);
            $this->assertNotEmpty($blob);

            $probe = $this->decode($blob);
            $this->assertSame('image/png', $this->format($blob));
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
    }

    public function testSaveWritesFilenameZero(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);
        $directory = sys_get_temp_dir() . '/utopia-image-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory));
        $target = $directory . '/0';

        try {
            $this->assertTrue(chdir($directory));
            $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
            $this->assertNull($image->save('0', 'jpg', 75));
            $this->assertFileExists($target);
            $this->assertNotEmpty(file_get_contents($target));
        } finally {
            chdir($cwd);
            if (is_file($target)) {
                unlink($target);
            }
            rmdir($directory);
        }
    }

    public function testWebpFromWebpInput(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/resize/100x100.webp') ?: '');
        $target = __DIR__ . '/roundtrip.webp';

        $image->crop(50, 50);

        $image->save($target, 'webp', 75);

        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $probe = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(50, imagesx($probe));
        $this->assertSame(50, imagesy($probe));
        $this->assertSame('image/webp', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100Avif(): void
    {
        $this->requireEncoder('AVIF');

        $image = new Image(file_get_contents(filename: __DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.avif';

        $image->crop(100, 100);

        $image->save($target, 'avif', 100);

        $this->assertIsReadable($target);
        // AVIF file size can vary based on encoder version and settings
        $fileSize = filesize($target);
        $this->assertGreaterThan(5000, $fileSize, 'AVIF file size is too small');
        $this->assertLessThan(10000, $fileSize, 'AVIF file size is too large');
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/avif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100AvifQuality30(): void
    {
        $this->requireEncoder('AVIF');

        $image = new Image(file_get_contents(filename: __DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.avif';

        $image->crop(100, 100);

        $image->save($target, 'avif', 30);

        $this->assertIsReadable($target);
        $this->assertLessThan(1419, filesize($target));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/avif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100Heic(): void
    {
        $this->requireEncoder('HEIC');

        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.heic';
        $original = __DIR__ . '/resources/resize/100x100.heic';

        $image->crop(100, 100);

        $image->save($target, 'heic', 100);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(500, filesize($target));
        $this->assertLessThan(10000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');

        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/heic', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100HeicQuality30(): void
    {
        $this->requireEncoder('HEIC');

        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.heic';
        $original = __DIR__ . '/resources/resize/100x100.heic';

        $image->crop(100, 100);

        $image->save($target, 'heic', 30);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(500, filesize($target));
        $this->assertLessThan(2081, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');

        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/heic', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100Png(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.png';
        $original = __DIR__ . '/resources/resize/100x100.png';

        $image->crop(100, 100);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(15000, filesize($target));
        $this->assertLessThan(30000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100PngQuality30(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.jpg';
        $original = __DIR__ . '/resources/resize/100x100-q30.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 10);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(500, filesize($target));
        $this->assertLessThan(2000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    public function testCrop100x100Gif(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/100x100.gif';
        $original = __DIR__ . '/resources/resize/100x100.gif';

        $image->crop(100, 100);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(400000, filesize($target));
        $this->assertLessThan(800000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/gif', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testBorder5Red(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/border_5_red.jpg';
        $original = __DIR__ . '/resources/resize/border_5_red.jpg';

        $image->setBorder(5, '#ff0000');

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testRotate45(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/rotate_45.jpg';
        $original = __DIR__ . '/resources/resize/rotate_45.jpg';

        $image->setRotation(45);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame('image/jpeg', $this->format(file_get_contents($target) ?: ''));
        $this->assertSame(2658, imagesy($image));
        $this->assertSame(2659, imagesx($image));
        unlink($target);
    }

    public function testOpacity02(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/opacity_0.2.png';
        $original = __DIR__ . '/resources/resize/opacity_0.2.png';

        $image->setOpacity(0.2);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testBorderRadius500(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/border_radius_500.png';
        $original = __DIR__ . '/resources/resize/border_radius_500.png';

        $image->setBorderRadius(500);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testCrop100Op05(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100_OP_0.5.png';
        $original = __DIR__ . '/resources/resize/100x100_OP_0.5.png';

        $image->crop(100, 100);
        $image->setOpacity(0.5);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(100, imagesx($image));
        $this->assertSame(100, imagesy($image));
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testCrop100BR50(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100_BR_50.png';
        $original = __DIR__ . '/resources/resize/100x100_BR_50.png';

        $image->crop(100, 100);
        $image->setOpacity(0.5);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame('image/png', $this->format(file_get_contents($target) ?: ''));
        unlink($target);
    }

    public function testGifSmallLastFrame(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/last-frame-1px.gif') ?: '');
        $target = __DIR__ . '/last-frame-1px-output.gif';

        $image->crop(0, 0);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = $this->decode(file_get_contents($target) ?: '');
        $this->assertSame(329, imagesx($image));
        $this->assertSame(274, imagesy($image));
        $this->assertSame('image/gif', $this->format(file_get_contents($target) ?: ''));

        unlink($target);
    }

    /**
     * Animated WebP stores delta/partial frames. Cropping without coalesce
     * scales those fragments independently and produces ghosting artifacts.
     */
    public function testCropAnimatedWebpPreservesFrames(): void
    {
        $source = __DIR__ . '/resources/disk-a/anim-delta.webp';
        $image = new Image(file_get_contents($source) ?: '');
        $target = __DIR__ . '/anim-delta-32x32.webp';

        $image->crop(32, 32);
        $image->save($target, 'webp', 100);

        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $blob = file_get_contents($target) ?: '';
        $frames = $this->frames($blob);
        $this->assertGreaterThan(1, \count($frames));
        $this->assertSame('image/webp', $this->format($blob));

        foreach ($frames as [, $frame]) {
            $this->assertSame(32, imagesx($frame));
            $this->assertSame(32, imagesy($frame));
        }

        // Frame 0 has a red square near the top-left; after a correct resize
        // that region must stay red — not blended with later frames.
        $pixel = $this->color($frames[0][1], 8, 8);
        $this->assertGreaterThan(200, $pixel['r']);
        $this->assertLessThan(100, $pixel['g']);
        $this->assertLessThan(100, $pixel['b']);

        // Frame 1 replaces that region with green on a correct crop.
        $pixel = $this->color($frames[1][1], 8, 8);
        $this->assertLessThan(100, $pixel['r']);
        $this->assertGreaterThan(180, $pixel['g']);
        $this->assertLessThan(150, $pixel['b']);

        unlink($target);
    }

    /**
     * Consecutive identical frames are hold/pause frames. Cropping must keep
     * total playback delay — deconstructImages() + WebP encode can zero it out.
     */
    public function testCropAnimatedWebpPreservesHoldFrames(): void
    {
        $red = $this->picture(40, 40);
        $blue = $this->picture(40, 40, [0x0000FF => [0, 0, 39, 39]]);

        $image = new Image($this->animatedGif([$red, $red, $blue], 40));
        $image->crop(20, 20);
        $outputBlob = $image->output('webp', 100);
        $this->assertNotFalse($outputBlob);
        $this->assertNotNull($outputBlob);

        $frames = $this->frames($outputBlob);

        $this->assertSame(120, array_sum(array_column($frames, 0)));
        $this->assertGreaterThanOrEqual(2, \count($frames));

        foreach ($frames as [, $frame]) {
            $this->assertSame(20, imagesx($frame));
            $this->assertSame(20, imagesy($frame));
        }
    }

    public function testCorruptImageThrows(): void
    {
        $png = file_get_contents(__DIR__ . '/resources/resize/100x100.png') ?: '';

        $this->expectException(CorruptImageException::class);
        $this->expectExceptionMessage('Corrupt or truncated image');

        new Image(substr($png, 0, 200));
    }

    public function testUnknownOutputTypeThrows(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/resize/100x100.png') ?: '');

        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage('Unsupported output format: bmp');

        $image->output('bmp');
    }

    public function testDimensionsOverTheLimitThrow(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/resize/100x100.png') ?: '');

        $this->expectException(LimitExceededException::class);
        $this->expectExceptionMessage('Image exceeds the size or memory limits');

        $image->crop(60000, 10);
    }

    public function testEveryImageFailureIsAnImageException(): void
    {
        try {
            new Image('not an image');
            $this->fail('Expected an ImageException');
        } catch (ImageException $exception) {
            $this->assertInstanceOf(UnsupportedFormatException::class, $exception);
        }
    }

    /**
     * @return array<string, array{\Closure(Image): mixed, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'negative width' => [fn (Image $image) => $image->crop(-1, 10), 'Width and height must not be negative'],
            'negative height at a gravity' => [fn (Image $image) => $image->crop(10, -1, Image::GRAVITY_TOP), 'Width and height must not be negative'],
            'negative border' => [fn (Image $image) => $image->setBorder(-1, '#ff0000'), 'Border width must not be negative'],
            'negative radius' => [fn (Image $image) => $image->setBorderRadius(-1), 'Corner radius must not be negative'],
            'unknown border colour' => [fn (Image $image) => $image->setBorder(2, 'no-such-colour'), 'Invalid color: no-such-colour'],
            'unknown background colour' => [fn (Image $image) => $image->setBackground('no-such-colour'), 'Invalid color: no-such-colour'],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsThrow(\Closure $call, string $message): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/resize/100x100.png') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $call($image);
    }

    public function testRoundedCornersAfterABackgroundAreTransparent(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/resources/disk-a/kitten-1.jpg') ?: '');
        $image->crop(256, 256)->setBackground('#336699')->setBorder(6, '#ff0000')->setBorderRadius(40);

        $output = $this->decode($image->output('png') ?? '');

        // Flattening leaves an alpha channel, so the corner is cut away, and the border is stroked.
        $this->assertGreaterThanOrEqual(126, $this->color($output, 1, 1)['a']);
        $edge = $this->color($output, 128, 3);
        $this->assertGreaterThan(200, $edge['r']);
        $this->assertLessThan(60, $edge['g']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rotatedOutputs(): array
    {
        return [
            'jpg, centre' => ['jpg', Image::GRAVITY_CENTER],
            'jpg, gravity' => ['jpg', Image::GRAVITY_TOP_LEFT],
            'webp, gravity' => ['webp', Image::GRAVITY_TOP_LEFT],
            'png, gravity' => ['png', Image::GRAVITY_TOP_LEFT],
            'avif, centre' => ['avif', Image::GRAVITY_CENTER],
            'avif, gravity' => ['avif', Image::GRAVITY_TOP_LEFT],
        ];
    }

    /**
     * The EXIF rotation is applied to the pixels, so the output must not ask a viewer for it again.
     */
    #[DataProvider('rotatedOutputs')]
    public function testARotatedSourceIsTurnedOnce(string $type, string $gravity): void
    {
        if ($type === 'avif') {
            $this->requireEncoder('AVIF');
        }

        // 20x10, tagged as needing a quarter turn: upright it is 10x20.
        $image = new Image($this->jpegWithExifOrientation(6));
        $image->crop(20, 10, $gravity);

        $blob = $image->output($type, 90) ?? '';
        $output = $this->decode($blob);

        $this->assertSame([10, 20], [imagesx($output), imagesy($output)]);
        $this->assertSame(1, (new Image($blob))->getOrientation());
    }
}
