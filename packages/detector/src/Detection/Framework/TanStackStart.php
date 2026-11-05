<?php

namespace Utopia\Detector\Detection\Framework;

class TanStackStart extends React
{
    public function getName(): string
    {
        return 'tanstack-start';
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge([], parent::getFiles());
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['@tanstack/react-start', '@tanstack/solid-start'], parent::getPackages());
    }

    public function getInstallCommand(): string
    {
        return match ($this->packager) {
            'yarn' => 'yarn install',
            'pnpm' => 'pnpm install',
            'npm' => 'npm install',
            default => 'pnpm install',
        };
    }

    public function getBuildCommand(): string
    {
        return match ($this->packager) {
            'yarn' => 'yarn build',
            'pnpm' => 'pnpm run build',
            'npm' => 'npm run build',
            default => 'pnpm run build',
        };
    }

    /**
     * Nitro builds into `.output`. Without a deployment adapter, which is what
     * the TanStack CLI scaffolds when it runs without prompts, Start builds into `dist`.
     */
    public function getOutputDirectory(): string
    {
        foreach (['nitro', 'nitropack', '@tanstack/nitro-v2-vite-plugin'] as $package) {
            if (\in_array($package, $this->dependencies, true)) {
                return './.output';
            }
        }

        return './dist';
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
        return ['vite.config.ts', 'vite.config.js', 'vite.config.mjs'];
    }

    public function getAdapter(string $configContent): string
    {
        $stripped = \preg_replace('/(?<!:)\/\/[^\n]*/', '', $configContent) ?? $configContent;

        // Nitro emits `.output/server/index.mjs` even when every route is prerendered.
        if (\str_contains($stripped, 'nitro/vite') || \str_contains($stripped, '@tanstack/nitro-v2-vite-plugin')) {
            return 'ssr';
        }

        if (!\preg_match('/\bprerender\b/', $stripped) || \preg_match('/\bprerender[\x27\x22]?\s*:\s*false\b/', $stripped)) {
            return 'ssr';
        }

        return 'static';
    }
}
