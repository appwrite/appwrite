<?php

namespace Appwrite\Platform\Workers;

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response\Model\Execution;
use Exception;
use Executor\Executor;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Logger\Log;
use Utopia\Platform\Action;
use Utopia\Queue\Message;
use Utopia\System\System;

class Functions extends Action
{
    public static function getName(): string
    {
        return 'functions';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this
            ->desc('Functions worker')
            ->groups(['functions'])
            ->inject('message')
            ->inject('dbForProject')
            ->inject('queueForFunctions')
            ->inject('queueForEvents')
            ->inject('queueForUsage')
            ->inject('log')
            ->callback(fn (Message $message, Database $dbForProject, Func $queueForFunctions, Event $queueForEvents, Usage $queueForUsage, Log $log) => $this->action($message, $dbForProject, $queueForFunctions, $queueForEvents, $queueForUsage, $log));
    }

    /**
     * @param Message $message
     * @param Database $dbForProject
     * @param Func $queueForFunctions
     * @param Event $queueForEvents
     * @param Usage $queueForUsage
     * @param Log $log
     * @return void
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     */
    public function action(Message $message, Database $dbForProject, Func $queueForFunctions, Event $queueForEvents, Usage $queueForUsage, Log $log): void
    {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

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
        $method = $payload['method'] ?? 'POST';
        $headers = $payload['headers'] ?? [];
        $path = $payload['path'] ?? '/';

        if ($project->getId() === 'console') {
            return;
        }

        $log->addTag('functionId', $function->getId());
        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
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

                    $this->execute(
                        log: $log,
                        dbForProject: $dbForProject,
                        queueForFunctions: $queueForFunctions,
                        queueForUsage: $queueForUsage,
                        queueForEvents: $queueForEvents,
                        project: $project,
                        function: $function,
                        trigger: 'event',
                        path: '/',
                        method: 'POST',
                        headers: [
                            'user-agent' => 'Appwrite/' . APP_VERSION_STABLE,
                            'content-type' => 'application/json'
                        ],
                        data: null,
                        user: $user,
                        jwt: null,
                        event: $events[0],
                        eventData: \is_string($eventData) ? $eventData : \json_encode($eventData),
                        executionId: null,
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
                $this->execute(
                    log: $log,
                    dbForProject: $dbForProject,
                    queueForFunctions: $queueForFunctions,
                    queueForUsage: $queueForUsage,
                    queueForEvents: $queueForEvents,
                    project: $project,
                    function: $function,
                    trigger: 'http',
                    path: $path,
                    method: $method,
                    headers: $headers,
                    data: $data,
                    user: $user,
                    jwt: $jwt,
                    event: null,
                    eventData: null,
                    executionId: $execution->getId()
                );
                break;
            case 'schedule':
                $this->execute(
                    log: $log,
                    dbForProject: $dbForProject,
                    queueForFunctions: $queueForFunctions,
                    queueForUsage: $queueForUsage,
                    queueForEvents: $queueForEvents,
                    project: $project,
                    function: $function,
                    trigger: 'schedule',
                    path: $path,
                    method: $method,
                    headers: $headers,
                    data: null,
                    user: null,
                    jwt: null,
                    event: null,
                    eventData: null,
                    executionId: null,
                );
                break;
        }
    }

    /**
     * @param string $message
     * @param Document $function
     * @param string $trigger
     * @param string $path
     * @param string $method
     * @param Document $user
     * @param string|null $jwt
     * @param string|null $event
     * @throws Exception
     */
    private function fail(
        string $message,
        Database $dbForProject,
        Document $function,
        string $trigger,
        string $path,
        string $method,
        Document $user,
        string $jwt = null,
        string $event = null,
    ): void {
        $headers['x-appwrite-trigger'] = $trigger;
        $headers['x-appwrite-event'] = $event ?? '';
        $headers['x-appwrite-user-id'] = $user->getId() ?? '';
        $headers['x-appwrite-user-jwt'] = $jwt ?? '';

        $headersFiltered = [];
        foreach ($headers as $key => $value) {
            if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                $headersFiltered[] = ['name' => $key, 'value' => $value];
            }
        }

