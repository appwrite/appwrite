<?php

namespace Utopia\Detector\Detection\Framework;

/**
 * Not a Flutter derivative, but detected from the same pubspec files. Extending
 * Flutter makes Jaspr the more specific match, so it wins once a jaspr package
 * shows up and loses an ambiguous pubspec whatever order the options were added in.
 */
class Jaspr extends Flutter
{
    public function getName(): string
    {
        return 'jaspr';
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
        return \array_merge(['jaspr', 'jaspr_builder'], parent::getPackages());
    }

    public function getInstallCommand(): string
    {
        return 'dart pub get';
    }

    public function getBuildCommand(): string
    {
        return 'jaspr build';
    }

    public function getOutputDirectory(): string
    {
        return './build/jaspr';
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
        return ['pubspec.yaml'];
    }

    /**
     * Reads `jaspr.mode`, which builds a server executable in `server` mode
     * and web assets in `static` and `client` mode.
     */
    public function getAdapter(string $configContent): string
    {
        $stripped = \preg_replace('/(^|[ \t])#[^\n]*/m', '$1', $configContent) ?? $configContent;

        if (\preg_match('/^[\x27\x22]?jaspr[\x27\x22]?[ \t]*:(?<body>.*?)(?=^\S|\z)/ms', $stripped, $block) !== 1) {
            return '';
        }

        return match ($this->readOption($block['body'], 'mode')) {
            'server' => 'ssr',
            'static', 'client' => 'static',
            default => '',
        };
    }

    /**
     * Reads a direct child of the jaspr mapping, written in either block or flow style.
     */
    private function readOption(string $body, string $key): ?string
    {
        if (\preg_match('/^[ \t]*\{(?<flow>[^}]*)}/', $body, $flow) === 1) {
            $entries = \explode(',', $flow['flow']);
        } else {
            $lines = \array_values(\array_filter(\explode("\n", $body), fn ($line) => \trim($line) !== ''));

            if (\count($lines) === 0) {
                return null;
            }

            $indent = \min(\array_map(fn ($line) => \strlen($line) - \strlen(\ltrim($line)), $lines));
            $entries = \array_filter($lines, fn ($line) => \strlen($line) - \strlen(\ltrim($line)) === $indent);
        }

        $pattern = '/^[ \t]*[\x27\x22]?'.\preg_quote($key, '/').'[\x27\x22]?[ \t]*:[ \t]*[\x27\x22]?(?<value>[^\x27\x22\s]*)/';

        foreach ($entries as $entry) {
            if (\preg_match($pattern, $entry, $match) === 1) {
                return $match['value'];
            }
        }

        return null;
    }
}
