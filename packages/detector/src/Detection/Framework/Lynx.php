<?php

namespace Utopia\Detector\Detection\Framework;

class Lynx extends React
{
    public function getName(): string
    {
        return 'lynx';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['@lynx-js/react'], parent::getPackages());
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return \array_merge(['lynx.config.ts', 'lynx.config.js', 'lynx.config.mjs'], parent::getFiles());
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
        return './dist';
    }
}