        $executionId = ID::unique();
        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
            'functionInternalId' => $function->getInternalId(),
            'functionId' => $function->getId(),
            'deploymentInternalId' => '',
            'deploymentId' => '',
            'trigger' => $trigger,
            'status' => 'failed',
            'responseStatusCode' => 0,
            'responseHeaders' => [],
            'requestPath' => $path,
            'requestMethod' => $method,
            'requestHeaders' => $headersFiltered,
            'errors' => $message,
            'logs' => '',
            'duration' => 0.0,
            'search' => implode(' ', [$function->getId(), $executionId]),
        ]);

        if ($function->getAttribute('logging')) {
            $execution = $dbForProject->createDocument('executions', $execution);
        }

        if ($execution->isEmpty()) {
            throw new Exception('Failed to create execution');
        }
    }

    /**
     * @param Log $log
     * @param Database $dbForProject
     * @param Func $queueForFunctions
     * @param Usage $queueForUsage
     * @param Event $queueForEvents
     * @param Document $project
     * @param Document $function
     * @param string $trigger
     * @param string $path
     * @param string $method
     * @param array $headers
     * @param string|null $data
     * @param Document|null $user
     * @param string|null $jwt
     * @param string|null $event
     * @param string|null $eventData
     * @param string|null $executionId
     * @return void
     * @throws Authorization
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     */
    private function execute(
        Log $log,
        Database $dbForProject,
        Func $queueForFunctions,
        Usage $queueForUsage,
        Event $queueForEvents,
        Document $project,
        Document $function,
        string $trigger,
        string $path,
        string $method,
        array $headers,
        string $data = null,
        ?Document $user = null,
        string $jwt = null,
        string $event = null,
        string $eventData = null,
        string $executionId = null,
    ): void {
        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deployment', '');

        $log->addTag('deploymentId', $deploymentId);

        /** Check if deployment exists */
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $functionId) {
            $errorMessage = 'The execution could not be completed because a corresponding deployment was not found. A function deployment needs to be created before it can be executed. Please create a deployment for your function and try again.';
            $this->fail($errorMessage, $dbForProject, $function, $trigger, $path, $method, $user, $jwt, $event);
            return;
        }

        if ($deployment->isEmpty()) {
            $errorMessage = 'The execution could not be completed because a corresponding deployment was not found. A function deployment needs to be created before it can be executed. Please create a deployment for your function and try again.';
            $this->fail($errorMessage, $dbForProject, $function, $trigger, $path, $method, $user, $jwt, $event);
            return;
        }

        $buildId = $deployment->getAttribute('buildId', '');

        $log->addTag('buildId', $buildId);

        /** Check if build has exists */
        $build = $dbForProject->getDocument('builds', $buildId);
        if ($build->isEmpty()) {
            $errorMessage = 'The execution could not be completed because a corresponding deployment was not found. A function deployment needs to be created before it can be executed. Please create a deployment for your function and try again.';
            $this->fail($errorMessage, $dbForProject, $function, $trigger, $path, $method, $user, $jwt, $event);
            return;
        }

        if ($build->getAttribute('status') !== 'ready') {
            $errorMessage = 'The execution could not be completed because the build is not ready. Please wait for the build to complete and try again.';
            $this->fail($errorMessage, $dbForProject, $function, $trigger, $path, $method, $user, $jwt, $event);
            return;
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
            $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));
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
        } finally {
            /** Trigger usage queue */
            $queueForUsage
                ->setProject($project)
                ->addMetric(METRIC_EXECUTIONS, 1)
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS), 1)
                ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000))// per project
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000))
                ->trigger()
            ;
        }

        if ($function->getAttribute('logging')) {
            $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
        }
        /** Trigger Webhook */
        $executionModel = new Execution();
        $queueForEvents
            ->setQueue(Event::WEBHOOK_QUEUE_NAME)
            ->setClass(Event::WEBHOOK_CLASS_NAME)
            ->setProject($project)
            ->setUser($user)
            ->setEvent('functions.[functionId].executions.[executionId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($execution->getArrayCopy(array_keys($executionModel->getRules())))
            ->trigger();

        /** Trigger Functions */
        $queueForFunctions
            ->from($queueForEvents)
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

        if (!empty($error)) {
            throw new Exception($error, $errorCode);
        }
    }
}
