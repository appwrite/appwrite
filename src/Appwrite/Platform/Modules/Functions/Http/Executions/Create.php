<?php

namespace Appwrite\Platform\Modules\Functions\Http\Executions;

use Ahc\Jwt\JWT;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Functions\Validator\Headers;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Executor\Executor;
use MaxMind\Db\Reader;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createExecution';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions/:functionId/executions')
            ->desc('Create execution')
            ->groups(['api', 'functions'])
            ->label('scope', 'execution.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].executions.[executionId].create')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'executions',
                name: 'createExecution',
                description: <<<EOT
                Trigger a function execution. The returned object will return you the current execution status. You can ping the `Get Execution` endpoint to get updates on the current execution status. Once this endpoint is called, your function execution process will start asynchronously.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_EXECUTION,
                    )
                ],
                contentType: ContentType::MULTIPART,
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('body', '', new Text(10485760, 0), 'HTTP body of execution. Default value is empty string.', true)
            ->param('async', false, new Boolean(true), 'Execute code in the background. Default value is false.', true)
            ->param('path', '/', new Text(2048), 'HTTP path of execution. Path can include query params. Default value is /', true)
            ->param('method', 'POST', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true), 'HTTP method of execution. Default value is POST.', true)
            ->param('headers', [], new AnyOf([new Assoc(), new Text(65535)], AnyOf::TYPE_MIXED), 'HTTP headers of execution. Defaults to empty.', true)
            ->param('scheduledAt', null, new Nullable(new Text(100)), 'Scheduled execution time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future with precision in minutes.', true)
            ->inject('response')
            ->inject('request')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('queueForFunctions')
            ->inject('geodb')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('executor')
            ->inject('platform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $body,
        mixed $async,
        string $path,
        string $method,
        mixed $headers,
        ?string $scheduledAt,
        Response $response,
        Request $request,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $user,
        Event $queueForEvents,
        StatsUsage $queueForStatsUsage,
        Func $queueForFunctions,
        Reader $geodb,
        Store $store,
        Token $proofForToken,
        Executor $executor,
        array $platform,
        Authorization $authorization,
    ) {
        $async = \strval($async) === 'true' || \strval($async) === '1';

        if (!$async && !is_null($scheduledAt)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Scheduled executions must run asynchronously. Set scheduledAt to a future date, or set async to true.');
        }

        if (!is_null($scheduledAt)) {
            $validator = new DatetimeValidator(requireDateInFuture: true, precision: DateTimeValidator::PRECISION_MINUTES, offset: 60);
            if (!$validator->isValid($scheduledAt)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Execution schedule must be a valid date, and at least 1 minute from now');
            }
        }

        /**
         * @var array<string, mixed> $headers
         */
        $assocParams = ['headers'];
        foreach ($assocParams as $assocParam) {
            if (!empty('headers') && !is_array($$assocParam)) {
                $$assocParam = \json_decode($$assocParam, true);
            }
        }

        $booleanParams = ['async'];
        foreach ($booleanParams as $booleamParam) {
            if (!empty($$booleamParam) && !is_bool($$booleamParam)) {
                $$booleamParam = $$booleamParam === "true" ? true : false;
            }
        }

        // 'headers' validator
        $validator = new Headers();
        if (!$validator->isValid($headers)) {
            throw new Exception($validator->getDescription(), 400);
        }

        $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $version = $function->getAttribute('version', 'v2');
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);
        $spec = Config::getParam('specifications')[$function->getAttribute('specification', APP_COMPUTE_SPECIFICATION_DEFAULT)];

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $deployment = $authorization->skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deploymentId', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        if ($deployment->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        if (!$authorization->isValid(new Input('execute', $function->getAttribute('execute')))) { // Check if user has write access to execute function
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $jwt = ''; // initialize
        if (!$user->isEmpty()) { // If userId exists, generate a JWT for function
            $sessions = $user->getAttribute('sessions', []);
            $current = new Document();

            foreach ($sessions as $session) {
                /** @var Utopia\Database\Document $session */
                if ($proofForToken->verify($store->getProperty('secret', ''), $session->getAttribute('secret'))) { // Find most recent active session for user ID and JWT headers
                    $current = $session;
                }
            }

            if (!$current->isEmpty()) {
                $jwtExpiry = $function->getAttribute('timeout', 900) + 60; // 1min extra to account for possible cold-starts
                $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
                $jwt = $jwtObj->encode([
                    'userId' => $user->getId(),
                    'sessionId' => $current->getId(),
                ]);
            }
        }

        $jwtExpiry = $function->getAttribute('timeout', 900) + 60; // 1min extra to account for possible cold-starts
        $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $jwtExpiry, 0);
        $apiKey = $jwtObj->encode([
            'projectId' => $project->getId(),
            'scopes' => $function->getAttribute('scopes', [])
        ]);

        $executionId = ID::unique();
        $headers['x-appwrite-execution-id'] = $executionId ?? '';
        $headers['x-appwrite-key'] = API_KEY_DYNAMIC . '_' . $apiKey;
        $headers['x-appwrite-trigger'] = 'http';
        $headers['x-appwrite-user-id'] = $user->getId() ?? '';
        $headers['x-appwrite-user-jwt'] = $jwt ?? '';
        $headers['x-appwrite-country-code'] = '';
        $headers['x-appwrite-continent-code'] = '';
        $headers['x-appwrite-continent-eu'] = 'false';
        $ip = $request->getIP();
        $headers['x-appwrite-client-ip'] = $ip;

        if (!empty($ip)) {
            $record = $geodb->get($ip);

            if ($record) {
                $eu = Config::getParam('locale-eu');

                $headers['x-appwrite-country-code'] = $record['country']['iso_code'] ?? '';
                $headers['x-appwrite-continent-code'] = $record['continent']['code'] ?? '';
                $headers['x-appwrite-continent-eu'] = (\in_array($record['country']['iso_code'], $eu)) ? 'true' : 'false';
            }
        }

        $headersFiltered = [];
        foreach ($headers as $key => $value) {
            if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                $headersFiltered[] = ['name' => $key, 'value' => $value];
            }
        }



        $status = $async ? 'waiting' : 'processing';

        if (!is_null($scheduledAt)) {
            $status = 'scheduled';
        }

        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => !$user->isEmpty() ? [Permission::read(Role::user($user->getId()))] : [],
            'resourceInternalId' => $function->getSequence(),
            'resourceId' => $function->getId(),
            'resourceType' => 'functions',
            'deploymentInternalId' => $deployment->getSequence(),
            'deploymentId' => $deployment->getId(),
            'trigger' => (!is_null($scheduledAt)) ? 'schedule' : 'http',
            'status' => $status, // waiting / processing / completed / failed / scheduled
            'responseStatusCode' => 0,
            'responseHeaders' => [],
            'requestPath' => $path,
            'requestMethod' => $method,
            'requestHeaders' => $headersFiltered,
            'errors' => '',
            'logs' => '',
            'duration' => 0.0,
        ]);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setContext('function', $function);

        if ($async) {
            if (is_null($scheduledAt)) {
                $execution = $authorization->skip(fn () => $dbForProject->createDocument('executions', $execution));
                $queueForFunctions
                    ->setType('http')
                    ->setExecution($execution)
                    ->setFunction($function)
                    ->setBody($body)
                    ->setHeaders($headers)
                    ->setPath($path)
                    ->setMethod($method)
                    ->setJWT($jwt)
                    ->setProject($project)
                    ->setUser($user)
                    ->setParam('functionId', $function->getId())
                    ->setParam('executionId', $execution->getId())
                    ->trigger();
            } else {
                $data = [
                    'headers' => $headers,
                    'path' => $path,
                    'method' => $method,
                    'body' => $body,
                    'userId' => $user->getId()
                ];

                $schedule = $dbForPlatform->createDocument('schedules', new Document([
                    'region' => $project->getAttribute('region'),
                    'resourceType' => SCHEDULE_RESOURCE_TYPE_EXECUTION,
                    'resourceId' => $execution->getId(),
                    'resourceInternalId' => $execution->getSequence(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule' => $scheduledAt,
                    'data' => $data,
                    'active' => true,
                ]));

                $execution = $execution
                    ->setAttribute('scheduleId', $schedule->getId())
                    ->setAttribute('scheduleInternalId', $schedule->getSequence())
                    ->setAttribute('scheduledAt', $scheduledAt);

                $execution = $authorization->skip(fn () => $dbForProject->createDocument('executions', $execution));
            }

            return $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($execution, Response::MODEL_EXECUTION);
        }

        $durationStart = \microtime(true);

        $vars = [];

        // V2 vars
        if ($version === 'v2') {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_TRIGGER' => $headers['x-appwrite-trigger'] ?? '',
                'APPWRITE_FUNCTION_DATA' => $body ?? '',
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
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
            'APPWRITE_FUNCTION_CPUS' => $spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT,
            'APPWRITE_FUNCTION_MEMORY' => $spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT,
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
                deploymentId: $deployment->getId(),
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
                requestTimeout: 30
            );

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = ['name' => $key, 'value' => $value];
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
            $status = $executionResponse['statusCode'] >= 500 ? 'failed' : 'completed';
            $execution->setAttribute('status', $status);
            $execution->setAttribute('responseStatusCode', $executionResponse['statusCode']);
            $execution->setAttribute('responseHeaders', $headersFiltered);
            $execution->setAttribute('logs', $logs);
            $execution->setAttribute('errors', $errors);
            $execution->setAttribute('duration', $executionResponse['duration']);
        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('status', 'failed')
                ->setAttribute('responseStatusCode', 500)
                ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
            Console::error($th->getMessage());

            if ($th instanceof AppwriteException) {
                throw $th;
            }
        } finally {
            $queueForStatsUsage
                ->addMetric(METRIC_EXECUTIONS, 1)
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS), 1)
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS), 1)
                ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000)) // per project
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000)) // per function
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000)) // per function
                ->addMetric(METRIC_EXECUTIONS_MB_SECONDS, (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
                ->addMetric(str_replace(['{resourceType}'], [RESOURCE_TYPE_FUNCTIONS], METRIC_RESOURCE_TYPE_EXECUTIONS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
                ->addMetric(str_replace(['{resourceType}', '{resourceInternalId}'], [RESOURCE_TYPE_FUNCTIONS, $function->getSequence()], METRIC_RESOURCE_TYPE_ID_EXECUTIONS_MB_SECONDS), (int)(($spec['memory'] ?? APP_COMPUTE_MEMORY_DEFAULT) * $execution->getAttribute('duration', 0) * ($spec['cpus'] ?? APP_COMPUTE_CPUS_DEFAULT)))
            ;

            $execution = $authorization->skip(fn () => $dbForProject->createDocument('executions', $execution));
        }

        $executionResponse['headers']['x-appwrite-execution-id'] = $execution->getId();

        $headers = [];
        foreach (($executionResponse['headers'] ?? []) as $key => $value) {
            $headers[] = ['name' => $key, 'value' => $value];
        }

        $execution->setAttribute('responseBody', $executionResponse['body'] ?? '');
        $execution->setAttribute('responseHeaders', $headers);

        $acceptTypes = \explode(', ', $request->getHeader('accept'));
        foreach ($acceptTypes as $acceptType) {
            if (\str_starts_with($acceptType, 'application/json') || \str_starts_with($acceptType, 'application/*')) {
                $response->setContentType(Response::CONTENT_TYPE_JSON);
                break;
            } elseif (\str_starts_with($acceptType, 'multipart/form-data') || \str_starts_with($acceptType, 'multipart/*')) {
                $response->setContentType(Response::CONTENT_TYPE_MULTIPART);
                break;
            }
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($execution, Response::MODEL_EXECUTION);
    }
}
