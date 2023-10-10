<?php

require_once __DIR__ . '/../worker.php';

use Domnikl\Statsd\Client;
use Utopia\Queue\Message;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Usage\Stats;
use Appwrite\Utopia\Response\Model\Execution;
use Executor\Executor;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Queue\Server;
use Utopia\Database\Helpers\Role;

Authorization::disable();
Authorization::setDefaultStatus(false);

Server::setResource('execute', function () {
    return function (
        Log $log,
        Func $queueForFunctions,
        Database $dbForProject,
        Client $statsd,
        Document $project,
        Document $function,
        string $trigger,
        string $data = null,
        string $path,
        string $method,
        array $headers,
        ?Document $user = null,
        string $jwt = null,
        string $event = null,
        string $eventData = null,
        string $executionId = null,
    ) {
        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');

        $log->addTag('functionId', $functionId);
        $log->addTag('projectId', $project->getId());

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
        $version = $function->getAttribute('version', 'v2');
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);

        if (!\array_key_exists($function->getAttribute('runtime'), $runtimes)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $runtime = $runtimes[$function->getAttribute('runtime')];

        $headers['x-appwrite-trigger'] = $trigger;
        $headers['x-appwrite-event'] = $event ?? '';
        $headers['x-appwrite-user-id'] = $user->getId() ?? '';
        $headers['x-appwrite-user-jwt'] = $jwt ?? '';

        /** Create execution or update execution status */
        $execution = $dbForProject->getDocument('executions', $executionId ?? '');
        if ($execution->isEmpty()) {
            $headersFiltered = [];
            foreach ($headers as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                    $headersFiltered[] = [ 'name' => $key, 'value' => $value ];
                }
            }

            $executionId = ID::unique();
            $execution = new Document([
                '$id' => $executionId,
                '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
                'functionInternalId' => $function->getInternalId(),
                'functionId' => $function->getId(),
                'deploymentInternalId' => $deployment->getInternalId(),
                'deploymentId' => $deployment->getId(),
                'trigger' => $trigger,
                'status' => 'processing',
                'responseStatusCode' => 0,
                'responseHeaders' => [],
                'requestPath' => $path,
                'requestMethod' => $method,
                'requestHeaders' => $headersFiltered,
                'errors' => '',
                'logs' => '',
                'duration' => 0.0,
                'search' => implode(' ', [$functionId, $executionId]),
            ]);

            if ($function->getAttribute('logging')) {
                $execution = $dbForProject->createDocument('executions', $execution);
            }

            // TODO: @Meldiron Trigger executions.create event here

            if ($execution->isEmpty()) {
                throw new Exception('Failed to create or read execution');
            }
        }

        if ($execution->getAttribute('status') !== 'processing') {
            $execution->setAttribute('status', 'processing');

            if ($function->getAttribute('logging')) {
                $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
            }
        }

        $durationStart = \microtime(true);

        $body = $eventData ?? '';
        if (empty($body)) {
            $body = $data ?? '';
        }

        $vars = [];

        // V2 vars
        if ($version === 'v2') {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_TRIGGER' => $headers['x-appwrite-trigger'] ?? '',
                'APPWRITE_FUNCTION_DATA' => $body ?? '',
                'APPWRITE_FUNCTION_EVENT_DATA' => $body ?? '',
                'APPWRITE_FUNCTION_EVENT' => $headers['x-appwrite-event'] ?? '',
                'APPWRITE_FUNCTION_USER_ID' => $headers['x-appwrite-user-id'] ?? '',
                'APPWRITE_FUNCTION_JWT' => $headers['x-appwrite-user-jwt'] ?? ''
            ]);
        }

        // Shared vars
        foreach ($function->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        // Function vars
        foreach ($function->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        // Appwrite vars
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
        ]);

        /** Execute function */
        try {
            $version = $function->getAttribute('version', 'v2');
            $command = $runtime['startCommand'];
            $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
            $command = $version === 'v2' ? '' : 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"';
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deploymentId,
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('path', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $command
            );

            $status = $executionResponse['statusCode'] >= 400 ? 'failed' : 'completed';

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = [ 'name' => $key, 'value' => $value ];
                }
            }

            /** Update execution status */
            $execution
                ->setAttribute('status', $status)
                ->setAttribute('responseStatusCode', $executionResponse['statusCode'])
                ->setAttribute('responseHeaders', $headersFiltered)
                ->setAttribute('logs', $executionResponse['logs'])
                ->setAttribute('errors', $executionResponse['errors'])
                ->setAttribute('duration', $executionResponse['duration']);
        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('status', 'failed')
                ->setAttribute('responseStatusCode', 500)
                ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());

            $error = $th->getMessage();
            $errorCode = $th->getCode();
        }

        if ($function->getAttribute('logging')) {
            $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
        }

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
        $queueForFunctions
            ->from($executionUpdate)
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
                ->setParam('functionId', $function->getId()) // TODO: We should use functionInternalId in usage stats
                ->setParam('executions.{scope}.compute', 1)
                ->setParam('executionStatus', $execution->getAttribute('status', ''))
                ->setParam('executionTime', $execution->getAttribute('duration'))
                ->setParam('networkRequestSize', 0)
                ->setParam('networkResponseSize', 0)
                ->submit();
        }

        if (!empty($error)) {
            throw new Exception($error, $errorCode);
        }
    };
});

