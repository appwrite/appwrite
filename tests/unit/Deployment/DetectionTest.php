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

    public function testRenderingFindsTanStackStartSSR(): void
    {
        $detection = Detection::rendering('tanstack-start', ['./client/index.html', './server/server.js']);

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    public function testRenderingFindsTanStackStartNitroSSR(): void
    {
        $detection = Detection::rendering('tanstack-start', ['./public/index.html', './server/index.mjs']);

        $this->assertSame('ssr', $detection->getName());
        $this->assertNull($detection->getFallbackFile());
    }

    public function testRenderingFindsTanStackStartStatic(): void
    {
        $detection = Detection::rendering('tanstack-start', ['./index.html', './assets/main.js']);

        $this->assertSame('static', $detection->getName());
        $this->assertSame('index.html', $detection->getFallbackFile());
    }
}
