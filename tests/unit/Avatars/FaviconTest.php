<?php

declare(strict_types=1);

namespace Tests\Unit\Avatars;

use Appwrite\Avatars\Favicon;
use PHPUnit\Framework\TestCase;

final class FaviconTest extends TestCase
{
    public function testLocateResolvesRelativeIconAgainstPageUrl(): void
    {
        $html = '<html><head><link rel="icon" href="images/icon.ico"></head></html>';

        [$href, $ext] = Favicon::locate($html, 'https://example.com/blog/page');

        $this->assertSame('https://example.com/blog/images/icon.ico', $href);
        $this->assertSame('ico', $ext);
    }

    public function testLocateFallbackKeepsPagePort(): void
    {
        [$href, $ext] = Favicon::locate('<html><head></head></html>', 'http://example.com:8080/blog/page');

        $this->assertSame('http://example.com:8080/favicon.ico', $href);
        $this->assertSame('ico', $ext);
    }

    public function testLocateKeepsAbsoluteIcon(): void
    {
        $html = '<html><head><link rel="icon" href="https://cdn.example/icon.png"></head></html>';

        [$href, $ext] = Favicon::locate($html, 'https://example.com/blog/page');

        $this->assertSame('https://cdn.example/icon.png', $href);
        $this->assertSame('png', $ext);
    }
}
