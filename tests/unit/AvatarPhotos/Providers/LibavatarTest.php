<?php

declare(strict_types=1);

namespace Tests\Unit\AvatarPhotos\Providers;

use Appwrite\AvatarPhotos\Providers\Libavatar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class LibavatarTest extends TestCase
{
    public static function provideSupports(): \Iterator
    {
        yield 'email hash' => [['emailHash' => \hash('sha256', 'walter@appwrite.io')], true];
        yield 'raw email' => [['email' => 'walter@appwrite.io'], false];
        yield 'name only' => [['name' => 'Walter White'], false];
        yield 'empty profile' => [[], false];
    }

    /**
     * Lookup keys off the profile's pre-computed SHA-256 email hash; a raw
     * email address is never read — the endpoint hashes it before building
     * the profile.
     */
    #[DataProvider('provideSupports')]
    public function testSupports(array $attributes, bool $expected): void
    {
        $this->assertSame($expected, (new Libavatar())->supports(new Document($attributes)));
    }
}
