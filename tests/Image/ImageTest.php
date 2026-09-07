<?php

declare(strict_types=1);

namespace Appwrite\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Image\Image;

final class ImageTest extends TestCase
{
    protected function setUp(): void {}

    protected function tearDown(): void {}

    private function requireEncoder(string $format): void
    {
        if (\Imagick::queryFormats($format) === []) {
            self::markTestSkipped("The {$format} encoder is not available.");
        }

        $probe = new \Imagick();
        try {
            $probe->newImage(1, 1, 'white');
            $probe->setImageFormat($format);
            $blob = $probe->getImageBlob();
        } catch (\ImagickException $exception) {
            self::markTestSkipped("The {$format} encoder is not usable: {$exception->getMessage()}");
        }

        if ($blob === '') {
            self::markTestSkipped("The {$format} encoder produced no output.");
        }
    }

    private function jpegWithExifOrientation(int $orientation): string
    {
        $source = new \Imagick();
        $source->newImage(20, 10, 'red', 'jpg');
        $jpeg = $source->getImageBlob();

        $exif = "Exif\0\0II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0"
            . pack('v', $orientation)
            . "\0\0\0\0\0\0";
        $segment = "\xff\xe1" . pack('n', \strlen($exif) + 2) . $exif;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    public function testJpeg(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testPng(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.png';

        $image->crop(100, 100);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropFocalUsesNormalizedCoordinates(): void
    {
        $source = new \Imagick();
        $source->newImage(6, 2, 'red', 'png');
        $draw = new \ImagickDraw();
        $draw->setFillColor('green');
        $draw->rectangle(2, 0, 3, 1);
        $draw->setFillColor('blue');
        $draw->rectangle(4, 0, 5, 1);
        $source->drawImage($draw);

        $image = new Image($source->getImageBlob());
        $image->crop(2, 2, x: 0.75, y: 0.5);

        $result = new \Imagick();
        $result->readImageBlob($image->output('png', 100) ?: '');
        $color = $result->getImagePixelColor(1, 1)->getColor();

        $this->assertGreaterThan($color['r'], $color['b']);
        $this->assertGreaterThan($color['g'], $color['b']);
    }

    public function testCropFocalRejectsCoordinatesOutsideTheImage(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: 1.1, y: 0.5);
    }

    public function testCropFocalRejectsNonFiniteCoordinates(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: NAN, y: 0.5);
    }

    public function testCropFocalRequiresBothCoordinates(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');

        $this->expectException(\InvalidArgumentException::class);
        $image->crop(100, 100, x: 0.5);
    }

    public function testCropFocalPreservesAnimatedFramesAndDelay(): void
    {
        $sequence = new \Imagick();
        foreach (['blue', 'green'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(6, 2, 'red', 'gif');
            $draw = new \ImagickDraw();
            $draw->setFillColor($color);
            $draw->rectangle(4, 0, 5, 1);
            $frame->drawImage($draw);
            $frame->setImageDelay(30);
            $sequence->addImage($frame);
        }

        $image = new Image($sequence->getImagesBlob());
        $image->crop(2, 2, x: 0.75, y: 0.5);

        $outputBlob = $image->output('gif', 100);
        $this->assertNotFalse($outputBlob);
        $this->assertNotNull($outputBlob);

        $output = new \Imagick();
        $output->readImageBlob($outputBlob);
        $this->assertSame(2, $output->getNumberImages());

        $totalDelay = 0;
        $expectedChannels = ['b', 'g'];
        foreach ($output->coalesceImages() as $index => $frame) {
            $this->assertSame(2, $frame->getImageWidth());
            $this->assertSame(2, $frame->getImageHeight());
            $color = $frame->getImagePixelColor(1, 1)->getColor();
            $this->assertGreaterThan(100, $color[$expectedChannels[$index]]);
            $totalDelay += $frame->getImageDelay();
        }
        $this->assertSame(60, $totalDelay);
    }

    public function testCropGravityNw(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/NW.jpg';
        $original = __DIR__ . '/../resources/resize/NW.jpg';

        $image->crop(50, 200, Image::GRAVITY_TOP_LEFT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityN(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/N.gif';
        $original = __DIR__ . '/../resources/resize/N.gif';

        $image->crop(100, 50, Image::GRAVITY_TOP);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(50, $image->getImageHeight());
        $this->assertSame('GIF', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityNe(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/NE.jpg';
        $original = __DIR__ . '/../resources/resize/NE.jpg';

        $image->crop(50, 200, Image::GRAVITY_TOP_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravitySw(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/SW.jpg';
        $original = __DIR__ . '/../resources/resize/SW.jpg';

        $image->crop(50, 200, Image::GRAVITY_BOTTOM_LEFT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityS(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/S.gif';
        $original = __DIR__ . '/../resources/resize/S.gif';

        $image->crop(100, 50, Image::GRAVITY_BOTTOM);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(50, $image->getImageHeight());
        $this->assertSame('GIF', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravitySe(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/SE.jpg';
        $original = __DIR__ . '/../resources/resize/SE.jpg';

        $image->crop(50, 200, Image::GRAVITY_BOTTOM_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityC(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/C.jpg';
        $original = __DIR__ . '/../resources/resize/C.jpg';

        $image->crop(150, 200, Image::GRAVITY_CENTER);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(150, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityW(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/W.gif';
        $original = __DIR__ . '/../resources/resize/W.gif';

        $image->crop(50, 100, Image::GRAVITY_LEFT);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('GIF', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityE(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/E.jpg';
        $original = __DIR__ . '/../resources/resize/E.jpg';

        $image->crop(50, 200, Image::GRAVITY_RIGHT);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));
        // Verify the image was properly cropped with the expected gravity
        $generatedImage = new \Imagick($target);
        $originalImage = new \Imagick($original);
        $this->assertSame($originalImage->getImageWidth(), $generatedImage->getImageWidth());
        $this->assertSame($originalImage->getImageHeight(), $generatedImage->getImageHeight());

        $image = new \Imagick($target);
        $this->assertSame(50, $image->getImageWidth());
        $this->assertSame(200, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCropGravityPreservesAspectRatio(): void
    {
        $source = new \Imagick();
        $source->newImage(2, 4, 'red', 'png');

        $draw = new \ImagickDraw();
        $draw->setFillColor('blue');
        $draw->rectangle(0, 2, 1, 3);
        $source->drawImage($draw);

        $image = new Image($source->getImageBlob());
        $image->crop(4, 2, Image::GRAVITY_TOP);

        $result = new \Imagick();
        $result->readImageBlob($image->output('png', 100) ?: '');
        $color = $result->getImagePixelColor(2, 1)->getColor();

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
        $source = new \Imagick();
        $source->newImage($horizontal ? 6 : 2, $horizontal ? 2 : 6, 'red', 'png');

        $draw = new \ImagickDraw();
        $draw->setFillColor('green');
        $draw->rectangle($horizontal ? 2 : 0, $horizontal ? 0 : 2, $horizontal ? 3 : 1, $horizontal ? 1 : 3);
        $draw->setFillColor('blue');
        $draw->rectangle($horizontal ? 4 : 0, $horizontal ? 0 : 4, $horizontal ? 5 : 1, $horizontal ? 1 : 5);
        $source->drawImage($draw);

        $image = new Image($source->getImageBlob());
        $image->crop(2, 2, $gravity);

        $result = new \Imagick();
        $result->readImageBlob($image->output('png', 100) ?: '');
        $color = $result->getImagePixelColor(1, 1)->getColor();

        $this->assertSame(2, $result->getImageWidth());
        $this->assertSame(2, $result->getImageHeight());
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
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x400.jpg';

        $image->crop(100, 400);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(400, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop400x100(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/400x100.jpg';

        $image->crop(400, 100);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(400, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100Webp(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.webp';

        $image->crop(100, 100);

        $image->save($target, 'webp', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);

        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertContains($image->getImageFormat(), ['PAM', 'WEBP']);

        unlink($target);
    }

    public function testCrop100x100WebpQuality30(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.webp';
        $original = __DIR__ . '/../resources/resize/100x100-q30.webp';

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

        $image = new \Imagick($target);

        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertContains($image->getImageFormat(), ['PAM', 'WEBP']);

        unlink($target);
    }

    public function testWebpBlobOutput(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');

        $image->crop(100, 100);

        $blob = $image->output('webp', 75);

        $this->assertIsString($blob);
        $this->assertNotEmpty($blob);
        $this->assertSame('RIFF', substr($blob, 0, 4));
        $this->assertSame('WEBP', substr($blob, 8, 4));

        $probe = new \Imagick();
        $probe->readImageBlob($blob);
        $this->assertSame(100, $probe->getImageWidth());
        $this->assertSame(100, $probe->getImageHeight());
        $this->assertContains($probe->getImageFormat(), ['PAM', 'WEBP']);
    }

    public function testRepeatedOutputAppliesExifRotationOnce(): void
    {
        $image = new Image($this->jpegWithExifOrientation(6));

        $firstBlob = $image->output('png', 100);
        $secondBlob = $image->output('png', 100);
        $this->assertIsString($firstBlob);
        $this->assertIsString($secondBlob);

        $first = new \Imagick();
        $first->readImageBlob($firstBlob);
        $second = new \Imagick();
        $second->readImageBlob($secondBlob);

        $this->assertSame(10, $first->getImageWidth());
        $this->assertSame(20, $first->getImageHeight());
        $this->assertSame($first->getImageWidth(), $second->getImageWidth());
        $this->assertSame($first->getImageHeight(), $second->getImageHeight());
    }

    public function testSavePreservesImageForSubsequentExports(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/reusable.jpg';

        try {
            $image->save($target, 'jpg', 75);
            $blob = $image->output('png', 75);

            $this->assertIsString($blob);
            $this->assertNotEmpty($blob);

            $probe = new \Imagick();
            $probe->readImageBlob($blob);
            $this->assertSame('PNG', $probe->getImageFormat());
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
            $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
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
        $image = new Image(file_get_contents(__DIR__ . '/../resources/resize/100x100.webp') ?: '');
        $target = __DIR__ . '/roundtrip.webp';

        $image->crop(50, 50);

        $image->save($target, 'webp', 75);

        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $probe = new \Imagick($target);
        $this->assertSame(50, $probe->getImageWidth());
        $this->assertSame(50, $probe->getImageHeight());
        $this->assertContains($probe->getImageFormat(), ['PAM', 'WEBP']);

        unlink($target);
    }

    public function testCrop100x100Avif(): void
    {
        $this->requireEncoder('AVIF');

        $image = new Image(file_get_contents(filename: __DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
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

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('AVIF', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100AvifQuality30(): void
    {
        $this->requireEncoder('AVIF');

        $image = new Image(file_get_contents(filename: __DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.avif';

        $image->crop(100, 100);

        $image->save($target, 'avif', 30);

        $this->assertIsReadable($target);
        $this->assertLessThan(1419, filesize($target));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('AVIF', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100Heic(): void
    {
        $this->requireEncoder('HEIC');

        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.heic';
        $original = __DIR__ . '/../resources/resize/100x100.heic';

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

        $image = new \Imagick($target);

        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('HEIC', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100HeicQuality30(): void
    {
        $this->requireEncoder('HEIC');

        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.heic';
        $original = __DIR__ . '/../resources/resize/100x100.heic';

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

        $image = new \Imagick($target);

        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('HEIC', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100Png(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100.png';
        $original = __DIR__ . '/../resources/resize/100x100.png';

        $image->crop(100, 100);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(15000, filesize($target));
        $this->assertLessThan(30000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100PngQuality30(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100-q30.jpg';
        $original = __DIR__ . '/../resources/resize/100x100-q30.jpg';

        $image->crop(100, 100);

        $image->save($target, 'jpg', 10);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(500, filesize($target));
        $this->assertLessThan(2000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('JPEG', $image->getImageFormat());

        unlink($target);
    }

    public function testCrop100x100Gif(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-3.gif') ?: '');
        $target = __DIR__ . '/100x100.gif';
        $original = __DIR__ . '/../resources/resize/100x100.gif';

        $image->crop(100, 100);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertGreaterThan(400000, filesize($target));
        $this->assertLessThan(800000, filesize($target));
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('GIF', $image->getImageFormat());
        unlink($target);
    }

    public function testBorder5Red(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/border_5_red.jpg';
        $original = __DIR__ . '/../resources/resize/border_5_red.jpg';

        $image->setBorder(5, '#ff0000');

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame('JPEG', $image->getImageFormat());
        unlink($target);
    }

    public function testRotate45(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/rotate_45.jpg';
        $original = __DIR__ . '/../resources/resize/rotate_45.jpg';

        $image->setRotation(45);

        $image->save($target, 'jpg', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame('JPEG', $image->getImageFormat());
        $this->assertSame(2658, $image->getImageHeight());
        $this->assertSame(2659, $image->getImageWidth());
        unlink($target);
    }

    public function testOpacity02(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/opacity_0.2.png';
        $original = __DIR__ . '/../resources/resize/opacity_0.2.png';

        $image->setOpacity(0.2);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame('PNG', $image->getImageFormat());
        unlink($target);
    }

    public function testBorderRadius500(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/border_radius_500.png';
        $original = __DIR__ . '/../resources/resize/border_radius_500.png';

        $image->setBorderRadius(500);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame('PNG', $image->getImageFormat());
        unlink($target);
    }

    public function testCrop100Op05(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100_OP_0.5.png';
        $original = __DIR__ . '/../resources/resize/100x100_OP_0.5.png';

        $image->crop(100, 100);
        $image->setOpacity(0.5);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(100, $image->getImageWidth());
        $this->assertSame(100, $image->getImageHeight());
        $this->assertSame('PNG', $image->getImageFormat());
        unlink($target);
    }

    public function testCrop100BR50(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/kitten-1.jpg') ?: '');
        $target = __DIR__ . '/100x100_BR_50.png';
        $original = __DIR__ . '/../resources/resize/100x100_BR_50.png';

        $image->crop(100, 100);
        $image->setOpacity(0.5);

        $image->save($target, 'png', 100);

        $this->assertIsReadable($target);
        $this->assertEquals(mime_content_type($target), mime_content_type($original));
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame('PNG', $image->getImageFormat());
        unlink($target);
    }

    public function testGifSmallLastFrame(): void
    {
        $image = new Image(file_get_contents(__DIR__ . '/../resources/disk-a/last-frame-1px.gif') ?: '');
        $target = __DIR__ . '/last-frame-1px-output.gif';

        $image->crop(0, 0);

        $image->save($target, 'gif', 100);

        $this->assertIsReadable($target);
        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $image = new \Imagick($target);
        $this->assertSame(329, $image->getImageWidth());
        $this->assertSame(274, $image->getImageHeight());
        $this->assertSame('GIF', $image->getImageFormat());

        unlink($target);
    }

    /**
     * Animated WebP stores delta/partial frames. Cropping without coalesce
     * scales those fragments independently and produces ghosting artifacts.
     */
    public function testCropAnimatedWebpPreservesFrames(): void
    {
        $source = __DIR__ . '/../resources/disk-a/anim-delta.webp';
        $image = new Image(file_get_contents($source) ?: '');
        $target = __DIR__ . '/anim-delta-32x32.webp';

        $image->crop(32, 32);
        $image->save($target, 'webp', 100);

        $this->assertFileExists($target);
        $this->assertNotEmpty(file_get_contents($target));

        $output = new \Imagick($target);
        $this->assertGreaterThan(1, $output->getNumberImages());
        $this->assertContains($output->getImageFormat(), ['PAM', 'WEBP']);

        $coalesced = $output->coalesceImages();
        foreach ($coalesced as $frame) {
            $this->assertSame(32, $frame->getImageWidth());
            $this->assertSame(32, $frame->getImageHeight());
        }

        // Frame 0 has a red square near the top-left; after a correct resize
        // that region must stay red — not blended with later frames.
        $coalesced->setFirstIterator();
        $pixel = $coalesced->getImagePixelColor(8, 8)->getColor();
        $this->assertGreaterThan(200, $pixel['r']);
        $this->assertLessThan(100, $pixel['g']);
        $this->assertLessThan(100, $pixel['b']);

        // Frame 1 replaces that region with green on a correct crop.
        $coalesced->nextImage();
        $pixel = $coalesced->getImagePixelColor(8, 8)->getColor();
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
        $sequence = new \Imagick();
        foreach (['#ff0000', '#ff0000', '#0000ff'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(40, 40, new \ImagickPixel($color));
            $frame->setImageDelay(40);
            $frame->setImageDispose(\Imagick::DISPOSE_NONE);
            $frame->setImageFormat('gif');
            $sequence->addImage($frame);
        }

        $blob = $sequence->getImagesBlob();
        $this->assertNotFalse($blob);

        $image = new Image($blob);
        $image->crop(20, 20);
        $outputBlob = $image->output('webp', 100);
        $this->assertNotFalse($outputBlob);
        $this->assertNotNull($outputBlob);

        $output = new \Imagick();
        $output->readImageBlob($outputBlob);

        $totalDelay = 0;
        foreach ($output as $frame) {
            $totalDelay += $frame->getImageDelay();
        }
        $this->assertSame(120, $totalDelay);
        $this->assertGreaterThanOrEqual(2, $output->getNumberImages());

        $coalesced = $output->coalesceImages();
        foreach ($coalesced as $frame) {
            $this->assertSame(20, $frame->getImageWidth());
            $this->assertSame(20, $frame->getImageHeight());
        }
    }
}
