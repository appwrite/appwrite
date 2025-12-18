<?php

namespace Appwrite\Platform\Workers;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Realtime;
use Appwrite\Event\StatsUsage;
use Appwrite\Event\Webhook;
use Appwrite\Utopia\Response\Model\Execution;
use Exception;
use Executor\Executor;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
            ->inject('project')
            ->inject('message')
            ->inject('dbForProject')
            ->inject('queueForWebhooks')
            ->inject('queueForFunctions')
            ->inject('queueForRealtime')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('log')
            ->inject('executor')
            ->inject('isResourceBlocked')
            ->callback($this->action(...));
    }

    public function action(
        Document $project,
        Message $message,
        Database $dbForProject,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        Event $queueForEvents,
        StatsUsage $queueForStatsUsage,
        Log $log,
        Executor $executor,
        callable $isResourceBlocked
    ): void {
        $payload = $message->getPayload() ?? [];

        if (empty($payload)) {
            throw new Exception('Missing payload');
        }

        $type = $payload['type'] ?? '';

        // Short-term solution to offhand write operation from API container
        if ($type === Func::TYPE_ASYNC_WRITE) {
            $execution = new Document($payload['execution'] ?? []);
            $dbForProject->createDocument('executions', $execution);
            return;
        }

        $events = $payload['events'] ?? [];
        $data = $payload['body'] ?? '';
        $eventData = $payload['payload'] ?? '';
        $platform = $payload['platform'] ?? Config::getParam('platform', []);
        $function = new Document($payload['function'] ?? []);
        $functionId = $payload['functionId'] ?? '';
        $user = new Document($payload['user'] ?? []);
        $userId = $payload['userId'] ?? '';
        $method = $payload['method'] ?? 'POST';
        $headers = $payload['headers'] ?? [];
        $path = $payload['path'] ?? '/';
        $jwt = $payload['jwt'] ?? '';

        if ($user->isEmpty() && !empty($userId)) {
            $user = $dbForProject->getDocument('users', $userId);
        }

        if (empty($jwt) && !$user->isEmpty()) {
            $jwtExpiry = $function->getAttribute('timeout', 900) + 60; // 1min extra to account for possible cold-starts
            $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
            $jwt = $jwtObj->encode([
                'userId' => $user->getId(),
            ]);
        }

        if ($project->getId() === 'console') {
            return;
        }

        if ($function->isEmpty() && !empty($functionId)) {
            $function = $dbForProject->getDocument('functions', $functionId);
        }

        $log->addTag('functionId', $function->getId());
        $log->addTag('projectId', $project->getId());
        $log->addTag('type', $type);

        if (!empty($events)) {
            $limit = 30;
            $sum = 30;
            $offset = 0;
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

                    if ($isResourceBlocked($project, RESOURCE_TYPE_FUNCTIONS, $function->getId())) {
                        Console::log('Function ' . $function->getId() . ' is blocked, skipping execution.');
                        continue;
                    }

                    Console::success('Iterating function: ' . $function->getAttribute('name'));

                    $this->execute(
                        log: $log,
                        dbForProject: $dbForProject,
                        queueForWebhooks: $queueForWebhooks,
                        queueForFunctions: $queueForFunctions,
                        queueForRealtime: $queueForRealtime,
                        queueForStatsUsage: $queueForStatsUsage,
                        queueForEvents: $queueForEvents,
                        project: $project,
                        function: $function,
                        executor:  $executor,
                        trigger: 'event',
                        path: '/',
                        method: 'POST',
                        headers: [
                            'user-agent' => 'Appwrite/' . APP_VERSION_STABLE,
                            'content-type' => 'application/json'
                        ],
                        platform: $platform,
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

        if ($isResourceBlocked($project, RESOURCE_TYPE_FUNCTIONS, $function->getId())) {
            Console::log('Function ' . $function->getId() . ' is blocked, skipping execution.');
            return;
        }

        /**
         * Handle Schedule and HTTP execution.
         */
        switch ($type) {
            case 'http':
                $execution = new Document($payload['execution'] ?? []);
                $user = new Document($payload['user'] ?? []);
                $this->execute(
                    log: $log,
                    dbForProject: $dbForProject,
                    queueForWebhooks: $queueForWebhooks,
                    queueForFunctions: $queueForFunctions,
                    queueForRealtime: $queueForRealtime,
                    queueForStatsUsage: $queueForStatsUsage,
                    queueForEvents: $queueForEvents,
                    project: $project,
                    function: $function,
                    executor:  $executor,
                    trigger: 'http',
                    path: $path,
                    method: $method,
                    headers: $headers,
                    platform: $platform,
                    data: $data,
                    user: $user,
                    jwt: $jwt,
                    event: null,
                    eventData: null,
                    executionId: $execution->getId()
                );
                break;
            case 'schedule':
                $execution = new Document($payload['execution'] ?? []);
                $this->execute(
                    log: $log,
                    dbForProject: $dbForProject,
                    queueForWebhooks: $queueForWebhooks,
                    queueForFunctions: $queueForFunctions,
                    queueForRealtime: $queueForRealtime,
                    queueForStatsUsage: $queueForStatsUsage,
                    queueForEvents: $queueForEvents,
                    project: $project,
                    function: $function,
                    executor:  $executor,
                    trigger: 'schedule',
                    path: $path,
                    method: $method,
                    headers: $headers,
                    platform: $platform,
                    data: $data,
                    user: $user,
                    jwt: $jwt,
                    event: null,
                    eventData: null,
                    executionId: $execution->getId() ?? null
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
        $executionId = ID::unique();
        $headers['x-appwrite-execution-id'] = $executionId ?? '';
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

        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
            'resourceInternalId' => $function->getSequence(),
            'resourceId' => $function->getId(),
            'resourceType' => 'functions',
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
        ]);

        $execution = $dbForProject->createDocument('executions', $execution);

        if ($execution->isEmpty()) {
            throw new Exception('Failed to create execution');
        }
    }

    /**
     * @param Log $log
     * @param Database $dbForProject
     * @param Func $queueForFunctions
     * @param Realtime $queueForRealtime
     * @param StatsUsage $queueForStatsUsage
     * @param Event $queueForEvents
     * @param Document $project
     * @param Document $function
     * @param Executor $executor
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
     * @throws Structure
     * @throws \Utopia\Database\Exception
     * @throws Conflict
     */
    private function execute(
        Log $log,
        Database $dbForProject,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Realtime $queueForRealtime,
        StatsUsage $queueForStatsUsage,
        Event $queueForEvents,
        Document $project,
        Document $function,
        Executor $executor,
        string $trigger,
        string $path,
        string $method,
        array $headers,
        array $platform,
        string $data = null,
        ?Document $user = null,
        string $jwt = null,
        string $event = null,
        string $eventData = null,
        string $executionId = null,
    ): void {
        $user ??= new Document();
        $functionId = $function->getId();
        $deploymentId = $function->getAttribute('deploymentId', '');
        $spec = Config::getParam('specifications')[$function->getAttribute('specification', APP_COMPUTE_SPECIFICATION_DEFAULT)];

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

        if ($deployment->getAttribute('status') !== 'ready') {
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

        $jwtExpiry = $function->getAttribute('timeout', 900) + 60; // 1min extra to account for possible cold-starts
        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
        $apiKey = $jwtObj->encode([
            'projectId' => $project->getId(),
            'scopes' => $function->getAttribute('scopes', [])
        ]);

        $headers['x-appwrite-execution-id'] = $executionId ?? '';
        $headers['x-appwrite-key'] = API_KEY_DYNAMIC . '_' . $apiKey;
        $headers['x-appwrite-trigger'] = $trigger;
        $headers['x-appwrite-event'] = $event ?? '';
        $headers['x-appwrite-user-id'] = $user->getId() ?? '';
        $headers['x-appwrite-user-jwt'] = $jwt ?? '';
        $headers['x-appwrite-country-code'] = '';
        $headers['x-appwrite-continent-code'] = '';
        $headers['x-appwrite-continent-eu'] = 'false';

        /** Create execution or update execution status */
        $execution = $dbForProject->getDocument('executions', $executionId ?? '');
        if ($execution->isEmpty()) {
            $executionId = ID::unique();
            $headers['x-appwrite-execution-id'] = $executionId;
            $headersFiltered = [];
            foreach ($headers as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                    $headersFiltered[] = [ 'name' => $key, 'value' => $value ];
                }
            }

            $execution = new Document([
                '$id' => $executionId,
                '$permissions' => $user->isEmpty() ? [] : [Permission::read(Role::user($user->getId()))],
                'resourceInternalId' => $function->getSequence(),
                'resourceId' => $function->getId(),
                'resourceType' => 'functions',
                'deploymentInternalId' => $deployment->getSequence(),
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
            ]);

            $execution = $dbForProject->createDocument('executions', $execution);

            // TODO: @Meldiron Trigger executions.create event here

            if ($execution->isEmpty()) {
                throw new Exception('Failed to create or read execution');
            }
        }

        if ($execution->getAttribute('status') !== 'processing') {
            $execution->setAttribute('status', 'processing');

            $execution = $dbForProject->updateDocument('executions', $executionId, $execution);
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

        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
        $endpoint = "$protocol://{$platform['apiHostname']}/v1";

        // Appwrite vars
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_API_ENDPOINT' => $endpoint,
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deploymentId,
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_CPUS' => ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT),
            'APPWRITE_FUNCTION_MEMORY' => ($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT),
            'APPWRITE_VERSION' => APP_VERSION_STABLE,
            'APPWRITE_REGION' => $project->getAttribute('region'),
            'APPWRITE_DEPLOYMENT_TYPE' => $deployment->getAttribute('type', ''),
            'APPWRITE_VCS_REPOSITORY_ID' => $deployment->getAttribute('providerRepositoryId', ''),
            'APPWRITE_VCS_REPOSITORY_NAME' => $deployment->getAttribute('providerRepositoryName', ''),
            'APPWRITE_VCS_REPOSITORY_OWNER' => $deployment->getAttribute('providerRepositoryOwner', ''),
            'APPWRITE_VCS_REPOSITORY_URL' => $deployment->getAttribute('providerRepositoryUrl', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH' => $deployment->getAttribute('providerBranch', ''),
            'APPWRITE_VCS_REPOSITORY_BRANCH_URL' => $deployment->getAttribute('providerBranchUrl', ''),
            'APPWRITE_VCS_COMMIT_HASH' => $deployment->getAttribute('providerCommitHash', ''),
            'APPWRITE_VCS_COMMIT_MESSAGE' => $deployment->getAttribute('providerCommitMessage', ''),
            'APPWRITE_VCS_COMMIT_URL' => $deployment->getAttribute('providerCommitUrl', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_NAME' => $deployment->getAttribute('providerCommitAuthor', ''),
            'APPWRITE_VCS_COMMIT_AUTHOR_URL' => $deployment->getAttribute('providerCommitAuthorUrl', ''),
            'APPWRITE_VCS_ROOT_DIRECTORY' => $deployment->getAttribute('providerRootDirectory', ''),
        ]);

        /** Execute function */
        try {
            $version = $function->getAttribute('version', 'v2');
            $command = $runtime['startCommand'];
            $source = $deployment->getAttribute('buildPath', '');
            $extension = str_ends_with($source, '.tar') ? 'tar' : 'tar.gz';
            $command = $version === 'v2' ? '' : "cp /tmp/code.$extension /mnt/code/code.$extension && nohup helpers/start.sh \"$command\"";
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deploymentId,
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $source,
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $command,
                cpus: $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
                memory: $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
                logging: $function->getAttribute('logging', true),
            );

            $status = $executionResponse['statusCode'] >= 500 ? 'failed' : 'completed';

            $executionResponse['headers']['x-appwrite-execution-id'] = $execution->getId();

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = [ 'name' => $key, 'value' => $value ];
                }
            }

            $maxLogLength = APP_FUNCTION_LOG_LENGTH_LIMIT;
            $logs = $executionResponse['logs'] ?? '';

            if (\is_string($logs) && \strlen($logs) > $maxLogLength) {
                $warningMessage = "[WARNING] Logs truncated. The output exceeded {$maxLogLength} characters.\n";
                $warningLength = \strlen($warningMessage);
                $maxContentLength = $maxLogLength - $warningLength;
                $logs = $warningMessage . \substr($logs, -$maxContentLength);
            }

            // Truncate errors if they exceed the limit
            $maxErrorLength = APP_FUNCTION_ERROR_LENGTH_LIMIT;
            $errors = $executionResponse['errors'] ?? '';

            if (\is_string($errors) && \strlen($errors) > $maxErrorLength) {
                $warningMessage = "[WARNING] Errors truncated. The output exceeded {$maxErrorLength} characters.\n";
                $warningLength = \strlen($warningMessage);
                $maxContentLength = $maxErrorLength - $warningLength;
                $errors = $warningMessage . \substr($errors, -$maxContentLength);
            }

            /** Update execution status */
            $execution
                ->setAttribute('status', $status)
                ->setAttribute('responseStatusCode', $executionResponse['statusCode'])
                ->setAttribute('responseHeaders', $headersFiltered)
                ->setAttribute('logs', $logs)
                ->setAttribute('errors', $errors)
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
            $queueForStatsUsage
                ->setProject($project)
                ->addMetric(METRIC_EXECUTIONS, 1)
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS), 1)
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS), 1)
                ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000))// per project
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000))
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000))
                ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
                ->trigger()
            ;
        }

        $execution = $dbForProject->updateDocument('executions', $executionId, $execution);

        $executionModel = new Execution();
        $realtimeExecution = $executionModel->filter(new Document($execution->getArrayCopy()));
        $realtimeExecution = $realtimeExecution->getArrayCopy(\array_keys($executionModel->getRules()));

        $queueForEvents
            ->setProject($project)
            ->setUser($user)
            ->setEvent('functions.[functionId].executions.[executionId].update')
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($realtimeExecution);

        /** Trigger Webhook */
        $queueForWebhooks
            ->from($queueForEvents)
            ->trigger();

        /** Trigger Functions */
        $queueForFunctions
            ->from($queueForEvents)
            ->trigger();

        /** Trigger Realtime Events */
        $queueForRealtime
            ->setSubscribers(['console', $project->getId()])
            ->from($queueForEvents)
            ->trigger();

        if (!empty($error)) {
            throw new Exception($error, $errorCode);
        }
    }
}
