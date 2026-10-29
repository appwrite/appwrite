<?php

namespace Utopia\Migration\Resources\Sites;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Site extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param string $framework
     * @param string $buildRuntime
     * @param bool $enabled
     * @param bool $logging
     * @param int $timeout
     * @param string $installCommand
     * @param string $buildCommand
     * @param string $outputDirectory
     * @param string $adapter
     * @param string $fallbackFile
     * @param string $specification
     * @param string $activeDeployment
     * @param string $buildSpecification
     * @param string $startCommand
     * @param int $deploymentRetention
     * @param array<string> $scopes
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly string $framework,
        private readonly string $buildRuntime,
        private readonly bool $enabled = true,
        private readonly bool $logging = true,
        private readonly int $timeout = 600,
        private readonly string $installCommand = '',
        private readonly string $buildCommand = '',
        private readonly string $outputDirectory = '',
        private readonly string $adapter = 'static',
        private readonly string $fallbackFile = '',
        private readonly string $specification = '',
        private readonly string $activeDeployment = '',
        private readonly string $buildSpecification = '',
        private readonly string $startCommand = '',
        private readonly int $deploymentRetention = 0,
        private readonly array $scopes = []
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return (new self(
            $array['id'],
            $array['name'],
            $array['framework'],
            $array['buildRuntime'],
            $array['enabled'] ?? true,
            $array['logging'] ?? true,
            $array['timeout'] ?? 600,
            $array['installCommand'] ?? '',
            $array['buildCommand'] ?? '',
            $array['outputDirectory'] ?? '',
            $array['adapter'] ?? 'static',
            $array['fallbackFile'] ?? '',
            $array['runtimeSpecification'] ?? $array['specification'] ?? '',
            $array['activeDeployment'] ?? '',
            $array['buildSpecification'] ?? $array['specification'] ?? '',
            $array['startCommand'] ?? '',
            $array['deploymentRetention'] ?? 0,
            $array['scopes'] ?? []
        ))->setDescription(\is_string($array['description'] ?? null) ? $array['description'] : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'description' => $this->description,
            'id' => $this->id,
            'name' => $this->name,
            'framework' => $this->framework,
            'buildRuntime' => $this->buildRuntime,
            'enabled' => $this->enabled,
            'logging' => $this->logging,
            'timeout' => $this->timeout,
            'installCommand' => $this->installCommand,
            'buildCommand' => $this->buildCommand,
            'outputDirectory' => $this->outputDirectory,
            'adapter' => $this->adapter,
            'fallbackFile' => $this->fallbackFile,
            'specification' => $this->specification,
            'runtimeSpecification' => $this->specification,
            'activeDeployment' => $this->activeDeployment,
            'buildSpecification' => $this->buildSpecification,
            'startCommand' => $this->startCommand,
            'deploymentRetention' => $this->deploymentRetention,
            'scopes' => $this->scopes,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_SITE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_SITES;
    }

    public function getSiteName(): string
    {
        return $this->name;
    }

    public function getFramework(): string
    {
        return $this->framework;
    }

    public function getBuildRuntime(): string
    {
        return $this->buildRuntime;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getLogging(): bool
    {
        return $this->logging;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getInstallCommand(): string
    {
        return $this->installCommand;
    }

    public function getBuildCommand(): string
    {
        return $this->buildCommand;
    }

    public function getOutputDirectory(): string
    {
        return $this->outputDirectory;
    }

    public function getAdapter(): string
    {
        return $this->adapter;
    }

    public function getFallbackFile(): string
    {
        return $this->fallbackFile;
    }

    public function getSpecification(): string
    {
        return $this->specification;
    }

    public function getRuntimeSpecification(): string
    {
        return $this->specification;
    }

    public function getBuildSpecification(): string
    {
        return $this->buildSpecification ?: $this->specification;
    }

    public function getActiveDeployment(): string
    {
        return $this->activeDeployment;
    }

    public function getStartCommand(): string
    {
        return $this->startCommand;
    }

    public function getDeploymentRetention(): int
    {
        return $this->deploymentRetention;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
