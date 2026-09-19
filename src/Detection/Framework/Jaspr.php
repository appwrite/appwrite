<?php

namespace Utopia\Detector\Detection\Framework;

use Utopia\Detector\Manifest;

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
        $config = Manifest::parse($configContent)['jaspr'] ?? null;

        if (! \is_array($config)) {
            return '';
        }

        return match ($config['mode'] ?? null) {
            'server' => 'ssr',
            'static', 'client' => 'static',
            default => '',
        };
    }
}
