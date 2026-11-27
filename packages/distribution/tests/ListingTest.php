<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Listing;
use Utopia\Storage\Device\Local;

final class ListingTest extends TestCase
{
    public function testTextOnlyListingNeedsNoDevice(): void
    {
        $fields = [
            Listing::FIELD_NAME => 'Example',
            Listing::FIELD_SUBTITLE => 'An example app',
            Listing::FIELD_SHORT_DESCRIPTION => 'Short',
            Listing::FIELD_DESCRIPTION => 'Long description',
            Listing::FIELD_KEYWORDS => 'example,app',
            Listing::FIELD_PROMOTIONAL_TEXT => 'Now faster',
            Listing::FIELD_SUPPORT_URL => 'https://example.com/support',
            Listing::FIELD_MARKETING_URL => 'https://example.com',
            Listing::FIELD_PRIVACY_URL => 'https://example.com/privacy',
        ];

        $listing = new Listing('io.example.app', 'en-US', $fields);

        $this->assertSame('io.example.app', $listing->identifier);
        $this->assertSame('en-US', $listing->locale);
        $this->assertSame($fields, $listing->fields);
        $this->assertSame([], $listing->screenshots);
        $this->assertNull($listing->device);
    }

    public function testScreenshotsWithDevice(): void
    {
        $device = new Local(sys_get_temp_dir() . '/distribution');
        $screenshots = [[
            'set' => 'APP_IPHONE_67',
            'path' => 'listings/one/1.png',
            'size' => 1024,
            'width' => 1290,
            'height' => 2796,
            'digest' => 'd41d8cd98f00b204e9800998ecf8427e',
        ]];

        $listing = new Listing('io.example.app', 'en-US', [], $screenshots, $device);

        $this->assertSame($screenshots, $listing->screenshots);
        $this->assertSame($device, $listing->device);
    }

    public function testUnknownFieldThrows(): void
    {
        $this->expectException(Invalid::class);

        new Listing('io.example.app', 'en-US', ['title' => 'Example']);
    }

    public function testScreenshotsWithoutDeviceThrow(): void
    {
        $this->expectException(Invalid::class);

        new Listing('io.example.app', 'en-US', [], [[
            'set' => 'APP_IPHONE_67',
            'path' => 'listings/one/1.png',
            'size' => 1024,
            'width' => 1290,
            'height' => 2796,
            'digest' => 'd41d8cd98f00b204e9800998ecf8427e',
        ]]);
    }
}
