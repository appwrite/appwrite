<?php

namespace Utopia\Detector\Detection\Framework;

class Remix extends React
{
    public function getName(): string
    {
        return 'remix';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['@remix-run/react'], parent::getPackages());
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge(['remix.config.js', 'remix.config.ts', 'remix.config.mjs'], parent::getFiles());
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
        return ['package.json'];
    }

    public function getAdapter(string $configContent): string
    {
        return 'ssr';
    }
}
