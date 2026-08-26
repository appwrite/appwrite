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

    public function testGetIgnoresEmail(): void
    {
        $user = new Document(['email' => 'walter@appwrite.io']);

        $this->assertNull((new Initials())->get($user, 100, 100, 'g'));
    }
}
