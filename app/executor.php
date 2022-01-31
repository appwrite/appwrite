<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Execution;
use Cron\CronExpression;
use Swoole\ConnectionPool;
use Swoole\Coroutine as Co;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

require_once __DIR__ . '/init.php';

Authorization::disable();
Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

function logError(Throwable $error, string $action, Utopia\Route $route = null)
{
    global $register;

    $logger = $register->get('logger');

    if ($logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("executor");
        $log->setServer(\gethostname());
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url',  $route->getPath());
        }

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());

        $log->setAction($action);

        $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
};

$orchestrationPool = new ConnectionPool(function () {
    $dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
    $dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
    $orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));

    return $orchestration;
}, 6);

try {
    $runtimes = Config::getParam('runtimes');

    // Warmup: make sure images are ready to run fast 🚀
    Co\run(function () use ($runtimes, $orchestrationPool) {
        foreach ($runtimes as $runtime) {
            go(function () use ($runtime, $orchestrationPool) {
                try {
                    $orchestration = $orchestrationPool->get();

                    Console::info('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');

                    $response = $orchestration->pull($runtime['image']);

                    if ($response) {
                        Console::success("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                    } else {
                        Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                    }
                } catch (\Throwable $th) {
                } finally {
                    $orchestrationPool->put($orchestration);
                }
            });
        }
    });

    $activeFunctions = new Swoole\Table(1024);
    $activeFunctions->column('id', Swoole\Table::TYPE_STRING, 512);
    $activeFunctions->column('name', Swoole\Table::TYPE_STRING, 512);
    $activeFunctions->column('status', Swoole\Table::TYPE_STRING, 512);
    $activeFunctions->column('key', Swoole\Table::TYPE_STRING, 4096);
    $activeFunctions->create();

    Co\run(function () use ($orchestrationPool, $activeFunctions) {
        try {
            $orchestration = $orchestrationPool->get();
            $executionStart = \microtime(true);
            $residueList = $orchestration->list(['label' => 'appwrite-type=function']);
        } catch (\Throwable $th) {
        } finally {
            $orchestrationPool->put($orchestration);
        }


        foreach ($residueList as $value) {
            go(fn () => $activeFunctions->set($value->getName(), [
                'id' => $value->getId(),
                'name' => $value->getName(),
                'status' => $value->getStatus(),
                'private-key' => ''
            ]));
        }

        $executionEnd = \microtime(true);
        Console::info(count($activeFunctions) . ' functions listed in ' . ($executionEnd - $executionStart) . ' seconds');
    });
} catch (\Throwable $error) {
    call_user_func($logError, $error, "startupError");
}

function createRuntimeServer(string $functionId, string $projectId, string $deploymentId, Database $database): void
{
    global $orchestrationPool;
    global $runtimes;
    global $activeFunctions;

    try {
        $orchestration = $orchestrationPool->get();
        $function = $database->getDocument('functions', $functionId);
        $deployment = $database->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('buildId') === null) {
            throw new Exception('Deployment has no buildId');
        }

        // Grab Build Document
        $build = $database->getDocument('builds', $deployment->getAttribute('buildId'));

        // Check if function isn't already created
        $functions = $orchestration->list(['label' => 'appwrite-type=function', 'name' => 'appwrite-function-' . $deployment->getId()]);

        if (\count($functions) > 0) {
            return;
        }

        // Generate random secret key
        $secret = \bin2hex(\random_bytes(16));

        // Check if runtime is active
        $runtime = $runtimes[$function->getAttribute('runtime', '')] ?? null;

        if ($deployment->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('deployment not found', 404);
        }

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        // Process environment variables
        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
            'INTERNAL_RUNTIME_KEY' => $secret
        ]);

        $vars = \array_merge($vars, $build->getAttribute('vars', [])); // for gettng endpoint.

        $container = 'appwrite-function-' . $deployment->getId();

        if ($activeFunctions->exists($container) && !(\substr($activeFunctions->get($container)['status'], 0, 2) === 'Up')) { // Remove container if not online
            // If container is online then stop and remove it
            try {
                $orchestration->remove($container, true);
            } catch (Exception $e) {
                try {
                    throw new Exception('Failed to remove container: ' . $e->getMessage());
                } catch (Throwable $error) {
                    logError($error, "createRuntimeServer");
                }
            }

            $activeFunctions->del($container);
        }

        // Check if deployment hasn't failed
        if ($build->getAttribute('status') === 'failed') {
            throw new Exception('Deployment build failed, please check your logs.', 500);
        }

        // Check if deployment is built yet.
        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception('Deployment is not built yet', 500);
        }

        // Grab Deployment Files
        $deploymentPath = $build->getAttribute('outputPath', '');

        $deploymentPathTarget = '/tmp/project-' . $projectId . '/' . $build->getId() . '/builtCode/code.tar.gz';
        $deploymentPathTargetDir = \pathinfo($deploymentPathTarget, PATHINFO_DIRNAME);
        $container = 'appwrite-function-' . $deployment->getId();

        $device = Storage::getDevice('builds');

        if (!\file_exists($deploymentPathTargetDir)) {
            if (@\mkdir($deploymentPathTargetDir, 0777, true)) {
                \chmod($deploymentPathTargetDir, 0777);
            } else {
                throw new Exception('Can\'t create directory ' . $deploymentPathTargetDir);
            }
        }

        if (!\file_exists($deploymentPathTarget)) {
            if (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) === Storage::DEVICE_LOCAL) {
                if (!\copy($deploymentPath, $deploymentPathTarget)) {
                    throw new Exception('Can\'t create temporary code file ' . $deploymentPathTarget);
                }
            } else {
                $buffer = $device->read($deploymentPath);
                \file_put_contents($deploymentPathTarget, $buffer);
            }
        };

        /**
         * Limit CPU Usage - DONE
         * Limit Memory Usage - DONE
         * Limit Network Usage
         * Limit Storage Usage (//--storage-opt size=120m \)
         * Make sure no access to redis, mariadb, influxdb or other system services
         * Make sure no access to NFS server / storage volumes
         * Access Appwrite REST from internal network for improved performance
         */
        if (!$activeFunctions->exists($container)) { // Create contianer if not ready
            $executionStart = \microtime(true);
            $executionTime = \time();

            $orchestration
                ->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', '1'))
                ->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', '256'))
                ->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '256'));

            $vars = array_map(fn ($v) => strval($v), $vars);

            // Launch runtime server
            $id = $orchestration->run(
                image: $runtime['image'],
                name: $container,
                vars: $vars,
                labels: [
                    'appwrite-type' => 'function',
                    'appwrite-created' => strval($executionTime),
                    'appwrite-runtime' => $function->getAttribute('runtime', ''),
                    'appwrite-project' => $projectId,
                    'appwrite-deployment' => $deployment->getId(),
                ],
                hostname: $container,
                mountFolder: $deploymentPathTargetDir,
            );

            if (empty($id)) {
                throw new Exception('Failed to create container');
            }

            // Add to network
            $orchestration->networkConnect($container, App::getEnv('_APP_EXECUTOR_RUNTIME_NETWORK', 'appwrite_runtimes'));

            $executionEnd = \microtime(true);

            $activeFunctions->set($container, [
                'id' => $id,
                'name' => $container,
                'status' => 'Up ' . \round($executionEnd - $executionStart, 2) . 's',
                'key' => $secret,
            ]);

            Console::success('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
        } else {
            Console::success('Runtime server is ready to run');
        }
    } catch (\Throwable $th) {
        Console::error($th->getMessage());
        $orchestrationPool->put($orchestration ?? null);
        throw $th;
    }
    $orchestrationPool->put($orchestration);
};

