<?php

namespace Utopia\Detector\Detection\Framework;

class SvelteKit extends Svelte
{
    public function getName(): string
    {
        return 'sveltekit';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['@sveltejs/kit'], parent::getPackages());
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge([], parent::getFiles());
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

    public function getOutputDirectory(): string
    {
        return './build';
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
        return ['svelte.config.js', 'svelte.config.mjs', 'svelte.config.ts', 'package.json'];
    }

    public function getAdapter(string $configContent): string
    {
        $stripped = \preg_replace('/(?<!:)\/\/[^\n]*/', '', $configContent) ?? $configContent;

        return \str_contains($stripped, '@sveltejs/adapter-static') ? 'static' : 'ssr';
    }
}
