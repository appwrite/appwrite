<?php

namespace Utopia\Detector\Detection\Framework;

class Svelte extends JS
{
    public function getName(): string
    {
        return 'svelte';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['svelte'], parent::getPackages());
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge(['svelte.config.js', 'svelte.config.mjs', 'svelte.config.ts'], parent::getFiles());
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
}