function execute(string $trigger, string $projectId, string $executionId, string $functionId, Database $database, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): array
{
    Console::info('Executing function: ' . $functionId);

    global $activeFunctions;
    global $runtimes;
    global $register;

    $function = $database->getDocument('functions', $functionId);
    $deployment = $database->getDocument('deployments', $function->getAttribute('deployment', ''));
    $build = $database->getDocument('builds', $deployment->getAttribute('buildId', ''));

    if ($deployment->getAttribute('functionId') !== $function->getId()) {
        throw new Exception('Deployment not found', 404);
    }

    // Grab execution document if exists
    // It it doesn't exist, create a new one.
    $execution = !empty($executionId)
        ? $database->getDocument('executions', $executionId)
        : $database->createDocument('executions', new Document([
            '$id' => $executionId,
            '$read' => ($userId !== '') ? ['user:' . $userId] : [],
            '$write' => ['role:all'],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'deploymentId' => $deployment->getId(),
            'trigger' => $trigger, // http / schedule / event
            'status' => 'processing', // waiting / processing / completed / failed
            'statusCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ]));

    if (false === $execution || ($execution instanceof Document && $execution->isEmpty())) {
        throw new Exception('Failed to create or read execution');
    }


    if ($build->getAttribute('status') === 'building') {

        $execution
            ->setAttribute('status', 'failed')
            ->setAttribute('statusCode', 500)
            ->setAttribute('stderr', 'Deployment is still being built.')
            ->setAttribute('time', 0);

        $database->updateDocument('executions', $execution->getId(), $execution);

        throw new Exception('Execution Failed. Reason: Deployment is still being built.');
    }

    // Check if runtime is active
    $runtime = $runtimes[$function->getAttribute('runtime', '')] ?? null;

    if (\is_null($runtime)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
        'APPWRITE_FUNCTION_TRIGGER' => $trigger,
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_EVENT' => $event,
        'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
        'APPWRITE_FUNCTION_DATA' => $data,
        'APPWRITE_FUNCTION_USER_ID' => $userId,
        'APPWRITE_FUNCTION_JWT' => $jwt,
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
    ]);

    $vars = \array_merge($vars, $build->getAttribute('vars', []));

    $container = 'appwrite-function-' . $deployment->getId();

    try {
        if ($build->getAttribute('status') !== 'ready') {
            // Create a new build entry
            $buildId = $database->getId();
            $database->createDocument('builds', new Document([
                '$id' => $buildId,
                '$read' => ($userId !== '') ? ['user:' . $userId] : [],
                '$write' => [],
                'dateCreated' => time(),
                'status' => 'processing',
                'outputPath' => '',
                'runtime' => $function->getAttribute('runtime', ''),
                'source' => $deployment->getAttribute('path'),
                'sourceType' => Storage::DEVICE_LOCAL,
                'stdout' => '',
                'stderr' => '',
                'time' => 0,
                'vars' => [
                    'ENTRYPOINT_NAME' => $deployment->getAttribute('entrypoint'),
                    'APPWRITE_FUNCTION_ID' => $function->getId(),
                    'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
                    'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
                    'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
                    'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
                ]
            ]));

            $deployment->setAttribute('buildId', $buildId);

            $database->updateDocument('deployments', $deployment->getId(), $deployment);

            runBuildStage($buildId, $deployment->getId(), $projectId);
        }
    } catch (Exception $e) {
        $execution
            ->setAttribute('status', 'failed')
            ->setAttribute('statusCode', 500)
            ->setAttribute('stderr', \utf8_encode(\mb_substr($e->getMessage(), -4000))) // log last 4000 chars output
            ->setAttribute('time', 0);

        $database->updateDocument('executions', $execution->getId(), $execution);

        throw new Error('Something went wrong building the code. ' . $e->getMessage());
    }

    try {
        if (!$activeFunctions->exists($container)) { // Create container if not ready
            createRuntimeServer($functionId, $projectId, $deployment->getId(), $database);
        } else if ($activeFunctions->get($container)['status'] === 'Down') {
            sleep(1);
        } else {
            Console::info('Container is ready to run');
        }
    } catch (Exception $e) {
        $execution->setAttribute('status', 'failed')
            ->setAttribute('statusCode', 500)
            ->setAttribute('stderr', \utf8_encode(\mb_substr($e->getMessage(), -4000))) // log last 4000 chars output
            ->setAttribute('time', 0);

        $execution = $database->updateDocument('executions', $execution->getId(), $execution);

        try {
            throw new Exception('Something went wrong building the runtime server. ' . $e->getMessage());
        } catch (\Exception $error) {
            logError($error, 'execution');
        }

        return [
            'status' => 'failed',
            'response' => \utf8_encode(\mb_substr($e->getMessage(), -4000)), // log last 4000 chars output
            'time' => 0
        ];
    }

    $key = $activeFunctions->get('appwrite-function-' . $deployment->getId(), 'key');

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
        'APPWRITE_FUNCTION_TRIGGER' => $trigger,
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_EVENT' => $event,
        'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
        'APPWRITE_FUNCTION_DATA' => $data,
        'APPWRITE_FUNCTION_USER_ID' => $userId,
        'APPWRITE_FUNCTION_JWT' => $jwt,
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId
    ]);

    $vars = \array_merge($vars, $build->getAttribute('vars', []));

    $stdout = '';
    $stderr = '';

    $executionStart = \microtime(true);

    $statusCode = 0;

    $errNo = -1;
    $attempts = 0;
    $max = 5;

    $executorResponse = '';

    // cURL request to runtime
    do {
        $attempts++;
        $ch = \curl_init();

        $body = \json_encode([
            'path' => '/usr/code',
            'file' => $build->getAttribute('vars', [])['ENTRYPOINT_NAME'],
            'env' => $vars,
            'payload' => $data,
            'timeout' => $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900))
        ]);

        \curl_setopt($ch, CURLOPT_URL, "http://" . $container . ":3000/");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . \strlen($body),
            'x-internal-challenge: ' . $key,
            'host: null'
        ]);

        $executorResponse = \curl_exec($ch);

        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $error = \curl_error($ch);

        $errNo = \curl_errno($ch);

        \curl_close($ch);
        if ($errNo != CURLE_COULDNT_CONNECT && $errNo != 111) {
            break;
        }

        sleep(1);
    } while ($attempts < $max);

    if ($attempts >= 5) {
        $stderr = 'Failed to connect to executor runtime after 5 attempts.';
        $statusCode = 124;
    }

    // If timeout error
    if (in_array($errNo, [CURLE_OPERATION_TIMEDOUT, 110])) {
        $statusCode = 124;
    }

    // 110 is the Swoole error code for timeout, see: https://www.swoole.co.uk/docs/swoole-error-code
    if ($errNo !== 0 && $errNo !== CURLE_COULDNT_CONNECT && $errNo !== CURLE_OPERATION_TIMEDOUT && $errNo !== 110) {
        throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 500);
    }

    $executionData = [];

    if (!empty($executorResponse)) {
        $executionData = json_decode($executorResponse, true);
    }

    if (isset($executionData['code'])) {
        $statusCode = $executionData['code'];
    }

    if ($statusCode === 500) {
        if (isset($executionData['message'])) {
            $stderr = $executionData['message'];
        } else {
            $stderr = 'Internal Runtime error';
        }
    } else if ($statusCode === 124) {
        $stderr = 'Execution timed out.';
    } else if ($statusCode === 0) {
        $stderr = 'Execution failed.';
    } else if ($statusCode >= 200 && $statusCode < 300) {
        $stdout = $executorResponse;
    } else {
        $stderr = 'Execution failed.';
    }

    $executionEnd = \microtime(true);
    $executionTime = ($executionEnd - $executionStart);
    $functionStatus = ($statusCode >= 200 && $statusCode < 300) ? 'completed' : 'failed';

    Console::success('Function executed in ' . ($executionEnd - $executionStart) . ' seconds, status: ' . $functionStatus);

    $execution->setAttribute('deploymentId', $deployment->getId())
        ->setAttribute('status', $functionStatus)
        ->setAttribute('statusCode', $statusCode)
        ->setAttribute('stdout', \utf8_encode(\mb_substr($stdout, -8000)))
        ->setAttribute('stderr', \utf8_encode(\mb_substr($stderr, -8000)))
        ->setAttribute('time', $executionTime);

    $execution = $database->updateDocument('executions', $execution->getId(), $execution);

    $executionModel = new Execution();
    $executionUpdate = new Event('v1-webhooks', 'WebhooksV1');

    $executionUpdate
        ->setParam('projectId', $projectId)
        ->setParam('userId', $userId)
        ->setParam('webhooks', $webhooks)
        ->setParam('event', 'functions.executions.update')
        ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));

    $executionUpdate->trigger();

    $target = Realtime::fromPayload('functions.executions.update', $execution);

    Realtime::send(
        projectId: $projectId,
        payload: $execution->getArrayCopy(),
        event: 'functions.executions.update',
        channels: $target['channels'],
        roles: $target['roles']
    );

    if (App::getEnv('_APP_USAGE_STATS', 'enabled') === 'enabled') {
        $statsd = $register->get('statsd');

        $usage = new Stats($statsd);

        $usage
            ->setParam('projectId', $projectId)
            ->setParam('functionId', $function->getId())
            ->setParam('functionExecution', 1)
            ->setParam('functionStatus', $functionStatus)
            ->setParam('functionExecutionTime', $executionTime * 1000) // ms
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
            ->submit();

        $usage->submit();
    }

    return [
        'status' => $functionStatus,
        'response' => ($functionStatus !=== 'completed') ? $stderr : $stdout,
        'time' => $executionTime
    ];
};

