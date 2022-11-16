<?php

require_once __DIR__ . '/../worker.php';

use Utopia\Queue\Message;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Domnikl\Statsd\Client;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Queue\Adapter\Swoole;
use Utopia\Queue\Server;

Authorization::disable();
Authorization::setDefaultStatus(false);

global $connection;
global $workerNumber;
$adapter  = new Swoole($connection, $workerNumber, Event::FUNCTIONS_QUEUE_NAME);
$server   = new Server($adapter);

Server::setResource('execute', function () {
    return function (
        Document $project,
        Document $function,
        Database $dbForProject,
        Func $functions,
        string $trigger,
        string $executionId = null,
        string $event = null,
        string $eventData = null,
        string $data = null,
        ?Document $user = null,
        string $jwt = null,
        Client $statsd
    ) {

        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');
        var_dump("Deployment ID : ", $deploymentId);
    
        /** Check if deployment exists */
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
    
        if ($deployment->getAttribute('resourceId') !== $functionId) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function');
        }
    
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found. Create deployment before trying to execute a function');
        }
    
        /** Check if build has exists */
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
        if ($build->isEmpty()) {
            throw new Exception('Build not found');
        }
    
        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready');
        }
    
        /** Check if  runtime is supported */
        $runtimes = Config::getParam('runtimes', []);
    
        if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }
    
        $runtime = $runtimes[$function->getAttribute('runtime')];
    
        /** Create execution or update execution status */
        $execution = $dbForProject->getDocument('executions', $executionId ?? '');
        if ($execution->isEmpty()) {
            $executionId = ID::unique();
            $execution = $dbForProject->createDocument('executions', new Document([
                '$id' => $executionId,
                '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
                'functionId' => $functionId,
                'deploymentId' => $deploymentId,
                'trigger' => $trigger,
                'status' => 'waiting',
                'statusCode' => 0,
                'response' => '',
                'stderr' => '',
                'duration' => 0.0,
                'search' => implode(' ', [$functionId, $executionId]),
            ]));
    
            if ($execution->isEmpty()) {
                throw new Exception('Failed to create or read execution');
            }
        }
        $execution->setAttribute('status', 'processing');
        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
    
        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Build not ready');
        }
    
        /** Check if  runtime is supported */
        $runtimes = Config::getParam('runtimes', []);
    
        if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }
    
        $runtime = $runtimes[$function->getAttribute('runtime')];
    
        /** Create execution or update execution status */
        $execution = $dbForProject->getDocument('executions', $executionId ?? '');
        if ($execution->isEmpty()) {
            $executionId = ID::unique();
            $execution = $dbForProject->createDocument('executions', new Document([
                '$id' => $executionId,
                '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
                'functionId' => $functionId,
                'deploymentId' => $deploymentId,
                'trigger' => $trigger,
                'status' => 'waiting',
                'statusCode' => 0,
                'response' => '',
                'stderr' => '',
                'duration' => 0.0,
                'search' => implode(' ', [$functionId, $executionId]),
            ]));
    
            if ($execution->isEmpty()) {
                throw new Exception('Failed to create or read execution');
            }
        }
        $execution->setAttribute('status', 'processing');
        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
    
        $vars = array_reduce($function['vars'] ?? [], function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value');
            return $carry;
        }, []);
    
        /** Collect environment variables */
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_EVENT' => $event ?? '',
            'APPWRITE_FUNCTION_EVENT_DATA' => $eventData ?? '',
            'APPWRITE_FUNCTION_DATA' => $data ?? '',
            'APPWRITE_FUNCTION_USER_ID' => $user->getId() ?? '',
            'APPWRITE_FUNCTION_JWT' => $jwt ?? '',
        ]);
    
        /** Execute function */
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
        try {
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                payload: $vars['APPWRITE_FUNCTION_DATA'] ?? '',
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('outputPath', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
            );
    
            /** Update execution status */
            $execution
                ->setAttribute('status', $executionResponse['status'])
                ->setAttribute('statusCode', $executionResponse['statusCode'])
                ->setAttribute('response', $executionResponse['response'])
                ->setAttribute('stdout', $executionResponse['stdout'])
                ->setAttribute('stderr', $executionResponse['stderr'])
                ->setAttribute('duration', $executionResponse['duration']);
        } catch (\Throwable $th) {
            $interval = (new \DateTime())->diff(new \DateTime($execution->getCreatedAt()));
            $execution
                ->setAttribute('duration', (float)$interval->format('%s.%f'))
                ->setAttribute('status', 'failed')
                ->setAttribute('statusCode', $th->getCode())
                ->setAttribute('stderr', $th->getMessage());
            Console::error($th->getMessage());
        }
    
        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
    
        /** Trigger Webhook */
        $executionModel = new Execution();
        $executionUpdate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
        $executionUpdate
            ->setProject($project)
            ->setUser($user)
            ->setEvent('functions.[functionId].executions.[executionId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($execution->getArrayCopy(array_keys($executionModel->getRules())))
            ->trigger();
    
        /** Trigger Functions */
        $functions
            ->setData($data ?? '')
            ->setProject($project)
            ->setUser($user)
            ->setEvent('functions.[functionId].executions.[executionId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->trigger();
    
        /** Trigger realtime event */
        $allEvents = Event::generateEvents('functions.[functionId].executions.[executionId].update', [
            'functionId' => $function->getId(),
            'executionId' => $execution->getId()
        ]);
        $target = Realtime::fromPayload(
            // Pass first, most verbose event pattern
            event: $allEvents[0],
            payload: $execution
        );
        Realtime::send(
            projectId: 'console',
            payload: $execution->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles']
        );
        Realtime::send(
            projectId: $project->getId(),
            payload: $execution->getArrayCopy(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles']
        );
    
        /** Update usage stats */
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
            $usage = new Stats($statsd);
            $usage
                ->setParam('projectId', $project->getId())
                ->setParam('projectInternalId', $project->getInternalId())
                ->setParam('functionId', $function->getId())
                ->setParam('executions.{scope}.compute', 1)
                ->setParam('executionStatus', $execution->getAttribute('status', ''))
                ->setParam('executionTime', $execution->getAttribute('duration'))
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0)
                ->submit();
        }
    };
});

