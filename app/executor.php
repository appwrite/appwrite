<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Utopia\Response;
use Swoole\ConnectionPool;
use Swoole\Coroutine as Co;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\JSON;
use Utopia\Validator\Range as ValidatorRange;
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

function createRuntimeServer(string $projectId, string $deploymentId, array $build, array $vars, string $baseImage, string $runtime): array
{
    global $orchestrationPool;
    global $activeFunctions;

    $orchestration = $orchestrationPool->get();

    try {
        $container = 'appwrite-function-' . $deploymentId;
        if ($activeFunctions->exists($container) && !(\substr($activeFunctions->get($container)['status'], 0, 2) === 'Up')) { // Remove container if not online
            // If container is online then stop and remove it
            try {
                $orchestration->remove($container, true);
            } catch (Exception $e) {
                throw new Exception('Failed to remove container: ' . $e->getMessage());
            }
            $activeFunctions->del($container);
        }

        /** Storage stuff */
        $deploymentPath = $build['outputPath'];
        $deploymentPathTarget = '/tmp/project-' . $projectId . '/' . $build['$id'] . '/builtCode/code.tar.gz';
        $deploymentPathTargetDir = \pathinfo($deploymentPathTarget, PATHINFO_DIRNAME);

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
        /** End Storage stuff */

        // Generate random secret key
        $secret = \bin2hex(\random_bytes(16));
        $vars = \array_merge($vars, [
            // 'ENTRYPOINT_NAME' => $deployment->getAttribute('entrypoint', ''),
            // 'APPWRITE_FUNCTION_ID' => $function->getId(),
            // 'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            // 'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            // 'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            // 'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
            'INTERNAL_RUNTIME_KEY' => $secret
        ]);

        /** Launch Runtime */
        if (!$activeFunctions->exists($container)) {
            $executionStart = \microtime(true);
            $executionTime = \time();

            $vars = array_map(fn ($v) => strval($v), $vars);

            $orchestration
                ->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', '1'))
                ->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', '256'))
                ->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '256'));

            $id = $orchestration->run(
                image: $baseImage,
                name: $container,
                vars: $vars,
                labels: [
                    'appwrite-type' => 'function',
                    'appwrite-created' => strval($executionTime),
                    'appwrite-runtime' => $runtime,
                    'appwrite-project' => $projectId,
                    'appwrite-deployment' => $deploymentId,
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
        }
        /** End Launch Runtime */

        Console::success('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
    } catch (\Throwable $th) {
        $build['status'] = 'failed';
        Console::error('Runtime Server Creation Failed: '. $th->getMessage());
    } finally {
        $orchestrationPool->put($orchestration);
        return $build;
    }
};

function execute(string $projectId, string $functionId, string $deploymentId, array $build, array $vars, string $data, string $userId, string $baseImage, string $runtime, string $entrypoint, int $timeout, array $webhooks = []): array
{

    Console::info('Executing function: ' . $functionId);

    global $activeFunctions;
    $container = 'appwrite-function-' . $deploymentId;

    /** Create a new runtime server if there's none running */
    if (!$activeFunctions->exists($container)) {
        Console::info("Runtime server for $deploymentId not running. Creating new one...");
        createRuntimeServer($projectId, $deploymentId, $build, $vars, $baseImage, $runtime);
    }

    $key = $activeFunctions->get('appwrite-function-' . $deploymentId, 'key');

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
            'file' => $entrypoint,
            'env' => $vars,
            'payload' => $data,
            'timeout' => $timeout ?? (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)
        ]);

        \curl_setopt($ch, CURLOPT_URL, "http://" . $container . ":3000/");
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout ?? (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900));
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

    Console::success('Function executed in ' . $executionTime . ' seconds, status: ' . $functionStatus);

    $execution = [
        'status' => $functionStatus,
        'statusCode' => $statusCode,
        'stdout' => \utf8_encode(\mb_substr($stdout, -8000)),
        'stderr' => \utf8_encode(\mb_substr($stderr, -8000)),
        'time' => $executionTime,
    ];

    return $execution;
};

