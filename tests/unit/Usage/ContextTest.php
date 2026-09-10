<?php

declare(strict_types=1);

namespace Tests\Unit\Usage;

use Appwrite\Usage\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testClientDimensionsHaveEmptyDefaults(): void
    {
        $context = new Context();

        $this->assertSame('', $context->getHostname());
        $this->assertSame('', $context->getUserAgent());
        $this->assertSame('', $context->getCountry());
        $this->assertSame('', $context->getSdk());
        $this->assertSame('', $context->getSdkVersion());
    }

    public function testClientDimensionsExposeNormalizedValues(): void
    {
        $context = new Context();
        $context->setHostname('https://EXAMPLE.test:443/path')
            ->setUserAgent('compatibility-test')
            ->setCountry('NZ')
            ->setSdk('php')
            ->setSdkVersion('27.1.0');

        $this->assertSame('example.test', $context->getHostname());
        $this->assertSame('compatibility-test', $context->getUserAgent());
        $this->assertSame('nz', $context->getCountry());
        $this->assertSame('php', $context->getSdk());
        $this->assertSame('27.1.0', $context->getSdkVersion());
    }
}
