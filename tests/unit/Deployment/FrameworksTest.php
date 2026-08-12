<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * A framework's ssr and static adapters describe two views of one build, so
 * their output directories have to come from the same layout. Pointing an
 * adapter at a directory the build never writes yields an empty deployment
 * rather than a build failure, which is why these are asserted directly.
 */
final class FrameworksTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $frameworks;

    protected function setUp(): void
    {
        parent::setUp();

        // Read the file rather than Config, which other suites overwrite.
        $this->frameworks = require __DIR__ . '/../../../app/config/frameworks.php';
    }

    /**
     * TanStack Start >= 1.133 builds vite-native: dist/server/server.js next to
     * dist/client. Only builds that opt into the nitro plugin still emit
     * .output, and those override the directory on the site.
     */
    public function testTanStackStartAdaptersShareViteOutput(): void
    {
        $adapters = $this->frameworks['tanstack-start']['adapters'];

        $this->assertSame('./dist', $adapters['ssr']['outputDirectory']);
        $this->assertSame('./dist/client', $adapters['static']['outputDirectory']);
    }

    /**
     * nuxt generate writes .output/public, and leaves dist as a symlink to it.
     */
    public function testNuxtStaticOutputIsInsideDotOutput(): void
    {
        $adapters = $this->frameworks['nuxt']['adapters'];

        $this->assertSame('./.output', $adapters['ssr']['outputDirectory']);
        $this->assertSame('./.output/public', $adapters['static']['outputDirectory']);
    }

    /**
     * Next.js is the one framework whose static export genuinely leaves the ssr
     * build root, so it is excluded rather than special-cased below.
     */
    public function testStaticOutputStaysInsideSsrOutput(): void
    {
        foreach ($this->frameworks as $key => $framework) {
            if ($key === 'nextjs') {
                continue;
            }

            $ssr = $framework['adapters']['ssr']['outputDirectory'] ?? null;
            $static = $framework['adapters']['static']['outputDirectory'] ?? null;

            if ($ssr === null || $static === null) {
                continue;
            }

            $this->assertStringStartsWith(
                \rtrim($ssr, '/') . '/',
                \rtrim($static, '/') . '/',
                "Framework '{$key}' serves static files from outside its ssr build output."
            );
        }
    }
}
