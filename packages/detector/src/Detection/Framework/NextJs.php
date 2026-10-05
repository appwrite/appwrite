<?php

namespace Utopia\Detector\Detection\Framework;

class NextJs extends React
{
    public function getName(): string
    {
        return 'nextjs';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['next'], parent::getPackages());
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge(['next.config.js', 'next.config.ts', 'next.config.mjs'], parent::getFiles());
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
        return './.next';
    }
}
