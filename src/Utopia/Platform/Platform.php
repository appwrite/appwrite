<?php

namespace Utopia\Platform;

/**
 * In-tree override of utopia-php/platform until workerNames / per-job
 * maxCoroutines land in utopia-php/monorepo `packages/platform`. Composer
 * PSR-4 maps `Utopia\Platform\` here so this class wins over vendor.
 *
 * init(TYPE_WORKER, ['workerName' => 'functions']) stays the single-queue path.
 * Combined workers pass workerNames: ['all'] (or a list) and workerJobs keyed
 * by action name with that queue's maxCoroutines (default 1).
 */

use Exception;
use Utopia\CLI\Adapters\Generic;
use Utopia\CLI\CLI;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Server;

abstract class Platform
{
    /**
     * Modules
     *
     * @var array<Module>
     */
    protected array $modules = [];

    protected CLI $cli;

    protected Server $worker;

    public function __construct(protected Module $core)
    {
        $this->modules[] = $this->core;
    }

    /**
     * Initialize Application
     */
    public function init(string $type, array $params = []): void
    {
        foreach ($this->modules as $module) {
            $services = $module->getServicesByType($type);
            switch ($type) {
                case Service::TYPE_HTTP:
                    $this->initHttp($services);
                    break;
                case Service::TYPE_TASK:
                    $adapter = $params['adapter'] ?? new Generic();
                    $this->cli ??= new CLI($adapter);
                    $this->initTasks($services);
                    break;
                case Service::TYPE_GRAPHQL:
                    $this->initGraphQL();
                    break;
                case Service::TYPE_WORKER:
                    $workerName = $params['workerName'] ?? null;

                    if (! isset($this->worker)) {
                        $consumer = $params['consumer'] ?? null;
                        $workersNum = $params['workersNum'] ?? 0;
                        $workerName = $params['workerName'] ?? null;
                        $queueName = $params['queueName'] ?? 'v1-' . $workerName;
                        $adapter = new Swoole($consumer, $workersNum, $queueName);
                        $this->worker ??= new Server($adapter);
                    }
                    $this->initWorker($services, $workerName, $params);
                    break;
                default:
                    throw new Exception('Please provide which type of initialization you want to carry out.');
            }
        }
    }

    /**
     * Init HTTP service
     */
    protected function initHttp(array $services): void
    {
        foreach ($services as $service) {
            foreach ($service->getActions() as $action) {
                /** @var Action $action */
                switch ($action->getType()) {
                    case Action::TYPE_INIT:
                        $hook = Http::init();
                        break;
                    case Action::TYPE_ERROR:
                        $hook = Http::error();
                        break;
                    case Action::TYPE_OPTIONS:
                        $hook = Http::options();
                        break;
                    case Action::TYPE_SHUTDOWN:
                        $hook = Http::shutdown();
                        break;
                    case Action::TYPE_DEFAULT:
                    default:
                        $hook = Http::routes($action->getHttpMethods(), $action->getHttpPath());
                        break;
                }

                $hook
                    ->groups($action->getGroups())
                    ->desc($action->getDesc() ?? '');

                if ($hook instanceof Route) {
                    foreach ($action->getHttpAliases() as $alias) {
                        $hook->alias($alias);
                    }
                }

                foreach ($action->getOptions() as $key => $option) {
                    switch ($option['type']) {
                        case 'param':
                            $key = substr((string) $key, stripos((string) $key, ':') + 1);
                            $hook->param($key, $option['default'], $option['validator'], $option['description'], $option['optional'], $option['injections'], $option['skipValidation'], $option['deprecated'], $option['example'], aliases: $option['aliases'] ?? [], enum: $option['enum'] ?? null);
                            break;
                        case 'injection':
                            $hook->inject($option['name']);
                            break;
                    }
                }

                foreach ($action->getLabels() as $key => $label) {
                    $hook->label($key, $label);
                }

                $hook->action($action->getCallback());
            }
        }
    }

