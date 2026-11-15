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
     * The mark is drawn as a filled path, so a bare surface means it was
     * never drawn.
     */
    public function testGetDrawsPersonMark(): void
    {
        $colors = \array_merge(...$this->pixels($this->get(100, 100)));

        $this->assertContains(self::SURFACE, $colors);
        $this->assertContains(self::FIGURE, $colors, 'The fallback carries no person mark — it rendered as a bare surface.');
    }

    /**
     * The mark is inset, so every corner is surface.
     */
    public function testGetDrawsOnNeutralSurface(): void
    {
        $pixels = $this->pixels($this->get(100, 100));

        foreach ([[2, 2], [97, 2], [2, 97], [97, 97]] as [$x, $y]) {
            $this->assertSame(self::SURFACE, $pixels[$y][$x], "Pixel at {$x},{$y} is not the neutral surface.");
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
        $pixels = $this->pixels($this->get($width, $height));

        $this->assertCount($expectedHeight, $pixels);
        $this->assertCount($expectedWidth, $pixels[0]);

        // A mark squeezed off a non-square canvas would leave a bare surface
        $this->assertContains(self::FIGURE, \array_merge(...$pixels));
    }

    private function get(int $width, int $height): string
    {
        return (string) (new Fallback())->get(new Document(), $width, $height, 'g');
    }

    /**
     * The image decoded by GD: rows of [r, g, b] triplets, indexed [y][x].
     *
     * @return array<array<array<int>>>
     */
    private function pixels(string $blob): array
    {
        $image = \imagecreatefromstring($blob);
        $this->assertNotFalse($image, 'The fallback is not a decodable image.');

        $pixels = [];

        for ($y = 0; $y < \imagesy($image); $y++) {
            for ($x = 0; $x < \imagesx($image); $x++) {
                $color = \imagecolorsforindex($image, \imagecolorat($image, $x, $y));
                $pixels[$y][$x] = [$color['red'], $color['green'], $color['blue']];
            }
        }

        return $pixels;
    }
}
