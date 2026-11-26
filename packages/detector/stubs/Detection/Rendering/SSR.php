<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detection\Rendering;

use Utopia\Detector\Detection\Rendering;

class SSR extends Rendering
{
    public const FRAMEWORK_FILES = [
        'nextjs' => ['.next/server/webpack-runtime.js', '.next/turbopack', 'server.js'],
        'nuxt' => ['server/index.mjs'],
        'sveltekit' => ['handler.js'],
        'astro' => ['server/entry.mjs'],
        'remix' => ['build/server/index.js'],
        'angular' => ['server/server.mjs'],
        'analog' => ['server/index.mjs'],
        'tanstack-start' => ['server/server.js', 'server/index.mjs'],
        'flutter' => [],
        'jaspr' => ['app'],
        'lynx' => [],
    ];

    /**
     * @return array<string>
     */
    public function getFiles(string $framework): array
    {
    }

    public function getName(): string
    {
    }
}
