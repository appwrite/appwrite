<?php

namespace Utopia\Detector\Detection;

use Utopia\Detector\Detection;

abstract class Framework extends Detection
{
    protected string $packager = '';

    protected string $packages = '';

    protected string $config = '';

    public function __construct()
    {
    }

    public function setPackager(string $packager): static
    {
        $this->packager = $packager;

        return $this;
    }

    public function setPackages(string $packages): static
    {
        $this->packages = $packages;

        return $this;
    }

    public function setConfig(string $config): static
    {
        $this->config = $config;

        return $this;
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
        return [];
    }

    abstract public function getInstallCommand(): string;

    abstract public function getBuildCommand(): string;

    abstract public function getOutputDirectory(): string;

    /**
     * @return array<string> Config files to read for adapter detection, in priority order.
     */
    public function getConfigFiles(): array
    {
        return [];
    }

    /**
     * Detect the adapter ('ssr' or 'static') from config file content.
     * Returns empty string if detection is not possible.
     */
    public function getAdapter(string $configContent): string
    {
        return '';
    }
}
