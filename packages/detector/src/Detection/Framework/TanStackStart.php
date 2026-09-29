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
        return './.output';
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

        while (($start = \strpos($stripped, '/*')) !== false && ($end = \strpos($stripped, '*/', $start + 2)) !== false) {
            $stripped = \substr($stripped, 0, $start) . \substr($stripped, $end + 2);
        }

        // Empty every string literal, so text such as `'nitro()'` is never read as a call.
        $code = '';
        $quote = '';
        for ($i = 0; $i < \strlen($stripped); $i++) {
            $char = $stripped[$i];
            if ($quote === '') {
                $code .= $char;
                $quote = \in_array($char, ['\'', '"', '`'], true) ? $char : '';
            } elseif ($char === '\\') {
                $i++;
            } elseif ($char === $quote || ($char === "\n" && $quote !== '`')) {
                $code .= $char;
                $quote = '';
            }
        }
        $code = \str_replace([' ', "\t", "\r", "\n"], '', $code);

        // Nitro emits `.output/server/index.mjs` even when every route is prerendered.
        foreach (['nitro/vite' => 'nitro', '@tanstack/nitro-v2-vite-plugin' => 'nitroV2Plugin'] as $module => $export) {
            $specifiers = (string) \strstr((string) \strrchr((string) \strstr($stripped, $module, true), '{'), '}', true);
            foreach (\explode(',', $specifiers) as $specifier) {
                $names = \array_values(\array_filter(\explode(' ', \str_replace(['{', "\t", "\r", "\n"], ' ', $specifier))));
                if (($names[0] ?? '') === $export && \str_contains($code, ($names[2] ?? $export) . '(')) {
                    return 'ssr';
                }
            }
        }

        $compact = \str_replace([' ', "\t", "\r", "\n", '"', "'"], '', $stripped);

        // Cut at the brace that closes the block, so a `({ path }) =>` filter does not end it early.
        $prerender = (string) \strstr($compact, 'prerender:{');
        for ($i = 10, $depth = 0; $i < \strlen($prerender); $i++) {
            $depth += match ($prerender[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                $prerender = \substr($prerender, 0, $i);
                break;
            }
        }

        if (\str_contains($prerender, 'enabled:false')) {
            return 'ssr';
        }

        if (!\preg_match('/\bprerender\b/', $stripped) || \preg_match('/\bprerender[\x27\x22]?\s*:\s*false\b/', $stripped)) {
            return 'ssr';
        }

        return 'static';
    }
}
