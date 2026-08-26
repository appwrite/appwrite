<?php

declare(strict_types=1);

namespace Tests\Unit\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Providers\Gravatar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class GravatarTest extends TestCase
{
    public static function provideSupports(): \Iterator
    {
        yield 'email' => [['email' => 'walter@appwrite.io'], true];
        yield 'name only' => [['name' => 'Walter White'], false];
        yield 'empty user' => [[], false];
    }

    /**
     * Without a pre-computed hash, photo lookup keys off the user's email.
     */
    #[DataProvider('provideSupports')]
    public function testSupports(array $attributes, bool $expected): void
    {
        $this->assertSame($expected, (new Gravatar())->supports(new Document($attributes)));
    }

    /**
     * A hash passed to the constructor stands in for the address, so a user
     * without an email of their own is still supported.
     */
    public function testSupportsHash(): void
    {
        $provider = new Gravatar(\hash('sha256', 'walter@appwrite.io'));

        $this->assertTrue($provider->supports(new Document([])));
        $this->assertTrue($provider->supports(new Document(['name' => 'Walter White'])));
    }
}
