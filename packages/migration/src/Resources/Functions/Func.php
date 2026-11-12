<?php

namespace Utopia\Migration\Resources\Functions;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Func extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param string $runtime
     * @param array<string> $execute
     * @param bool $enabled
     * @param array<string> $events
     * @param string $schedule
     * @param int $timeout
     * @param string $activeDeployment
     * @param string $entrypoint
     * @param string $commands
     * @param bool $logging
     * @param array<string> $scopes
     * @param string $specification
     * @param string $buildSpecification
     * @param int $interval
     * @param int $deploymentRetention
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly string $runtime,
        private readonly array $execute = [],
        private readonly bool $enabled = true,
        private readonly array $events = [],
        private readonly string $schedule = '',
        private readonly int $timeout = 0,
        private readonly string $activeDeployment = '',
        private readonly string $entrypoint = '',
        private readonly string $commands = '',
        private readonly bool $logging = true,
        private readonly array $scopes = [],
        private readonly string $specification = '',
        private readonly string $buildSpecification = '',
        private readonly int $interval = 0,
        private readonly int $deploymentRetention = 0
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
            $array['runtime'],
            $array['execute'] ?? [],
            $array['enabled'] ?? true,
            $array['events'] ?? [],
            $array['schedule'] ?? '',
            $array['timeout'] ?? 0,
            $array['activeDeployment'] ?? '',
            $array['entrypoint'] ?? '',
            $array['commands'] ?? '',
            $array['logging'] ?? true,
            $array['scopes'] ?? [],
            $array['runtimeSpecification'] ?? $array['specification'] ?? '',
            $array['buildSpecification'] ?? $array['specification'] ?? '',
            $array['interval'] ?? 0,
            $array['deploymentRetention'] ?? 0
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
            'execute' => $this->execute,
            'enabled' => $this->enabled,
            'runtime' => $this->runtime,
            'events' => $this->events,
            'schedule' => $this->schedule,
            'timeout' => $this->timeout,
            'activeDeployment' => $this->activeDeployment,
            'entrypoint' => $this->entrypoint,
            'commands' => $this->commands,
            'logging' => $this->logging,
            'scopes' => $this->scopes,
            'specification' => $this->specification,
            'runtimeSpecification' => $this->specification,
            'buildSpecification' => $this->buildSpecification,
            'interval' => $this->interval,
            'deploymentRetention' => $this->deploymentRetention,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_FUNCTION;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_FUNCTIONS;
    }

    public function getFunctionName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getExecute(): array
    {
        return $this->execute;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getRuntime(): string
    {
        return $this->runtime;
    }

    /**
     * @return array<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getSchedule(): string
    {
        return $this->schedule;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function getDeploymentRetention(): int
    {
        return $this->deploymentRetention;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getActiveDeployment(): string
    {
        return $this->activeDeployment;
    }

    public function getEntrypoint(): string
    {
        return $this->entrypoint;
    }

    public function getCommands(): string
    {
        return $this->commands;
    }

    public function getLogging(): bool
    {
        return $this->logging;
    }

    /**
     * @return array<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
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
}
