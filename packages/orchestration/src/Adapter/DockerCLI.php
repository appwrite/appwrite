<?php

namespace Utopia\Orchestration\Adapter;

use Utopia\Command;
use Utopia\Console;
use Utopia\Orchestration\Adapter;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Container\Stats;
use Utopia\Orchestration\Exception\Orchestration;
use Utopia\Orchestration\Exception\Timeout;
use Utopia\Orchestration\Network;

class DockerCLI extends Adapter
{
    /**
     * Constructor
     *
     * @return void
     */
    public function __construct(?string $username = null, ?string $password = null)
    {
        if ($username && $password) {
            $output = '';
            $stderr = '';

            $command = new Command('docker');
            $command
                ->argument('login')
                ->option('--username', $username)
                ->flag('--password-stdin');

            if (Console::execute($command, $password, $output, $stderr) !== 0) {
                $error = empty($stderr) ? $output : $stderr;
                throw new Orchestration("Docker Error: {$error}");
            }
        }
    }

    /**
     * Create Network
     */
    public function createNetwork(string $name, bool $internal = false): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('create');

        if ($internal) {
            $command->flag('--internal');
        }

        $command->argument($name);

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0;
    }

    /**
     * Remove Network
     */
    public function removeNetwork(string $name): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('rm')
            ->argument($name);

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0;
    }

    /**
     * Connect a container to a network
     */
    public function networkConnect(string $container, string $network): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('connect')
            ->argument($network)
            ->argument($container);

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0;
    }

    /**
     * Disconnect a container from a network
     */
    public function networkDisconnect(string $container, string $network, bool $force = false): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('disconnect');

        if ($force) {
            $command->flag('--force');
        }

        $command
            ->argument($network)
            ->argument($container);

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0;
    }

    /**
     * Check if a network exists
     */
    public function networkExists(string $name): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('inspect')
            ->argument($name)
            ->option('--format', '{{.Name}}');

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0 && trim($output) === $name;
    }

    /**
     * Get usage stats of containers
     *
     * @param  array<string, string>  $filters
     * @return array<Stats>
     */
    public function getStats(?string $container = null, array $filters = []): array
    {
        // List ahead of time, since docker stats does not allow filtering
        $containerIds = [];

        if ($container === null) {
            $containers = $this->list($filters);
            $containerIds = \array_map(fn ($c) => $c->getId(), $containers);
        } else {
            $containerIds[] = $container;
        }

        $output = '';
        $stderr = '';

        if (\count($containerIds) <= 0 && \count($filters) > 0) {
            return []; // No containers found
        }

        $stats = [];

        $command = new Command('docker');
        $command
            ->argument('stats')
            ->flag('--no-trunc')
            ->option('--format', 'id={{.ID}}&name={{.Name}}&cpu={{.CPUPerc}}&memory={{.MemPerc}}&diskIO={{.BlockIO}}&memoryIO={{.MemUsage}}&networkIO={{.NetIO}}')
            ->flag('--no-stream');

        foreach ($containerIds as $containerId) {
            $command->argument($containerId);
        }

        $result = Console::execute($command, '', $output, $stderr);

        if ($result !== 0) {
            return [];
        }

        $lines = \explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $stat = [];
            \parse_str($line, $stat);

            $stats[] = new Stats(
                containerId: $stat['id'],
                containerName: $stat['name'],
                cpuUsage: \floatval(\rtrim($stat['cpu'], '%')) / 100, // Remove percentage symbol, parse to number, convert to percentage
                memoryUsage: empty($stat['memory']) ? 0 : \floatval(\rtrim($stat['memory'], '%')), // Remove percentage symbol and parse to number. Value is empty on Windows
                diskIO: $this->parseIOStats($stat['diskIO']),
                memoryIO: $this->parseIOStats($stat['memoryIO']),
                networkIO: $this->parseIOStats($stat['networkIO']),
            );
        }

        return $stats;
    }

    /**
     * Use this method to parse string format into numeric in&out stats.
     * CLI IO stats in verbose format: "2.133MiB / 62.8GiB"
     * Output after parsing: [ "in" => 2133000, "out" => 62800000000 ]
     *
     * @return array<string,float>
     */
    private function parseIOStats(string $stats)
    {
        $stats = \strtolower($stats);
        $units = [
            'b' => 1,
            'kb' => 1000,
            'mb' => 1000000,
            'gb' => 1000000000,
            'tb' => 1000000000000,
            'kib' => 1024,
            'mib' => 1048576,
            'gib' => 1073741824,
            'tib' => 1099511627776,
        ];

        [$inStr, $outStr] = \explode(' / ', $stats);

        $inUnit = null;
        $outUnit = null;

        foreach ($units as $unit => $value) {
            if (\str_ends_with($inStr, $unit)) {
                $inUnit = $unit;
            }
            if (\str_ends_with($outStr, $unit)) {
                $outUnit = $unit;
            }
        }

        $inMultiply = $inUnit === null ? 1 : $units[$inUnit];
        $outMultiply = $outUnit === null ? 1 : $units[$outUnit];

        $inValue = \floatval(\rtrim($inStr, $inUnit));
        $outValue = \floatval(\rtrim($outStr, $outUnit));

        $response = [
            'in' => $inValue * $inMultiply,
            'out' => $outValue * $outMultiply,
        ];

        return $response;
    }

    /**
     * List Networks
     *
     * @return Network[]
     */
    public function listNetworks(): array
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('network')
            ->argument('ls')
            ->option('--format', 'id={{.ID}}&name={{.Name}}&driver={{.Driver}}&scope={{.Scope}}');

        $result = Console::execute($command, '', $output, $stderr);

        if ($result !== 0) {
            $error = empty($stderr) ? $output : $stderr;
            throw new Orchestration("Docker Error: {$error}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $output);

        foreach ($stdoutArray as $value) {
            $network = [];

            \parse_str($value, $network);

            if (isset($network['name'])) {
                $parsedNetwork = new Network($network['name'], $network['id'], $network['driver'], $network['scope']);

                array_push($list, $parsedNetwork);
            }
        }

        return $list;
    }

    /**
     * Pull Image
     */
    public function pull(string $image): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('pull')
            ->argument($image);

        $result = Console::execute($command, '', $output, $stderr);

        return $result === 0;
    }

    /**
     * List Containers
     *
     * @param  array<string, string>  $filters
     * @return Container[]
     */
    public function list(array $filters = []): array
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('ps')
            ->flag('--all')
            ->flag('--no-trunc')
            ->option('--format', 'id={{.ID}}&name={{.Names}}&status={{.Status}}&labels={{.Labels}}');

        foreach ($filters as $key => $value) {
            $command->option('--filter', $key.'='.$value);
        }

        $result = Console::execute($command, '', $output, $stderr);

        if ($result !== 0 && $result !== -1) {
            $error = empty($stderr) ? $output : $stderr;
            throw new Orchestration("Docker Error: {$error}");
        }

        $list = [];
        $stdoutArray = \explode("\n", $output);

        foreach ($stdoutArray as $value) {
            $container = [];

            \parse_str($value, $container);

            if (isset($container['name'])) {
                $labelsParsed = [];

                foreach (\explode(',', $container['labels']) as $value) {
                    $value = \explode('=', $value);

                    if (isset($value[0]) && isset($value[1])) {
                        $labelsParsed[$value[0]] = $value[1];
                    }
                }

                $parsedContainer = new Container($container['name'], $container['id'], $container['status'], $labelsParsed);

                array_push($list, $parsedContainer);
            }
        }

        return $list;
    }

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
        string $restart = self::RESTART_NO
    ): string {
        $output = '';
        $stderr = '';

        $time = time();

        $dockerCommand = new Command('docker');
        $dockerCommand
            ->argument('run')
            ->flag('-d');

        if ($remove) {
            $dockerCommand->flag('--rm');
        }

        if (! empty($network)) {
            $dockerCommand->option('--network', $network);
        }

        if (! empty($entrypoint)) {
            $dockerCommand->option('--entrypoint', $entrypoint);
        }

        if (! empty($this->cpus)) {
            $dockerCommand->option('--cpus', $this->cpus);
        }

        if (! empty($this->memory)) {
            $dockerCommand->option('--memory', $this->memory.'m');
        }

        if (! empty($this->swap)) {
            $dockerCommand->option('--memory-swap', $this->swap.'m');
        }

        $dockerCommand
            ->option('--restart', $restart)
            ->option('--name', $name)
            ->option('--label', "{$this->namespace}-type=runtime")
            ->option('--label', "{$this->namespace}-created={$time}");

        if (! empty($mountFolder)) {
            $dockerCommand->option('--volume', $mountFolder.':/tmp:rw');
        }

        foreach ($volumes as $volume) {
            $dockerCommand->option('--volume', $volume);
        }

        foreach ($labels as $labelKey => $label) {
            $dockerCommand->option('--label', $labelKey.'='.$label);
        }

        if (! empty($workdir)) {
            $dockerCommand->option('--workdir', $workdir);
        }

        if (! empty($hostname)) {
            $dockerCommand->option('--hostname', $hostname);
        }

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);
            $dockerCommand->option('--env', $key.'='.$value);
        }

        $dockerCommand->argument($image);

        foreach ($command as $value) {
            $dockerCommand->argument($value);
        }

        $result = Console::execute($dockerCommand, '', $output, $stderr, 30);

        if ($result !== 0) {
            $error = empty($stderr) ? $output : $stderr;
            throw new Orchestration("Docker Error: {$error}");
        }

        // Use first line only, CLI can add warnings or other messages
        $output = \explode("\n", $output)[0];

        return rtrim($output);
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
        string &$output = '',
        array $vars = [],
        int $timeout = -1
    ): bool {
        $stderr = '';

        $dockerCommand = new Command('docker');
        $dockerCommand
            ->argument('exec');

        foreach ($vars as $key => $value) {
            $key = $this->filterEnvKey($key);
            $dockerCommand->option('--env', $key.'='.$value);
        }

        $dockerCommand->argument($name);

        foreach ($command as $value) {
            $dockerCommand->argument($value);
        }

        $result = Console::execute($dockerCommand, '', $output, $stderr, $timeout);

        if ($result !== 0) {
            if ($result === 124) {
                throw new Timeout('Command timed out');
            } else {
                $error = empty($stderr) ? $output : $stderr;
                throw new Orchestration("Docker Error: {$error}");
            }
        }

        return true;
    }

    /**
     * Remove Container
     */
    public function remove(string $name, bool $force = false): bool
    {
        $output = '';
        $stderr = '';

        $command = new Command('docker');
        $command
            ->argument('rm');

        if ($force) {
            $command->flag('--force');
        }

        $command->argument($name);

        $result = Console::execute($command, '', $output, $stderr);

        $combinedOutput = $output.$stderr;

        if (! \str_starts_with($combinedOutput, $name) || \str_contains($combinedOutput, 'No such container')) {
            throw new Orchestration("Docker Error: {$combinedOutput}");
        }

        return ! $result;
    }
}
