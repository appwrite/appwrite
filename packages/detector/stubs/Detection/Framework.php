<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Framework extends Detection
{
    protected string $packager = '';

    public function __construct()
    {
    }

    public function setPackager(string $packager): self
    {
    }

    abstract public function getName(): string;

    /**
     * @return array<string> List of relevant files for detection.
     */
    abstract public function getFiles(): array;

    /**
     * @return array<string> List of package names for detection.
     */
    public function getPackages(): array
    {
    }

    abstract public function getInstallCommand(): string;

    abstract public function getBuildCommand(): string;

    abstract public function getOutputDirectory(): string;

    /**
     * @return array<string> Config files to read for adapter detection, in priority order.
     */
    public function getConfigFiles(): array
    {
    }

    /**
     * Detect the adapter ('ssr' or 'static') from config file content.
     * Returns empty string if detection is not possible.
     */
    public function getAdapter(string $configContent): string
    {
    }
}