    /**
     * Init CLI Services
     */
    protected function initTasks(array $services): void
    {
        $cli = $this->cli;
        foreach ($services as $service) {
            foreach ($service->getActions() as $key => $action) {
                switch ($action->getType()) {
                    case Action::TYPE_INIT:
                        $hook = $cli->init();
                        break;
                    case Action::TYPE_ERROR:
                        $hook = $cli->error();
                        break;
                    case Action::TYPE_SHUTDOWN:
                        $hook = $cli->shutdown();
                        break;
                    case Action::TYPE_DEFAULT:
                    default:
                        $hook = $cli->task($key);
                        break;
                }
                $hook
                    ->groups($action->getGroups())
                    ->desc($action->getDesc() ?? '');

                foreach ($action->getOptions() as $key => $option) {
                    switch ($option['type']) {
                        case 'param':
                            $key = substr((string) $key, stripos((string) $key, ':') + 1);
                            $hook->param($key, $option['default'], $option['validator'], $option['description'], $option['optional'], $option['injections'], $option['skipValidation'], $option['deprecated'], $option['example'], aliases: $option['aliases'] ?? [], enum: $option['enum'] ?? null);
                            break;
                        case 'injection':
                            $hook->inject($option['name']);
                            break;
                    }
                }

                foreach ($action->getLabels() as $key => $label) {
                    $hook->label($key, $label);
                }

                $hook->action($action->getCallback());
            }
        }
    }

    /**
     * Init worker Services
     *
     * @param array<int|string, Service> $services
     * @param array<string, mixed> $params
     */
    protected function initWorker(array $services, ?string $workerName, array $params = []): void
    {
        $worker = $this->worker;
        $names = $params['workerNames'] ?? [];
        if ($names === [] && $workerName !== null && $workerName !== '') {
            $names = [$workerName];
        }
        $names = array_map(static fn ($name): string => strtolower((string) $name), $names);
        $all = $names === [] || in_array('all', $names, true);
        /** @var array<string, array{queue?: ?string, maxCoroutines?: int}> $jobs */
        $jobs = $params['workerJobs'] ?? [];

        foreach ($services as $service) {
            foreach ($service->getActions() as $key => $action) {
                if ($action->getType() == Action::TYPE_DEFAULT) {
                    $name = strtolower((string) $key);
                    if (!$all && !in_array($name, $names, true)) {
                        continue;
                    }
                }
                switch ($action->getType()) {
                    case Action::TYPE_INIT:
                        $hook = $worker->init();
                        break;
                    case Action::TYPE_ERROR:
                        $hook = $worker->error();
                        break;
                    case Action::TYPE_SHUTDOWN:
                        $hook = $worker->shutdown();
                        break;
                    case Action::TYPE_WORKER_START:
                        $hook = $worker->workerStart();
                        break;
                    case Action::TYPE_WORKER_STOP:
                        $hook = $worker->workerStop();
                        break;
                    case Action::TYPE_DEFAULT:
                    default:
                        $name = strtolower((string) $key);
                        $config = $jobs[$name] ?? [];
                        $hook = $worker->job(
                            $config['queue'] ?? null,
                            max(1, (int) ($config['maxCoroutines'] ?? 1)),
                        );
                        break;
                }
                $hook
                    ->groups($action->getGroups())
                    ->desc($action->getDesc() ?? '');

                foreach ($action->getOptions() as $key => $option) {
                    switch ($option['type']) {
                        case 'param':
                            $key = substr((string) $key, stripos((string) $key, ':') + 1);
                            $hook->param($key, $option['default'], $option['validator'], $option['description'], $option['optional'], $option['injections'], $option['skipValidation'], $option['deprecated'], $option['example'], aliases: $option['aliases'] ?? [], enum: $option['enum'] ?? null);
                            break;
                        case 'injection':
                            $hook->inject($option['name']);
                            break;
                    }
                }

                foreach ($action->getLabels() as $key => $label) {
                    $hook->label($key, $label);
                }

                $hook->action($action->getCallback());
            }
        }
    }

    /**
     * Initialize GraphQL Services
     */
    protected function initGraphQL(): void {}

    /**
     * Add module
     */
    public function addModule(Module $module): self
    {
        $this->modules[] = $module;

        return $this;
    }

    /**
     * Add Service
     */
    public function addService(string $key, Service $service): self
    {
        $this->core->addService($key, $service);

        return $this;
    }

    /**
     * Remove Service
     */
    public function removeService(string $key): self
    {
        $this->core->removeService($key);

        return $this;
    }

    /**
     * Get Service
     */
    public function getService(string $key): ?Service
    {
        return $this->core->getService($key);
    }

    /**
     * Get Services
     */
    public function getServices(): array
    {
        return $this->core->getServices();
    }

    /**
     * Get the value of cli
     */
    public function getCli(): CLI
    {
        return $this->cli;
    }

    /**
     * Set the value of cli
     */
    public function setCli(CLI $cli): self
    {
        $this->cli = $cli;

        return $this;
    }

    /**
     * Get the value of worker
     */
    public function getWorker(): Server
    {
        return $this->worker;
    }

    /**
     * Set the value of worker
     */
    public function setWorker(Server $worker): self
    {
        $this->worker = $worker;

        return $this;
    }
}