function runBuildStage(string $buildId, string $deploymentId, string $projectID): Document
{
    global $runtimes;
    global $orchestrationPool;
    global $register;

    /** @var Orchestration $orchestration */
    $orchestration = $orchestrationPool->get();

    $buildStdout = '';
    $buildStderr = '';

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();
    $cache = new Cache(new RedisCache($redis));

    $database = new Database(new MariaDB($db), $cache);
    $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
    $database->setNamespace('_project_' . $projectID);

    // Check if build has already been run
    $build = $database->getDocument('builds', $buildId);
    $deployment = $database->getDocument('deployments', $deploymentId);

    try {
        // If we already have a built package ready there is no need to rebuild.
        if ($build->getAttribute('status') === 'ready' && \file_exists($build->getAttribute('outputPath'))) {
            return $build;
        }

        // Update deployment Status
        $build->setAttribute('status', 'building');
        $deployment->setAttribute('status', 'building');
        $database->updateDocument('builds', $buildId, $build);
        $database->updateDocument('deployments', $deploymentId, $deployment);

        // Check if runtime is active
        $runtime = $runtimes[$build->getAttribute('runtime', '')] ?? null;

        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $build->getAttribute('runtime', '') . '" is not supported');
        }

        // Grab Deployment Files
        $deploymentPath = $build->getAttribute('source', '');
        $device = Storage::getDevice('builds');

        $deploymentPathTarget = '/tmp/project-' . $projectID . '/' . $buildId . '/code.tar.gz';
        $deploymentPathTargetDir = \pathinfo($deploymentPathTarget, PATHINFO_DIRNAME);

        $container = 'build-stage-' . $buildId;

        // Perform various checks
        if (!\file_exists($deploymentPathTargetDir)) {
            if (@\mkdir($deploymentPathTargetDir, 0777, true)) {
                \chmod($deploymentPathTargetDir, 0777);
            } else {
                throw new Exception('Can\'t create directory ' . $deploymentPathTargetDir);
            }
        }

        if (!\file_exists($deploymentPathTarget)) {
            if (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) === Storage::DEVICE_LOCAL) {
                if (!\copy($deploymentPath, $deploymentPathTarget)) {
                    throw new Exception('Can\'t create temporary code file ' . $deploymentPathTarget);
                }
            } else {
                $buffer = $device->read($deploymentPath);
                \file_put_contents($deploymentPathTarget, $buffer);
            }
        }

        if (!$device->exists($deploymentPath)) {
            throw new Exception('Code is not readable: ' . $build->getAttribute('source', ''));
        }

        $vars = $build->getAttribute('vars', []);

        // Start tracking time
        $buildStart = \microtime(true);
        $time = \time();

        $orchestration
            ->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', 0))
            ->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', 256))
            ->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', 256));

        $vars = array_map(fn ($v) => strval($v), $vars);
        $path = '/tmp/project-' . $projectID . '/' . $buildId . '/builtCode';

        if (!\file_exists($path)) {
            if (@\mkdir($path, 0777, true)) {
                \chmod($path, 0777);
            } else {
                throw new Exception('Can\'t create directory /tmp/project-' . $projectID . '/' . $buildId . '/builtCode');
            }
        }

        // Launch build container
        $id = $orchestration->run(
            image: $runtime['base'],
            name: $container,
            vars: $vars,
            workdir: '/usr/code',
            labels: [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($time),
                'appwrite-runtime' => $build->getAttribute('runtime', ''),
                'appwrite-project' => $projectID,
                'appwrite-build' => $buildId,
            ],
            command: [
                'tail',
                '-f',
                '/dev/null'
            ],
            hostname: $container,
            mountFolder: $deploymentPathTargetDir,
            volumes: [
                '/tmp/project-' . $projectID . '/' . $buildId . '/builtCode' . ':/usr/builtCode:rw'
            ]
        );

        if (empty($id)) {
            throw new Exception('Failed to start build container');
        }

        // Extract user code into build container
        $untarStdout = '';
        $untarStderr = '';

        $untarSuccess = $orchestration->execute(
            name: $container,
            command: [
                'sh',
                '-c',
                'mkdir -p /usr/code && cp /tmp/code.tar.gz /usr/workspace/code.tar.gz && cd /usr/workspace/ && tar -zxf /usr/workspace/code.tar.gz -C /usr/code && rm /usr/workspace/code.tar.gz'
            ],
            stdout: $untarStdout,
            stderr: $untarStderr,
            timeout: 60
        );

        if (!$untarSuccess) {
            throw new Exception('Failed to extract tar: ' . $untarStderr);
        }

        // Build Code / Install Dependencies
        $buildSuccess = $orchestration->execute(
            name: $container,
            command: ['sh', '-c', 'cd /usr/local/src && ./build.sh'],
            stdout: $buildStdout,
            stderr: $buildStderr,
            timeout: App::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900)
        );

        if (!$buildSuccess) {
            throw new Exception('Failed to build dependencies: ' . $buildStderr);
        }

        // Repackage Code and Save.
        $compressStdout = '';
        $compressStderr = '';

        $builtCodePath = '/tmp/project-' . $projectID . '/' . $build->getId() . '/builtCode/code.tar.gz';

        $compressSuccess = $orchestration->execute(
            name: $container,
            command: [
                'tar', '-C', '/usr/code', '-czvf', '/usr/builtCode/code.tar.gz', './'
            ],
            stdout: $compressStdout,
            stderr: $compressStderr,
            timeout: 60
        );

        if (!$compressSuccess) {
            throw new Exception('Failed to compress built code: ' . $compressStderr);
        }

        // Remove Container
        $orchestration->remove($id, true);

        // Check if the build was successful by checking if file exists
        if (!\file_exists($builtCodePath)) {
            throw new Exception('Something went wrong during the build process.');
        }

        // Upload new code
        $device = Storage::getDevice('builds');

        $path = $device->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

        if (!\file_exists(\dirname($path))) { // Checks if directory path to file exists
            if (@\mkdir(\dirname($path), 0777, true)) {
                \chmod(\dirname($path), 0777);
            } else {
                throw new Exception('Can\'t create directory: ' . \dirname($path));
            }
        }

        if (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) === Storage::DEVICE_LOCAL) {
            if (!$device->move($builtCodePath, $path)) {
                throw new Exception('Failed to upload built code upload to storage', 500);
            }
        } else {
            if (!$device->upload($builtCodePath, $path)) {
                throw new Exception('Failed to upload built code upload to storage', 500);
            }
        }

        if ($buildStdout === '') {
            $buildStdout = 'Build Successful!';
        }

        $build
            ->setAttribute('outputPath', $path)
            ->setAttribute('status', 'ready')
            ->setAttribute('stdout',  \utf8_encode(\mb_substr($buildStdout, -4096)))
            ->setAttribute('stderr', \utf8_encode(\mb_substr($buildStderr, -4096)))
            ->setAttribute('time', $time);

        // Update build with built code attribute
        $build = $database->updateDocument('builds', $buildId, $build);

        $deployment->setAttribute('status', 'ready');
        $database->updateDocument('deployments', $deploymentId, $deployment);

        $buildEnd = \microtime(true);

        Console::info('Build Stage Ran in ' . ($buildEnd - $buildStart) . ' seconds');
    } catch (Exception $e) {
        $build
            ->setAttribute('status', 'failed')
            ->setAttribute('stdout',  \utf8_encode(\mb_substr($buildStdout, -4096)))
            ->setAttribute('stderr', \utf8_encode(\mb_substr($e->getMessage(), -4096)));

        $build = $database->updateDocument('builds', $buildId, $build);

        $deployment->setAttribute('status', 'failed');
        $database->updateDocument('deployments', $deploymentId, $deployment);

        // also remove the container if it exists
        if (isset($id)) {
            $orchestration->remove($id, true);
        }

        $register->get('dbPool')->put($db);
        $register->get('redisPool')->put($redis);

        throw new Exception('Build failed: ' . $e->getMessage());
    }

    $orchestrationPool->put($orchestration);

    $register->get('dbPool')->put($db);
    $register->get('redisPool')->put($redis);

    return $build;
}

