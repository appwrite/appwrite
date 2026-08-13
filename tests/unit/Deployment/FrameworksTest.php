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
            // TanStack Start still pairs a nitro ssr directory with a vite-native
            // static one, pending a decision on which layout to default to.
            if ($key === 'tanstack-start') {
                continue;
            }

            $ssr = $framework['adapters']['ssr']['outputDirectory'] ?? null;
            $static = $framework['adapters']['static']['outputDirectory'] ?? null;

            if ($ssr === null || $static === null) {
                continue;
            }

            // An unnested static directory is its own build root, like Next.js ./out.
            if (\substr_count(\rtrim($static, '/'), '/') <= 1) {
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