function runBuildStage(string $buildId, string $projectID, string $path, array $vars, string $baseImage, string $runtime): array
{
    
    global $orchestrationPool;
    $orchestration = $orchestrationPool->get();

    $build = [];
    $id = '';
    $buildStdout = '';
    $buildStderr = '';
    $buildStart = \time();
    $buildEnd = 0;

    try {
        Console::info('Running build stage: ' . $buildId);
        // Grab Deployment Files
        $deploymentPath = $path;
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
            throw new Exception('Code is not readable: ' . $path);
        }

        $vars = array_map(fn ($v) => strval($v), $vars);
        $path = '/tmp/project-' . $projectID . '/' . $buildId . '/builtCode';

        if (!\file_exists($path)) {
            if (@\mkdir($path, 0777, true)) {
                \chmod($path, 0777);
            } else {
                throw new Exception('Can\'t create directory /tmp/project-' . $projectID . '/' . $buildId . '/builtCode');
            }
        }

        $orchestration
            ->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', 0))
            ->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', 256))
            ->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', 256));

        $id = $orchestration->run(
            image: $baseImage,
            name: $container,
            vars: $vars,
            workdir: '/usr/code',
            labels: [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($buildStart),
                'appwrite-runtime' => $runtime,
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

        $builtCodePath = '/tmp/project-' . $projectID . '/' . $buildId . '/builtCode/code.tar.gz';

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

        $buildEnd = \time();
        $build = [
            '$id' => $buildId,
            'outputPath' => $path,
            'status' => 'ready',
            'stdout' => \utf8_encode(\mb_substr($buildStdout, -4096)),
            'stderr' => \utf8_encode(\mb_substr($buildStderr, -4096)),
            'startTime' => $buildStart,
            'endTime' => $buildEnd,
            'duration' => $buildEnd - $buildStart,
        ];
        
        Console::success('Build Stage Ran in ' . ($buildEnd - $buildStart) . ' seconds');
    } catch (Throwable $th) {
        $buildEnd = \time();
        $buildStderr = $th->getMessage();
        $build = [
            '$id' => $buildId,
            'status' => 'failed',
            'stdout' => \utf8_encode(\mb_substr($buildStdout, -4096)),
            'stderr' => \utf8_encode(\mb_substr($buildStderr, -4096)),
            'startTime' => $buildStart,
            'endTime' => $buildEnd,
            'duration' => $buildEnd - $buildStart,
        ];
        Console::error('Build failed: ' . $th->getMessage());
    } finally {
        if (!empty($id)) {
            $orchestration->remove($id, true);
        }
        $orchestrationPool->put($orchestration);
        return $build;
    }
}

App::post('/v1/execution')
    ->desc('Create a function execution')
    ->param('functionId', '', new Text(1024), 'The FunctionID to execute')
    ->param('deploymentId', '', new Text(1024), 'The deployment ID to execute')
    ->param('buildId', '', new Text(1024), 'The build ID of the function')
    ->param('path', '', new Text(0), 'Path to built files.', false)
    ->param('vars', '', new Assoc(), 'Environment Variables required for the build', false)
    ->param('data', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function', false)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file')
    ->param('timeout', 15, new ValidatorRange(1, 900), 'Function maximum execution time in seconds.', true)
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime', false)
    ->param('webhooks', [], new ArrayList(new JSON()), 'Any webhooks that need to be triggered after this execution', true)
    ->param('userId', '', new Text(1024), 'The UserID of the user who triggered the execution if it was called from a client SDK', true)
    ->inject('projectId')
    ->inject('response')
    ->action(
        function (string $functionId, string $deploymentId, string $buildId, string $path, array $vars, string $data, string $runtime, string $entrypoint, $timeout, string $baseImage, array $webhooks, string $userId, string $projectId, Response $response) {

            $build = [
                '$id' => $buildId,
                'outputPath' => $path,
            ];

            // Send both data and vars from the caller 
            $execution = execute($projectId, $functionId, $deploymentId, $build, $vars, $data, $userId, $baseImage, $runtime, $entrypoint, $timeout, $webhooks);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

App::delete('/v1/deployments/:deploymentId')
    ->desc('Delete a deployment')
    ->param('deploymentId', '', new UID(), 'Deployment unique ID.', false)
    ->param('buildIds', [], new ArrayList(new Text(0), 100), 'List of build IDs to delete.', false)
    ->inject('response')
    ->action(function (string $deploymentId, array $buildIds, Response $response) use ($orchestrationPool) {

        Console::info('Deleting deployment: ' . $deploymentId);
        $orchestration = $orchestrationPool->get();

        // Remove the container of the deployment
        $status = $orchestration->remove('appwrite-function-' . $deploymentId , true);
        if ($status) {
            Console::success('Removed container for deployment: ' . $deploymentId);
        } else {
            Console::error('Failed to remove container for deployment: ' . $deploymentId);
        }

        // Remove all the build containers
        foreach ($buildIds as $buildId) {
            $status = $orchestration->remove('build-stage-' . $buildId, true);
            if ($status) {
                Console::success("Removed build container: $buildId for deployment: " . $deploymentId);
            } else {
                Console::error("Failed to remove build container: $buildId for deployment: " . $deploymentId);
            }
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
    ->param('path', '', new Text(0), 'Path to source files.', false)
    ->param('vars', '', new Assoc(), 'Environment Variables required for the build', false)
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function', false)
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime', false)
    ->inject('projectId')
    ->inject('response')
    ->action(function (string $functionId, string $deploymentId, string $buildId, string $path, array $vars, string $runtime, string $baseImage, string $projectId, Response $response) {

        $build = runBuildStage($buildId, $projectId, $path, $vars, $baseImage, $runtime);

        if ( $build['status'] === 'ready') {
            $build = createRuntimeServer($projectId, $deploymentId, $build, $vars, $baseImage, $runtime);
        }

        $response
            ->setStatusCode(201)
            ->json($build);
    });

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

// function handleShutdown()
// {
//     global $orchestrationPool;
//     global $register;

//     try {
//         Console::info('Cleaning up containers before shutdown...');

//         // Remove all containers.

//         /** @var Orchestration $orchestration */
//         $orchestration = $orchestrationPool->get();

//         $functionsToRemove = $orchestration->list(['label' => 'appwrite-type=function']);

//         foreach ($functionsToRemove as $container) {
//             go(fn () => $orchestration->remove($container->getId(), true));

//             // Get a database instance
//             $db = $register->get('dbPool')->get();
//             $cache = $register->get('redisPool')->get();

//             $cache = new Cache(new RedisCache($cache));
//             $database = new Database(new MariaDB($db), $cache);
//             $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
//             $database->setNamespace('_project_' . $container->getLabels()["appwrite-project"]);

//             // Get list of all processing executions
//             $executions = $database->find('executions', [
//                 new Query('deploymentId', Query::TYPE_EQUAL, [$container->getLabels()["appwrite-deployment"]]),
//                 new Query('status', Query::TYPE_EQUAL, ['waiting'])
//             ]);

//             // Mark all processing executions as failed
//             foreach ($executions as $execution) {
//                 $execution
//                     ->setAttribute('status', 'failed')
//                     ->setAttribute('statusCode', 1)
//                     ->setAttribute('stderr', 'Appwrite was shutdown during execution');

//                 $database->updateDocument('executions', $execution->getId(), $execution);
//             }

//             Console::info('Removed container ' . $container->getName());
//         }
//     } catch (\Throwable $error) {
//         logError($error, 'shutdownError');
//     } finally {
//         $orchestrationPool->put($orchestration);
//     }
// };

$http->on('start', function ($http) {
    @Process::signal(SIGINT, function () use ($http) {
        // handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGQUIT, function () use ($http) {
        // handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGKILL, function () use ($http) {
        // handleShutdown();
        $http->shutdown();
    });

    @Process::signal(SIGTERM, function () use ($http) {
        // handleShutdown();
        $http->shutdown();
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

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
    }
});

$http->start();