$server->job()
    ->inject('message')
    ->inject('dbForProject')
    ->inject('queueForFunctions')
    ->inject('statsd')
    ->inject('execute')
    ->inject('log')
    ->action(function (Message $message, Database $dbForProject, Func $queueForFunctions, Client $statsd, callable $execute, Log $log) {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';
        $events = $payload['events'] ?? [];
        $data = $payload['body'] ?? '';
        $eventData = $payload['payload'] ?? '';
        $project = new Document($payload['project'] ?? []);
        $function = new Document($payload['function'] ?? []);
        $user = new Document($payload['user'] ?? []);

        if ($project->getId() === 'console') {
            return;
        }

        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
            $functions = [];
            /** @var Document[] $functions */
            while ($sum >= $limit) {
                $functions = $dbForProject->find('functions', [
                    Query::limit($limit),
                    Query::offset($offset)
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
                        log: $log,
                        statsd: $statsd,
                        dbForProject: $dbForProject,
                        project: $project,
                        function: $function,
                        queueForFunctions: $queueForFunctions,
                        trigger: 'event',
                        event: $events[0],
                        eventData: \is_string($eventData) ? $eventData : \json_encode($eventData),
                        user: $user,
                        data: null,
                        executionId: null,
                        jwt: null,
                        path: '/',
                        method: 'POST',
                        headers: [
                            'user-agent' => 'Appwrite/' . APP_VERSION_STABLE,
                            'content-type' => 'application/json'
                        ],
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
                    log: $log,
                    project: $project,
                    function: $function,
                    dbForProject: $dbForProject,
                    queueForFunctions: $queueForFunctions,
                    trigger: 'http',
                    executionId: $execution->getId(),
                    event: null,
                    eventData: null,
                    data: $data,
                    user: $user,
                    jwt: $jwt,
                    path: $payload['path'] ?? '',
                    method: $payload['method'] ?? 'POST',
                    headers: $payload['headers'] ?? [],
                    statsd: $statsd,
                );
                break;
            case 'schedule':
                $execute(
                    log: $log,
                    project: $project,
                    function: $function,
                    dbForProject: $dbForProject,
                    queueForFunctions: $queueForFunctions,
                    trigger: 'schedule',
                    executionId: null,
                    event: null,
                    eventData: null,
                    data: null,
                    user: null,
                    jwt: null,
                    path: $payload['path'] ?? '/',
                    method: $payload['method'] ?? 'POST',
                    headers: $payload['headers'] ?? [],
                    statsd: $statsd,
                );
                break;
        }
    });

$server->workerStart();
$server->start();
