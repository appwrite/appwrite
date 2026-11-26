<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

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
    }

    /**
     * @return array<string>
     */
    public function getPackages(): array
    {
    }

    public function getInstallCommand(): string
    {
    }

    public function getBuildCommand(): string
    {
    }

    public function getOutputDirectory(): string
    {
    }

    /**
     * @return array<string>
     */
    public function getConfigFiles(): array
    {
    }

    /**
     * Reads `jaspr.mode`, which builds a server executable in `server` mode
     * and web assets in `static` and `client` mode.
     */
    public function getAdapter(string $configContent): string
    {
    }
}