App::post('/v1/functions/:functionId/executions')
    ->desc('Execute a function')
    ->param('trigger', '', new Text(1024), 'What triggered this execution, can be http / schedule / event')
    ->param('projectId', '', new Text(1024), 'The ProjectID this execution belongs to')
    ->param('executionId', '', new Text(1024), 'An optional execution ID, If not specified a new execution document is created.', true)
    ->param('functionId', '', new Text(1024), 'The FunctionID to execute')
    ->param('event', '', new Text(1024), 'The event that triggered this execution', true)
    ->param('eventData', '', new Text(0), 'Extra Data for the event', true)
    ->param('data', '', new Text(1024), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('webhooks', [], new ArrayList(new JSON()), 'Any webhooks that need to be triggered after this execution', true)
    ->param('userId', '', new Text(1024), 'The UserID of the user who triggered the execution if it was called from a client SDK', true)
    ->param('jwt', '', new Text(1024), 'A JWT of the user who triggered the execution if it was called from a client SDK', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(
        function (string $trigger, string $projectId, string $executionId, string $functionId, string $event, string $eventData, string $data, array $webhooks, string $userId, string $jwt, Response $response, Database $dbForProject) {
            $data = execute($trigger, $projectId, $executionId, $functionId, $dbForProject, $event, $eventData, $data, $webhooks, $userId, $jwt);
            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($data);
        }
    );

App::delete('/v1/functions/:functionId')
    ->desc('Delete a function')
    ->param('functionId', '', new UID(), 'The FunctionID to delete')
    ->inject('response')
    ->inject('dbForProject')
    ->action(
        function (string $functionId, Response $response, Database $dbForProject) use ($orchestrationPool) {
            try {
                /** @var Orchestration $orchestration */
                $orchestration = $orchestrationPool->get();
                
                // Get function document
                $function = $dbForProject->getDocument('functions', $functionId);

                // Check if function exists
                if ($function->isEmpty()) {
                    throw new Exception('Function not found', 404);
                }

                $results = $dbForProject->find('deployments', [new Query('functionId', Query::TYPE_EQUAL, [$functionId])], 999);

                // If amount is 0 then we simply return true
                if (count($results) === 0) {
                    $response
                        ->setStatusCode(Response::STATUS_CODE_OK)
                        ->send();
                }

                // Delete the containers of all deployments
                foreach ($results as $deployment) {
                    // Remove any ongoing builds
                    if ($deployment->getAttribute('buildId')) {
                        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId'));

                        if ($build->getAttribute('status') === 'building') {
                            // Remove the build
                            $orchestration->remove('build-stage-' . $deployment->getAttribute('buildId'), true);
                            Console::info('Removed build for deployment ' . $deployment['$id']);
                        }
                    }

                    $orchestration->remove('appwrite-function-' . $deployment['$id'], true);
                    Console::info('Removed container for deployment ' . $deployment['$id']);
                }

                $response
                    ->setStatusCode(Response::STATUS_CODE_OK)
                    ->send();
            } catch (Throwable $th) {
                $orchestrationPool->put($orchestration);
                throw $th; 
            } finally {
                $orchestrationPool->put($orchestration);
            }
        }
    );

App::post('/v1/functions/:functionId/deployments/:deploymentId/runtime')
    ->desc('Create a new runtime server for a deployment')
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('deploymentId', '', new UID(), 'Deployment unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('projectId')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, string $projectID) use ($runtimes) {
        // Get function document
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        // Get deployment document
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $runtime = $runtimes[$function->getAttribute('runtime')] ?? null;
        if (\is_null($runtime)) {
            throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" not found.', 404);
        }

        createRuntimeServer($functionId, $projectID, $deploymentId, $dbForProject);

        $response
            ->setStatusCode(201)
            ->send();
    });

App::delete('/v1/deployments/:deploymentId')
    ->desc('Delete a deployment')
    ->param('deploymentId', '', new UID(), 'Deployment unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $deploymentId, Response $response, Database $dbForProject) use ($orchestrationPool) {
        try {
            /** @var Orchestration $orchestration */
            $orchestration = $orchestrationPool->get();
            
            // Get deployment document
            $deployment = $dbForProject->getDocument('deployments', $deploymentId);

            // Check if deployment exists
            if ($deployment->isEmpty()) {
                throw new Exception('Deployment not found', 404);
            }

            // Remove any ongoing builds
            if ($deployment->getAttribute('buildId')) {
                $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId'));

                if ($build->getAttribute('status') === 'building') {
                    // Remove the build
                    $orchestration->remove('build-stage-' . $deployment->getAttribute('buildId'), true);
                    Console::info('Removed build for deployment ' . $deployment['$id']);
                }
            }

            // Remove the container of the deployment
            $orchestration->remove('appwrite-function-' . $deployment['$id'], true);
            Console::info('Removed container for deployment ' . $deployment['$id']);
        } catch (Throwable $th) {
            $orchestrationPool->put($orchestration);
            throw $th;
        }
        $orchestrationPool->put($orchestration);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

App::post('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
    ->desc("Create a new build")
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('deploymentId', '', new UID(), 'Deployment unique ID.', false)
    ->param('buildId', '', new UID(), 'Build unique ID.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('projectId')
    ->action(function (string $functionId, string $deploymentId, string $buildId, Response $response, Database $dbForProject, string $projectId) {
        
        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception('Function not found', 404);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception('Deployment not found', 404);
        }

        $build = $dbForProject->getDocument('builds', $buildId);
        if ($build->isEmpty()) {
            throw new Exception('Build not found', 404);
        }

        if ($build->getAttribute('status') === 'building') {
            throw new Exception('Build is already running', 409);
        }

        // Check if build is already finished
        if ($build->getAttribute('status') === 'ready') {
            throw new Exception('Build is already finished', 409);
        }

        go(function() use ($functionId, $deploymentId, $buildId, $projectId, $dbForProject, $function, $deployment) {
            Console::info('Starting build for deployment ' . $deployment['$id']);
            runBuildStage($buildId, $deploymentId, $projectId);

            // Update the schedule
            $schedule = $function->getAttribute('schedule', '');
            $cron = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? new CronExpression($schedule) : null;
            $next = (empty($function->getAttribute('deployment')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : 0;

            // Grab build
            $build = $dbForProject->getDocument('builds', $buildId);

            // If the build failed, it won't be possible to deploy
            if ($build->getAttribute('status') !== 'ready') {
                throw new Exception('Build failed', 500);
            }

            if ($deployment->getAttribute('deploy') === true) {
                // Update the function document setting the deployment as the active one
                $function
                    ->setAttribute('deployment', $deployment->getId())
                    ->setAttribute('scheduleNext', (int)$next);

                $function = $dbForProject->updateDocument('functions', $functionId, $function);
            }

            // Deploy Runtime Server
            try {
                Console::info("[ INFO ] Creating runtime server");
                createRuntimeServer($functionId, $projectId, $deploymentId, $dbForProject);
            } catch (\Throwable $th) {
                Console::error($th->getMessage());
                $deployment->setAttribute('status', 'failed');
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment);
                throw $th;
            }
        });

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->send();
    });

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

function handleShutdown()
{
    global $orchestrationPool;
    global $register;

    try {
        Console::info('Cleaning up containers before shutdown...');

        // Remove all containers.

        /** @var Orchestration $orchestration */
        $orchestration = $orchestrationPool->get();

        $functionsToRemove = $orchestration->list(['label' => 'appwrite-type=function']);

        foreach ($functionsToRemove as $container) {
            go(fn () => $orchestration->remove($container->getId(), true));

            // Get a database instance
            $db = $register->get('dbPool')->get();
            $cache = $register->get('redisPool')->get();

            $cache = new Cache(new RedisCache($cache));
            $database = new Database(new MariaDB($db), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace('_project_' . $container->getLabels()["appwrite-project"]);

            // Get list of all processing executions
            $executions = $database->find('executions', [
                new Query('deploymentId', Query::TYPE_EQUAL, [$container->getLabels()["appwrite-deployment"]]),
                new Query('status', Query::TYPE_EQUAL, ['waiting'])
            ]);

            // Mark all processing executions as failed
            foreach ($executions as $execution) {
                $execution
                    ->setAttribute('status', 'failed')
                    ->setAttribute('statusCode', 1)
                    ->setAttribute('stderr', 'Appwrite was shutdown during execution');

                $database->updateDocument('executions', $execution->getId(), $execution);
            }

            Console::info('Removed container ' . $container->getName());
        }
    } catch (\Throwable $error) {
        logError($error, 'shutdownError');
    } finally {
        $orchestrationPool->put($orchestration);
    }
};

$http->on('start', function ($http) {
    @Process::signal(SIGINT, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGQUIT, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGKILL, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGTERM, function () use ($http) {
        handleShutdown();
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    global $register;

    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();

    App::setResource('db', fn () => $db);
    App::setResource('cache', fn () => $redis);

    $projectId = $request->getHeader('x-appwrite-project', '');

    Storage::setDevice('functions', new Local(APP_STORAGE_FUNCTIONS . '/app-' . $projectId));
    Storage::setDevice('builds', new Local(APP_STORAGE_BUILDS . '/app-' . $projectId));

    // Check environment variable key
    $secretKey = $request->getHeader('x-appwrite-executor-key', '');

    if (empty($secretKey)) {
        $swooleResponse->status(401);
        return $swooleResponse->end('401: Authentication Error');
    }

    if ($secretKey !== App::getEnv('_APP_EXECUTOR_SECRET', '')) {
        $swooleResponse->status(401);
        return $swooleResponse->end('401: Authentication Error');
    }

    App::setResource('dbForProject', function ($db, $cache) use ($projectId) {
        $cache = new Cache(new RedisCache($cache));

        $database = new Database(new MariaDB($db), $cache);
        $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $database->setNamespace('_project_' . $projectId);

        return $database;
    }, ['db', 'cache']);

    App::error(function ($error, $utopia, $request, $response) {
        /** @var Exception $error */
        /** @var Utopia\App $utopia */
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        if ($error instanceof PDOException) {
            throw $error;
        }

        $route = $utopia->match($request);
        logError($error, "httpError", $route);

        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $code = $error->getCode();
        $message = $error->getMessage();

        $output = ((App::isDevelopment())) ? [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTrace(),
            'version' => $version,
        ] : [
            'message' => $message,
            'code' => $code,
            'version' => $version,
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode(500);

        $response->dynamic(
            new Document($output),
            $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR
        );
    }, ['error', 'utopia', 'request', 'response']);

    App::setResource('projectId', function () use ($projectId) {
        return $projectId;
    });

    try {
        $app->run($request, $response);
    } catch (Exception $e) {
        logError($e, "serverError");
        $swooleResponse->end('500: Server Error');
    } finally {
        /** @var PDOPool $dbPool */
        $dbPool = $register->get('dbPool');
        $dbPool->put($db);

        /** @var RedisPool $redisPool */
        $redisPool = $register->get('redisPool');
        $redisPool->put($redis);
    }
});

$http->start();