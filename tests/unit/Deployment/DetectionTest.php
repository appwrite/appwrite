<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Appwrite\Deployment\Detection;
use PHPUnit\Framework\TestCase;

final class DetectionTest extends TestCase
{
    public function testRenderingFindsStaticFallbackFile(): void
    {
        $detection = Detection::rendering('other', ['./main.html']);

        $this->assertSame('static', $detection->getName());
        $this->assertSame('main.html', $detection->getFallbackFile());
    }

    public function testRenderingFindsAstroSSR(): void
    {
        $detection = Detection::rendering('astro', ['./client/index.html', './server/entry.mjs']);

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    /**
     * TanStack Start >= 1.133 builds vite-native, emitting the server entry as
     * dist/server/server.js alongside dist/client.
     */
    public function testRenderingFindsTanStackStartSSR(): void
    {
        $detection = Detection::rendering('tanstack-start', [
            './client/assets/main-BBU0Zbc3.js',
            './server/server.js',
        ]);

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    /**
     * Opting into the nitro plugin keeps the older layout, where the server
     * entry is .output/server/index.mjs.
     */
    public function testRenderingFindsTanStackStartNitroSSR(): void
    {
        $detection = Detection::rendering('tanstack-start', [
            './public/favicon.ico',
            './server/index.mjs',
        ]);

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    /**
     * A prerendered build ships only the client bundle, so no server entry is
     * present to match on.
     */
    public function testRenderingFindsTanStackStartStatic(): void
    {
        $detection = Detection::rendering('tanstack-start', [
            './index.html',
            './assets/main-BBU0Zbc3.js',
        ]);

        $this->assertSame('static', $detection->getName());
        $this->assertSame('index.html', $detection->getFallbackFile());
    }
}
