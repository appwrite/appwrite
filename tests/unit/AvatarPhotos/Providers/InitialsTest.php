<?php

declare(strict_types=1);

namespace Tests\Unit\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Providers\Initials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class InitialsTest extends TestCase
{
    public static function provideSupports(): \Iterator
    {
        yield 'name' => [['name' => 'Walter White'], true];
        yield 'zero name' => [['name' => '0'], true];
        yield 'name and email' => [['name' => 'Walter White', 'email' => 'walter@appwrite.io'], true];
        yield 'email only' => [['email' => 'walter@appwrite.io'], false];
        yield 'blank name with email' => [['name' => ' ', 'email' => 'walter@appwrite.io'], false];
        yield 'empty user' => [[], false];
    }

    /**
     * Initials derive from the display name only — an email address must
     * never be used as the label.
     */
    #[DataProvider('provideSupports')]
    public function testSupports(array $attributes, bool $expected): void
    {
        $this->assertSame($expected, (new Initials())->supports(new Document($attributes)));
    }

    /**
     * The initials of '0' are printable, so they must render — a falsy-but-
     * present label previously fell through to the static fallback.
     */
    public function testGetRendersZeroName(): void
    {
        if (!\extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to render initials.');
        }

        $this->assertNotNull((new Initials())->get(new Document(['name' => '0']), 100, 100, 'g'));
    }

    /**
     * A label with no alphanumeric start has no initials to draw, so the
     * provider declines and lets the static fallback answer.
     */
    public function testGetDeclinesUnprintableName(): void
    {
        if (!\extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to render initials.');
        }

        $this->assertNull((new Initials())->get(new Document(['name' => '-']), 100, 100, 'g'));
    }

    public static function provideMultibyteNames(): \Iterator
    {
        yield 'diacritics' => ['José Álvarez', true];
        yield 'latin extended' => ['Łukasz Żółć', true];
        yield 'cyrillic' => ['Анна Каренина', true];
        yield 'greek' => ['Αλέξανδρος Παπαδόπουλος', true];
        yield 'cjk without latin form' => ['山田 太郎', false];
    }

    /**
     * Initials must derive from the first character, not the first byte, of
     * each word. Characters the bundled Latin font cannot draw transliterate
     * to their Latin form (Ł → L, А → A); names with no Latin form at all
     * decline so the static fallback answers instead of a blank square.
     */
    #[DataProvider('provideMultibyteNames')]
    public function testGetRendersMultibyteNames(string $name, bool $renders): void
    {
        if (!\extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to render initials.');
        }

        $photo = (new Initials())->get(new Document(['name' => $name]), 100, 100, 'g');

        $this->assertSame($renders, $photo !== null);
    }

    /**
     * Initials are always drawn uppercase, so the casing a name happens to
     * be written in never changes the avatar.
     */
    public function testGetUppercasesInitials(): void
    {
        $signatures = \array_map(
            fn (string $name) => $this->signature($name),
            ['Walter White', 'walter white', 'wALTER wHITE'],
        );

        $this->assertCount(1, \array_unique($signatures));
    }

    /**
     * Initials are the first letter of every word, so each pair below draws
     * the very same avatar.
     */
    public static function provideEquivalentNames(): \Iterator
    {
        yield 'trailing punctuation' => ['Dr. Emmett Brown Jr.', 'D E B J'];
        yield 'capped at four words' => ['A B C D E F', 'A B C D'];
        yield 'words starting with neither letter nor digit' => ['W (Hello) W', 'W W'];
        yield 'underscores stand in for spaces' => ['walter_white', 'Walter White'];
        yield 'padding and repeated spaces' => ['  Walter   White  ', 'Walter White'];
    }

    #[DataProvider('provideEquivalentNames')]
    public function testGetDerivesInitialsFromWords(string $name, string $equivalent): void
    {
        $this->assertSame($this->signature($equivalent), $this->signature($name));
    }

    /**
     * Guards the equivalences above from passing because every name renders
     * the same square.
     */
    public function testGetVariesByInitials(): void
    {
        $this->assertNotSame($this->signature('Walter White'), $this->signature('Jesse Pinkman'));
    }

    /**
     * Initials draw in the neutral palette shared with the static fallback:
     * one surface for every name, never a per-name colour.
     */
    public function testGetDrawsOnNeutralSurface(): void
    {
        foreach (['Walter White', 'Jesse Pinkman', '0'] as $name) {
            $image = new \Imagick();
            $image->readImageBlob($this->render($name));

            $color = $image->getImagePixelColor(2, 2)->getColor();

            $this->assertSame([79, 79, 79], [$color['r'], $color['g'], $color['b']]);
            $this->assertContains([255, 255, 255], $this->colors($image), 'No letters were drawn on the surface.');
        }
    }

    public function testGetHonoursBackgroundOverride(): void
    {
        $image = new \Imagick();
        $image->readImageBlob((new Initials('123456'))->render('Walter White', 100, 100));

        $color = $image->getImagePixelColor(2, 2)->getColor();

        $this->assertSame([0x12, 0x34, 0x56], [$color['r'], $color['g'], $color['b']]);
    }

    /**
     * Pixel signature of a rendered name — identical initials render
     * identically, and the signature ignores the metadata Imagick stamps
     * into each blob.
     */
    private function signature(string $name): string
    {
        $image = new \Imagick();
        $image->readImageBlob($this->render($name));

        return $image->getImageSignature();
    }

    private function render(string $name): string
    {
        if (!\extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is required to render initials.');
        }

        return (new Initials())->render($name, 100, 100);
    }

    /**
     * Every colour present in the image, as [r, g, b] triplets.
     *
     * @return array<array<int>>
     */
    private function colors(\Imagick $image): array
    {
        $colors = [];

        foreach ($image->getImageHistogram() as $pixel) {
            $color = $pixel->getColor();
            $colors[] = [$color['r'], $color['g'], $color['b']];
        }

        return $colors;
    }

    public function testGetIgnoresEmail(): void
    {
        $user = new Document(['email' => 'walter@appwrite.io']);

        $this->assertNull((new Initials())->get($user, 100, 100, 'g'));
    }
}
