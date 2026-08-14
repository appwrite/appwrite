<?php

namespace Appwrite\Platform;

use Appwrite\Platform\Modules\Account;
use Appwrite\Platform\Modules\Advisor;
use Appwrite\Platform\Modules\Avatars;
use Appwrite\Platform\Modules\Console;
use Appwrite\Platform\Modules\Core;
use Appwrite\Platform\Modules\Databases;
use Appwrite\Platform\Modules\Functions;
use Appwrite\Platform\Modules\Health;
use Appwrite\Platform\Modules\Migrations;
use Appwrite\Platform\Modules\Notifications;
use Appwrite\Platform\Modules\Organization;
use Appwrite\Platform\Modules\Presences;
use Appwrite\Platform\Modules\Project;
use Appwrite\Platform\Modules\Projects;
use Appwrite\Platform\Modules\Proxy;
use Appwrite\Platform\Modules\Sites;
use Appwrite\Platform\Modules\Storage;
use Appwrite\Platform\Modules\Teams;
use Appwrite\Platform\Modules\Tokens;
use Appwrite\Platform\Modules\Users;
use Appwrite\Platform\Modules\VCS;
use Appwrite\Platform\Modules\Webhooks;
use Appwrite\Worker\Config as WorkerConfig;
use Utopia\Platform\Action;
use Utopia\Platform\Platform;
use Utopia\Platform\Service;

class Appwrite extends Platform
{
    /**
     * @var list<string>
     */
    private array $workerNames = [];

    /**
     * @var array<string, array{queue: string, maxCoroutines: int}>
     */
    private array $workerJobs = [];

    public function __construct()
    {
        parent::__construct(new Core());
        $this->addModule(new Account\Module());
        $this->addModule(new Avatars\Module());
        $this->addModule(new Databases\Module());
        $this->addModule(new Projects\Module());
        $this->addModule(new Presences\Module());
        $this->addModule(new Functions\Module());
        $this->addModule(new Health\Module());
        $this->addModule(new Notifications\Module());
        $this->addModule(new Sites\Module());
        $this->addModule(new Console\Module());
        $this->addModule(new Proxy\Module());
        $this->addModule(new Teams\Module());
        $this->addModule(new Tokens\Module());
        $this->addModule(new Users\Module());
        $this->addModule(new Storage\Module());
        $this->addModule(new VCS\Module());
        $this->addModule(new Webhooks\Module());
        $this->addModule(new Migrations\Module());
        $this->addModule(new Organization\Module());
        $this->addModule(new Project\Module());
        $this->addModule(new Advisor\Module());
    }

    public function init(string $type, array $params = []): void
    {
        if ($type === Service::TYPE_WORKER) {
            $names = $params['workerNames'] ?? [];
            if ($names === [] && isset($params['workerName'])) {
                $names = [$params['workerName']];
            }
            $this->workerNames = array_map('strtolower', $names);
            $this->workerJobs = $params['workerJobs'] ?? [];
        }

        parent::init($type, $params);
    }

    /**
     * @param array<int|string, \Utopia\Platform\Service> $services
     */
    protected function initWorker(array $services, string $workerName): void
    {
        $worker = $this->worker;
        $names = $this->workerNames !== [] ? $this->workerNames : [strtolower($workerName)];
        $all = \in_array('all', $names, true);

        foreach ($services as $service) {
            foreach ($service->getActions() as $key => $action) {
                if ($action->getType() == Action::TYPE_DEFAULT) {
                    $name = strtolower((string) $key);
                    if (!$all && !\in_array($name, $names, true)) {
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
                        $config = $this->workerJobs[$name] ?? [
                            'queue' => WorkerConfig::queueName($name),
                            'maxCoroutines' => WorkerConfig::maxCoroutines($name),
                        ];
                        $hook = $worker->job($config['queue'], $config['maxCoroutines']);
                        break;
                }

                $hook
                    ->groups($action->getGroups())
                    ->desc($action->getDesc() ?? '');

                foreach ($action->getOptions() as $optionKey => $option) {
                    switch ($option['type']) {
                        case 'param':
                            $optionKey = substr((string) $optionKey, stripos((string) $optionKey, ':') + 1);
                            $hook->param($optionKey, $option['default'], $option['validator'], $option['description'], $option['optional'], $option['injections'], $option['skipValidation'], $option['deprecated'], $option['example'], aliases: $option['aliases'] ?? [], enum: $option['enum'] ?? null);
                            break;
                        case 'injection':
                            $hook->inject($option['name']);
                            break;
                    }
                }

                foreach ($action->getLabels() as $labelKey => $label) {
                    $hook->label($labelKey, $label);
                }

                $hook->action($action->getCallback());
            }
        }
    }
}
