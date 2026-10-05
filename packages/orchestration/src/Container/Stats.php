<?php

namespace Utopia\Orchestration\Container;

class Stats
{
    protected string $containerId;

    protected string $containerName;

    protected float $cpuUsage;

    protected float $memoryUsage;

    /**
     * @var array<string,float>
     */
    protected array $diskIO;

    /**
     * @var array<string,float>
     */
    protected array $memoryIO;

    /**
     * @var array<string,float>
     */
    protected array $networkIO;

    /**
     * @param  array<string,float>  $diskIO
     * @param  array<string,float>  $memoryIO
     * @param  array<string,float>  $networkIO
     */
    public function __construct(string $containerId, string $containerName, float $cpuUsage, float $memoryUsage, array $diskIO, array $memoryIO, array $networkIO)
    {
        $this->containerId = $containerId;
        $this->containerName = $containerName;
        $this->cpuUsage = $cpuUsage;
        $this->memoryUsage = $memoryUsage;
        $this->diskIO = $diskIO;
        $this->memoryIO = $memoryIO;
        $this->networkIO = $networkIO;
    }

    public function getContainerId(): string
    {
        return $this->containerId;
    }

    public function getContainerName(): string
    {
        return $this->containerName;
    }

    public function getCpuUsage(): float
    {
        return $this->cpuUsage;
    }

    public function getMemoryUsage(): float
    {
        return $this->memoryUsage;
    }

    /**
     * @return array<string,float>
     */
    public function getMemoryIO(): array
    {
        return $this->memoryIO;
    }

    /**
     * @return array<string,float>
     */
    public function getDiskIO(): array
    {
        return $this->diskIO;
    }

    /**
     * @return array<string,float>
     */
    public function getNetworkIO(): array
    {
        return $this->networkIO;
    }
}
