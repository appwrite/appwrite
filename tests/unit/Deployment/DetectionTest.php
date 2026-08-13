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
}