$server->job()
    ->inject('message')
    ->inject('dbForProject')
    ->inject('functions')
    ->inject('statsd')
    ->inject('execute')
    ->action(function (Message $message, Database $dbForProject, Func $functions, Client $statsd, callable $execute) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        var_dump(json_encode($payload));
        $type = $payload['type'] ?? '';
        $events = $payload['events'] ?? [];
        $data = $payload['data'] ?? '';
        $project = new Document($payload['project'] ?? []);
        $function = new Document($payload['function'] ?? []);
        $user = new Document($payload['user'] ?? []);
        var_dump("Function : ", $function);

        if ($project->getId() === 'console') {
            return;
        }

        /**
         * Handle Event execution.
         */
        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
            $functions = [];
            /** @var Document[] $functions */
            while ($sum >= $limit) {
                $functions = $dbForProject->find('functions', [
                    Query::limit($limit),
                    Query::offset($offset),
                    Query::orderAsc('name'),
                ]);

                $sum = \count($functions);
                $offset = $offset + $limit;

                Console::log('Fetched ' . $sum . ' functions...');

                foreach ($functions as $function) {
                    if (!array_intersect($events, $function->getAttribute('events', []))) {
                        continue;
                    }
                    Console::success('Iterating function: ' . $function->getAttribute('name'));
                    $execute(
                        statsd: $statsd,
                        dbForProject: $dbForProject,
                        project: $project, 
                        function: $function,
                        trigger: 'event', 
                        event: $events[0], 
                        eventData: $payload, 
                        user: $user,
                        data: null, 
                        executionId: null, 
                        jwt: null
                    );
                    Console::success('Triggered function: ' . $events[0]);
                }
            }

            return;
        }

        /**
         * Handle Schedule and HTTP execution.
         */
        switch ($type) {
            case 'http':
                $jwt = $payload['jwt'] ?? '';
                $execution = new Document($payload['execution'] ?? []);
                $user = new Document($payload['user'] ?? []);
                $execute(
                    project: $project, 
                    function: $function,
                    dbForProject: $dbForProject,
                    functions: $functions,
                    trigger: 'http',
                    executionId: $execution->getId(), 
                    event: null, 
                    eventData: null,
                    data: $data, 
                    user: $user,
                    jwt: $jwt,
                    statsd: $statsd,
                );
                break;
            case 'schedule':
                $execute(
                    project: $project, 
                    function: $function,
                    dbForProject: $dbForProject,
                    functions: $functions,
                    trigger: 'http',
                    executionId: null, 
                    event: null, 
                    eventData: null,
                    data: null, 
                    user: null,
                    jwt: null,
                    statsd: $statsd,
                );
                break;
        }
    });

$server
    ->error()
    ->inject('error')
    ->inject('logger')
    ->inject('register')
    ->action(function ($error, $logger, $register) {

        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        if ($error instanceof PDOException) {
            throw $error;
        }

        if ($error->getCode() >= 500 || $error->getCode() === 0) {
            $log = new Log();

            $log->setNamespace("appwrite-worker");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());
            $log->setAction('appwrite-worker-functions');
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', \Utopia\Database\Validator\Authorization::$roles);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $logger->addLog($log);
        }

        Console::error('[Error] Type: ' . get_class($error));
        Console::error('[Error] Message: ' . $error->getMessage());
        Console::error('[Error] File: ' . $error->getFile());
        Console::error('[Error] Line: ' . $error->getLine());

        $register->get('pools')->reclaim();
    });

$server->workerStart();
$server->start();
