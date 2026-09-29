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

        // `$code` empties string literals, so `'nitro()'` is never read as a call, and keeps whitespace only between two names.
        // `$compact` drops whitespace and quotes, and keeps a string's text only when it is a key, so `"enabled": false` still reads as one.
        $code = '';
        $compact = '';
        $quote = '';
        $text = '';
        $gap = false;
        for ($i = 0; $i < \strlen($stripped); $i++) {
            $char = $stripped[$i];
            if ($quote !== '') {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote || ($char === "\n" && $quote !== '`')) {
                    $code .= $char;
                    $quote = '';
                    if (\str_starts_with(\ltrim(\substr($stripped, $i + 1)), ':')) {
                        $compact .= $text;
                    }
                } else {
                    $text .= $char;
                }

                continue;
            }

            if (\ctype_space($char)) {
                $gap = true;

                continue;
            }

            $last = \substr($code, -1);
            if ($gap && (\ctype_alnum($last) || \in_array($last, ['_', '$'], true)) && (\ctype_alnum($char) || \in_array($char, ['_', '$'], true))) {
                $code .= ' ';
            }

            $gap = false;
            $code .= $char;
            if (\in_array($char, ['\'', '"', '`'], true)) {
                $quote = $char;
                $text = '';
            } else {
                $compact .= $char;
            }
        }

        // What each Nitro import is called as: `nitro`, `serverPlugin` for `{ nitro as serverPlugin }`, `nitroPlugin.nitro` for `* as nitroPlugin`.
        $calls = [];
        foreach (['nitro/vite' => 'nitro', '@tanstack/nitro-v2-vite-plugin' => 'nitroV2Plugin'] as $module => $export) {
            for ($at = \strpos($stripped, $module); $at !== false; $at = \strpos($stripped, $module, $at + 1)) {
                $import = \substr($stripped, 0, $at);
                if (!\in_array(\substr($import, -1), ['\'', '"'], true) || !\str_ends_with(\rtrim(\substr($import, 0, -1)), 'from')) {
                    continue;
                }

                $names = \array_values(\array_filter(\explode(' ', \str_replace(['{', '}', ',', "\t", "\r", "\n"], ' ', \substr($import, (int) \strrpos($import, 'import '))))));
                $index = \array_search($export, $names, true);
                $calls[] = match (true) {
                    ($names[1] ?? '') === '*' && isset($names[3]) => $names[3] . '.' . $export,
                    $index !== false && ($names[$index + 1] ?? '') === 'as' => $names[$index + 2] ?? '',
                    $index !== false => $export,
                    default => '',
                };
            }
        }

        // Nitro emits `.output/server/index.mjs` even when every route is prerendered.
        foreach (\array_filter($calls) as $call) {
            for ($at = \strpos($code, $call . '('); $at !== false; $at = \strpos($code, $call . '(', $at + 1)) {
                $before = $at > 0 ? $code[$at - 1] : ' ';
                if (\ctype_alnum($before) || \in_array($before, ['_', '$', '.'], true)) {
                    continue;
                }

                // `nitro(...) {` declares a function of that name rather than calling the plugin.
                for ($end = $at + \strlen($call), $depth = 0; $end < \strlen($code); $end++) {
                    $depth += match ($code[$end]) {
                        '(' => 1,
                        ')' => -1,
                        default => 0,
                    };

                    if ($depth === 0) {
                        break;
                    }
                }

                if (($code[$end + 1] ?? '') !== '{') {
                    return 'ssr';
                }
            }
        }

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
