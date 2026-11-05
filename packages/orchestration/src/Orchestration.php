<?php

namespace Utopia\Orchestration;

use Exception;

class Orchestration
{
    /**
     * @var Adapter
     */
    protected $adapter;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Command Line String into Array
     *
     * This function will convert a string containing a command into an array of arguments.
     * It will go through the string and find all instances of spaces, and will split the string
     * however if it detects a apostrophe comes after the space it will find the next apostrophe and split the entire thing
     * and add it to the array. This is so arguments with spaces in them can be passed such as scripts for sh or bash.
     *
     * If there are no spaces detected in the first place it will just return the string as an array.
     *
     * @return (false|string)[]
     */
    public function parseCommandString(string $command): array
    {
        $currentPos = 0;
        $commandProcessed = [];

        if (strpos($command, ' ', $currentPos) === false) {
            return [$command];
        }

        while (true) {
            if (strpos($command, ' ', $currentPos) !== false) {
                $place = (int) strpos($command, ' ', $currentPos);

                if ($command[$place + 1] !== "'") {
                    array_push($commandProcessed, substr($command, $currentPos, $place - $currentPos));
                    $place = $place + 1;
                } else {
                    array_push($commandProcessed, substr($command, $currentPos, $place - $currentPos));

                    $closingString = strpos($command, "'", $place + 2);

                    if ($closingString == false) {
                        throw new Exception("Invalid Command given, are you missing an `'` at the end?");
                    }

                    array_push($commandProcessed, substr($command, $place + 1, $closingString));
                    $place = $closingString + 1;
                }

                if (strpos($command, ' ', $place) === false) {
                    if (! empty(substr($command, $place, strlen($command) - $currentPos))) {
                        array_push($commandProcessed, substr($command, $place, strlen($command) - $currentPos));
                    }
                }

                $currentPos = $place;
            } else {
                break;
            }
        }

        return $commandProcessed;
    }

    /**
     * Create Network
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        return $this->adapter->createNetwork($name, $internal);
    }

    /**
     * Remove Network
     */
    public function removeNetwork(string $name): bool
    {
        return $this->adapter->removeNetwork($name);
    }

    /**
     * List Networks
     *
     * @return Network[]
     */
    public function listNetworks(): array
    {
        return $this->adapter->listNetworks();
    }

    /**
     * Connect a container to a network
     */
    public function networkConnect(string $container, string $network): bool
    {
        return $this->adapter->networkConnect($container, $network);
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<\Utopia\Orchestration\Container\Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        return $this->adapter->getStats($container, $filters);
    }

    /**
     * Disconnect a container from a network
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        return $this->adapter->networkDisconnect($container, $network, $force);
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        return $this->adapter->networkExists($name);
    }

    /**
     * Pull Image
     */
    public function pull(string $image): bool
    {
        return $this->adapter->pull($image);
    }

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        return $this->adapter->list($filters);
    }

    /**
     * Run Container
     *
     * Creates and runs a new container, On success it will return a string containing the container ID.
     * On fail it will throw an exception.
     *
     * @param  string[]  $command
     * @param  string[]  $volumes
     * @param  array<string, string>  $labels
     * @param  array<string, string>  $vars
     */
    public function run(
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
        string $restart = Adapter::RESTART_NO
    ): string {
        return $this->adapter->run($image, $name, $command, $entrypoint, $workdir, $volumes, $vars, $mountFolder, $labels, $hostname, $remove, $network, $restart);
    }

    /**
     * Execute Container
     *
     * @param  string[]  $command
     * @param  array<string, string>  $vars
     */
    public function execute(
        string $name,
        array $command,
        string &$output,
        array $vars = [],
        int $timeout = -1
    ): bool {
        return $this->adapter->execute($name, $command, $output, $vars, $timeout);
    }

    /**
     * Remove Container
     */
    public function remove(string $name, bool $force = false): bool
    {
        return $this->adapter->remove($name, $force);
    }

    /**
     * Set containers namespace
     *
     * @return $this
     */
    public function setNamespace(string $namespace): self
    {
        $this->adapter->setNamespace($namespace);

        return $this;
    }

    /**
     * Set max allowed CPU Quota per container
     *
     * @return $this
     */
    public function setCpus(float $cores): self
    {
        $this->adapter->setCpus($cores);

        return $this;
    }

    /**
     * Set max allowed memory in mb per container
     *
     * @return $this
     */
    public function setMemory(int $mb): self
    {
        $this->adapter->setMemory($mb);

        return $this;
    }

    /**
     * Set max allowed swap memory in mb per container
     *
     * @return $this
     */
    public function setSwap(int $mb): self
    {
        $this->adapter->setSwap($mb);

        return $this;
    }
}
