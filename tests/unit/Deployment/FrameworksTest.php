<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class FrameworksTest extends TestCase
{
    private array $frameworks;

    protected function setUp(): void
    {
        parent::setUp();

        // Read the file rather than Config, which sibling suites overwrite.
        $this->frameworks = require __DIR__ . '/../../../app/config/frameworks.php';
    }

    public function testTanStackStartAdaptersUseViteOutput(): void
    {
        $adapters = $this->frameworks['tanstack-start']['adapters'];

        $this->assertSame('./dist', $adapters['ssr']['outputDirectory']);
        $this->assertSame('./dist/client', $adapters['static']['outputDirectory']);
    }

    public function testNuxtStaticOutputIsInsideDotOutput(): void
    {
        $adapters = $this->frameworks['nuxt']['adapters'];

        $this->assertSame('./.output/public', $adapters['static']['outputDirectory']);
    }
}
