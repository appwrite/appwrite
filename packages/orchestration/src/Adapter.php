<?php

namespace Utopia\Orchestration;

abstract class Adapter
{
    public const RESTART_NO = 'no'; // Never restart

    public const RESTART_ALWAYS = 'always'; // Restart after any exit code

    public const RESTART_ON_FAILURE = 'on-failure'; // Restart on after non-zero exit code

    public const RESTART_UNLESS_STOPPED = 'unless-stopped'; // Restart after any exit code, if not stopped manually

    /**
     * @var string
     */
    protected $namespace = 'utopia';

    /**
     * @var float
     */
    protected $cpus = 0;

    /**
     * @var int
     */
    protected $memory = 0;

    /**
     * @var int
     */
    protected $swap = 0;

    /**
     * Filter ENV vars
     */
    public function filterEnvKey(string $string): string
    {
        return preg_replace('/[^A-Za-z0-9\_\.\-]/', '', $string);
    }

    /**
     * Create Network
     */
    abstract public function createNetwork(string $name, bool $internal = false): bool;

    /**
     * Remove Network
     */
    abstract public function removeNetwork(string $name): bool;

    /**
     * Connect a container to a network
     */
    abstract public function networkConnect(string $container, string $network): bool;

    /**
     * Disconnect a container from a network
     */
    abstract public function networkDisconnect(string $container, string $network, bool $force = false): bool;

    /**
     * Check if a network exists
     */
    abstract public function networkExists(string $name): bool;

    /**
     * List Networks
     *
     * @return Network[]
     */
    abstract public function listNetworks(): array;

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<\Utopia\Orchestration\Container\Stats>
     */
    abstract public function getStats(?string $container = null, array $filters = []): array;

    /**
     * Pull Image
     */
    abstract public function pull(string $image): bool;

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    abstract public function list(array $filters = []): array;

    /**
     * Run Container
     *
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $vars
     * @param  array<string, string>  $labels
     */
    abstract public function run(
        string $image,
        string $name,
        array $command = [],
        string $entrypoint = '',
        string $workdir = '',
        array $volumes = [],
        array $vars = [],
        string $mountFolder = '',
        array $labels = [],
        string $hostname = '',
        bool $remove = false,
        string $network = '',
        string $restart = self::RESTART_NO
    ): string;

    /**
     * Execute Container
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    abstract public function execute(string $name, array $command, string &$output, array $vars = [], int $timeout = -1): bool;

    /**
     * Remove Container
     */
    abstract public function remove(string $name, bool $force): bool;

    /**
     * Set containers namespace
     *
     * @return $this
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * Set max allowed CPU Quota per container
     *
     * @return $this
     */
    public function setCpus(float $cores): self
    {
        $this->cpus = $cores;

        return $this;
    }

    /**
     * Set max allowed memory in mb per container
     *
     * @return $this
     */
    public function setMemory(int $mb): self
    {
        $this->memory = $mb;

        return $this;
    }

    /**
     * Set max allowed swap memory in mb per container
     *
     * @return $this
     */
    public function setSwap(int $mb): self
    {
        $this->swap = $mb;

        return $this;
    }
}
