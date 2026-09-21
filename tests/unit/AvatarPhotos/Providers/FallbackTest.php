<?php

declare(strict_types=1);

namespace Tests\Unit\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Providers\Fallback;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class FallbackTest extends TestCase
{
    /**
     * The neutral surface and figure every generated avatar draws in, shared
     * with the initials square so the two look like one family.
     */
    private const SURFACE = [79, 79, 79];
    private const FIGURE = [255, 255, 255];

    public function testGetName(): void
    {
        $this->assertSame('fallback', (new Fallback())->getName());
    }

    public static function provideSupports(): \Iterator
    {
        yield 'empty profile' => [[]];
        yield 'name only' => [['name' => 'Walter White']];
        yield 'email hash only' => [['emailHash' => \hash('sha256', 'walter@appwrite.io')]];
    }

    /**
     * The fallback answers for anyone — it is the last provider in the chain,
     * so a request must never come back without an avatar.
     */
    #[DataProvider('provideSupports')]
    public function testSupports(array $attributes): void
    {
        $this->assertTrue((new Fallback())->supports(new Document($attributes)));
    }

    public function testGetReturnsPng(): void
    {
        $this->assertStringStartsWith("\x89PNG", $this->get(100, 100));
    }

    /**
     * ImageMagick's security policy can forbid decoding SVG altogether, so
     * the mark has to be drawn with primitives rather than rasterised from
     * the markup.
     */
    public function testGetDrawsPersonMark(): void
    {
        $colors = $this->colors($this->get(100, 100));

        $this->assertContains(self::SURFACE, $colors);
        $this->assertContains(self::FIGURE, $colors, 'The fallback carries no person mark — it rendered as a bare surface.');
    }

    /**
     * The mark is inset, so every corner is surface.
     */
    public function testGetDrawsOnNeutralSurface(): void
    {
        $image = new \Imagick();
        $image->readImageBlob($this->get(100, 100));

        foreach ([[2, 2], [97, 2], [2, 97], [97, 97]] as [$x, $y]) {
            $color = $image->getImagePixelColor($x, $y)->getColor();

            $this->assertSame(self::SURFACE, [$color['r'], $color['g'], $color['b']], "Pixel at {$x},{$y} is not the neutral surface.");
        }
    }

    public static function provideSizes(): \Iterator
    {
        yield 'square' => [100, 100, 100, 100];
        yield 'landscape' => [120, 80, 120, 80];
        yield 'unset falls back to 256' => [0, 0, 256, 256];
    }

    #[DataProvider('provideSizes')]
    public function testGetSizesTheAvatar(int $width, int $height, int $expectedWidth, int $expectedHeight): void
    {
        $image = new \Imagick();
        $image->readImageBlob($this->get($width, $height));

        $this->assertSame($expectedWidth, $image->getImageWidth());
        $this->assertSame($expectedHeight, $image->getImageHeight());

        // A mark squeezed off a non-square canvas would leave a bare surface
        $this->assertContains(self::FIGURE, $this->colors($image->getImageBlob()));
    }

    private function get(int $width, int $height): string
    {
        if (!\extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to draw the fallback.');
        }

        return (string) (new Fallback())->get(new Document(), $width, $height, 'g');
    }

    /**
     * Every colour present in the image, as [r, g, b] triplets.
     *
     * @return array<array<int>>
     */
    private function colors(string $blob): array
    {
        $image = new \Imagick();
        $image->readImageBlob($blob);

        $colors = [];

        foreach ($image->getImageHistogram() as $pixel) {
            $color = $pixel->getColor();
            $colors[] = [$color['r'], $color['g'], $color['b']];
        }

        return $colors;
    }
}
