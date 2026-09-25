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

    public function getOutputDirectory(): string
    {
        $static = $this->getAdapter($this->config) === 'static';

        if ($this->usesNitro()) {
            return $static ? './.output/public' : './.output';
        }

        return $static ? './dist/client' : './dist';
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
        return ['vite.config.ts', 'vite.config.js', 'vite.config.mjs'];
    }

    private function usesNitro(): bool
    {
        if ($this->config !== '') {
            $stripped = \preg_replace('/(?<!:)\/\/[^\n]*/', '', $this->config) ?? $this->config;

            // Installing the plugin is not registering it, and only a registered one moves the build.
            if (!\preg_match('/\bnitro\w*\s*\(/i', $stripped)) {
                return false;
            }
        }

        $packages = \json_decode($this->packages, true);

        if (!\is_array($packages)) {
            // The scaffold registers the plugin, so an unread manifest is nitro.
            return true;
        }

        $dependencies = \array_merge(
            (array) ($packages['dependencies'] ?? []),
            (array) ($packages['devDependencies'] ?? [])
        );

        foreach (['nitro', 'nitropack', '@tanstack/nitro-v2-vite-plugin'] as $package) {
            if (isset($dependencies[$package])) {
                return true;
            }
        }

        return false;
    }

    public function getAdapter(string $configContent): string
    {
        $stripped = \preg_replace('/(?<!:)\/\/[^\n]*/', '', $configContent) ?? $configContent;

        if (!\preg_match('/\bprerender\b/', $stripped) || \preg_match('/\bprerender[\x27\x22]?\s*:\s*false\b/', $stripped)) {
            return 'ssr';
        }

        \preg_match('/\bprerender[\x27\x22]?\s*:\s*(\{(?:[^{}]|(?1))*\})/s', $stripped, $prerender);

        // Listing routes, filtering them, or switching it off all leave part of the site to a server.
        if (\preg_match('/\b(?:routes|filter)[\x27\x22]?\s*:|\benabled[\x27\x22]?\s*:\s*false\b/', $prerender[1] ?? '')) {
            return 'ssr';
        }

        return 'static';
    }
}
