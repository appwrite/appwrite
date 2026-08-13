<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

final class FrameworksTest extends TestCase
{
    protected array $frameworks;

    public function setUp(): void
    {
        $this->frameworks = require('app/config/frameworks.php');
    }

    public function testStaticOutputStaysInsideSsrOutput(): void
    {
        foreach ($this->frameworks as $key => $framework) {
            // Next.js exports a static site to its own root rather than into the ssr build.
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
