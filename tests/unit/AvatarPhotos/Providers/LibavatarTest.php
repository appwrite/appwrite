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
        yield 'email' => [['email' => 'walter@appwrite.io'], true];
        yield 'email hash' => [['emailHash' => \hash('sha256', 'walter@appwrite.io')], true];
        yield 'email and hash' => [['email' => 'walter@appwrite.io', 'emailHash' => \hash('sha256', 'walter@appwrite.io')], true];
        yield 'name only' => [['name' => 'Walter White'], false];
        yield 'empty user' => [[], false];
    }

    /**
     * A pre-computed SHA-256 hash (`emailHash`) stands in for the raw email
     * address.
     */
    #[DataProvider('provideSupports')]
    public function testSupports(array $attributes, bool $expected): void
    {
        $this->assertSame($expected, (new Libavatar())->supports(new Document($attributes)));
    }
}
