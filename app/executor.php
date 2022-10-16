<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use Executor\Executor;
use Swoole\ConnectionPool;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;


Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

/** Constants */
const MAINTENANCE_INTERVAL = 3600; // 3600 seconds = 1 hour

/**
* Create a Swoole table to store runtime information
*/
$activeRuntimes = new Swoole\Table(1024);
$activeRuntimes->column('id', Swoole\Table::TYPE_STRING, 256);
$activeRuntimes->column('created', Swoole\Table::TYPE_INT, 8);
$activeRuntimes->column('updated', Swoole\Table::TYPE_INT, 8);
$activeRuntimes->column('name', Swoole\Table::TYPE_STRING, 128);
$activeRuntimes->column('status', Swoole\Table::TYPE_STRING, 128);
$activeRuntimes->column('key', Swoole\Table::TYPE_STRING, 256);
$activeRuntimes->create();

/**
 * Create orchestration pool
 */
$orchestrationPool = new ConnectionPool(function () {
    $dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
    $dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
    $orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));
    return $orchestration;
}, 10);


/**
 * Create logger instance
 */
$providerName = App::getEnv('_APP_LOGGING_PROVIDER', '');
$providerConfig = App::getEnv('_APP_LOGGING_CONFIG', '');
$logger = null;

if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);
    $adapter = new $classname($providerConfig);
    $logger = new Logger($adapter);
}

function logError(Throwable $error, string $action, Utopia\Route $route = null)
{
    global $logger;

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
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        $log->addExtra('detailedTrace', $error->getTrace());

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
}

function getStorageDevice($root): Device
{
    switch (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)) {
        case Storage::DEVICE_LOCAL:
        default:
            return new Local($root);
        case Storage::DEVICE_S3:
            $s3AccessKey = App::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
            $s3SecretKey = App::getEnv('_APP_STORAGE_S3_SECRET', '');
            $s3Region = App::getEnv('_APP_STORAGE_S3_REGION', '');
            $s3Bucket = App::getEnv('_APP_STORAGE_S3_BUCKET', '');
            $s3Acl = 'private';
            return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
        case Storage::DEVICE_DO_SPACES:
            $doSpacesAccessKey = App::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
            $doSpacesSecretKey = App::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
            $doSpacesRegion = App::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
            $doSpacesBucket = App::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
            $doSpacesAcl = 'private';
            return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
        case Storage::DEVICE_BACKBLAZE:
            $backblazeAccessKey = App::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
            $backblazeSecretKey = App::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
            $backblazeRegion = App::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
            $backblazeBucket = App::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
            $backblazeAcl = 'private';
            return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
        case Storage::DEVICE_LINODE:
            $linodeAccessKey = App::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
            $linodeSecretKey = App::getEnv('_APP_STORAGE_LINODE_SECRET', '');
            $linodeRegion = App::getEnv('_APP_STORAGE_LINODE_REGION', '');
            $linodeBucket = App::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
            $linodeAcl = 'private';
            return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
        case Storage::DEVICE_WASABI:
            $wasabiAccessKey = App::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
            $wasabiSecretKey = App::getEnv('_APP_STORAGE_WASABI_SECRET', '');
            $wasabiRegion = App::getEnv('_APP_STORAGE_WASABI_REGION', '');
            $wasabiBucket = App::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
            $wasabiAcl = 'private';
            return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
    }
}

App::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('destination', '', new Text(0), 'Destination folder to store build files into.', true)
    ->param('vars', [], new Assoc(), 'Environment Variables required for the build.')
    ->param('commands', [], new ArrayList(new Text(1024), 100), 'Commands required to build the container. Maximum of 100 commands are allowed, each 1024 characters long.')
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function.')
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.')
    ->param('workdir', '', new Text(256), 'Working directory.', true)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, string $source, string $destination, array $vars, array $commands, string $runtime, string $baseImage, string $entrypoint, bool $remove, string $workdir, $orchestrationPool, $activeRuntimes, Response $response) {
        if ($activeRuntimes->exists($runtimeId)) {
            if ($activeRuntimes->get($runtimeId)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 500);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $containerId = '';
        $stdout = '';
        $stderr = '';
        $startTime = DateTime::now();
        $startTimeUnix = (new \DateTime($startTime))->getTimestamp();
        $endTimeUnix = 0;
        $orchestration = $orchestrationPool->get();

        $secret = \bin2hex(\random_bytes(16));

        if (!$remove) {
            $activeRuntimes->set($runtimeId, [
                'id' => $containerId,
                'name' => $runtimeId,
                'created' => $startTimeUnix,
                'updated' => $endTimeUnix,
                'status' => 'pending',
                'key' => $secret,
            ]);
        }

        try {
            Console::info('Building container : ' . $runtimeId);

            /**
             * Temporary file paths in the executor
             */
            $tmpSource = "/tmp/$runtimeId/src/code.tar.gz";
            $tmpBuild = "/tmp/$runtimeId/builds/code.tar.gz";

            /**
             * Copy code files from source to a temporary location on the executor
             */
            $sourceDevice = getStorageDevice("/");
            $localDevice = new Local();
            $buffer = $sourceDevice->read($source);
            if (!$localDevice->write($tmpSource, $buffer)) {
                throw new Exception('Failed to copy source code to temporary directory', 500);
            };

            /**
             * Create the mount folder
             */
            if (!\file_exists(\dirname($tmpBuild))) {
                if (!@\mkdir(\dirname($tmpBuild), 0755, true)) {
                    throw new Exception("Failed to create temporary directory", 500);
                }
            }

            /**
             * Create container
             */
            $vars = \array_merge($vars, [
                'INTERNAL_RUNTIME_KEY' => $secret,
                'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
            ]);
            $vars = array_map(fn ($v) => strval($v), $vars);
            $orchestration
                ->setCpus((int) App::getEnv('_APP_FUNCTIONS_CPUS', 0))
                ->setMemory((int) App::getEnv('_APP_FUNCTIONS_MEMORY', 0))
                ->setSwap((int) App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', 0));

            /** Keep the container alive if we have commands to be executed */
            $entrypoint = !empty($commands) ? [
                'tail',
                '-f',
                '/dev/null'
            ] : [];

            $executorName = \explode('-', $runtimeId)[0];

            $containerId = $orchestration->run(
                image: $baseImage,
                name: $runtimeId,
                hostname: $runtimeId,
                vars: $vars,
                command: $entrypoint,
                labels: [
                    'openruntimes-id' => $runtimeId,
                    'openruntimes-type' => 'runtime',
                    'openruntimes-executor' => $executorName,
                    'openruntimes-created' => strval($startTimeUnix),
                    'openruntimes-runtime' => $runtime,
                ],
                workdir: $workdir,
                volumes: [
                    \dirname($tmpSource) . ':/tmp:rw',
                    \dirname($tmpBuild) . ':/usr/code:rw'
                ]
            );

            if (empty($containerId)) {
                throw new Exception('Failed to create build container', 500);
            }

            $orchestration->networkConnect($runtimeId, App::getEnv('OPEN_RUNTIMES_NETWORK', 'appwrite_runtimes'));

            /**
             * Execute any commands if they were provided
             */
            if (!empty($commands)) {
                $status = $orchestration->execute(
                    name: $runtimeId,
                    command: $commands,
                    stdout: $stdout,
                    stderr: $stderr,
                    timeout: App::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900)
                );

                if (!$status) {
                    throw new Exception('Failed to build dependenices ' . $stderr, 500);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!\file_exists($tmpBuild)) {
                    throw new Exception('Something went wrong during the build process', 500);
                }

                $destinationDevice = getStorageDevice($destination);
                $outputPath = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

                $buffer = $localDevice->read($tmpBuild);
                if (!$destinationDevice->write($outputPath, $buffer, $localDevice->getFileMimeType($tmpBuild))) {
                    throw new Exception('Failed to move built code to storage', 500);
                };

                $container['outputPath'] = $outputPath;
            }

            if (empty($stdout)) {
                $stdout = 'Build Successful!';
            }

            $endTime = DateTime::now();
            $endTimeUnix = (new \DateTime($endTime))->getTimestamp();
            $duration = $endTimeUnix - $startTimeUnix;

            $container = array_merge($container, [
                'status' => 'ready',
                'response' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'startTime' => $startTime,
                'endTime' => $endTime,
                'duration' => $duration,
            ]);


            if (!$remove) {
                $activeRuntimes->set($runtimeId, [
                    'id' => $containerId,
                    'name' => $runtimeId,
                    'created' => $startTimeUnix,
                    'updated' => $endTimeUnix,
                    'status' => 'Up ' . \round($duration, 2) . 's',
                    'key' => $secret,
                ]);
            }

            Console::success('Build Stage completed in ' . ($duration) . ' seconds');
        } catch (Throwable $th) {
            Console::error('Build failed: ' . $th->getMessage() . $stdout);

            throw new Exception($th->getMessage() . $stdout, 500);
        } finally {
            // Container cleanup
            if ($remove) {
                if (!empty($containerId)) {
                    // If container properly created
                    $orchestration->remove($containerId, true);
                    $activeRuntimes->del($runtimeId);
                } else {
                    // If whole creation failed, but container might have been initialized
                    try {
                        // Try to remove with contaier name instead of ID
                        $orchestration->remove($runtimeId, true);
                        $activeRuntimes->del($runtimeId);
                    } catch (Throwable $th) {
                        // If fails, means initialization also failed.
                        // Contianer is not there, no need to remove
                    }
                }
            }

            // Release orchestration back to pool, we are done with it
            $orchestrationPool->put($orchestration);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($container);
    });


App::get('/v1/runtimes')
    ->desc("List currently active runtimes")
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($activeRuntimes, Response $response) {
        $runtimes = [];

        foreach ($activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtimes);
    });

App::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($runtimeId, $activeRuntimes, Response $response) {

        if (!$activeRuntimes->exists($runtimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($runtimeId);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtime);
    });

App::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.', false)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, $orchestrationPool, $activeRuntimes, Response $response) {

        if (!$activeRuntimes->exists($runtimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        Console::info('Deleting runtime: ' . $runtimeId);

        try {
            $orchestration = $orchestrationPool->get();
            $orchestration->remove($runtimeId, true);
            $activeRuntimes->del($runtimeId);
            Console::success('Removed runtime container: ' . $runtimeId);
        } finally {
            $orchestrationPool->put($orchestration);
        }

        // Remove all the build containers with that same  ID
        // TODO:: Delete build containers
        // foreach ($buildIds as $buildId) {
        //     try {
        //         Console::info('Deleting build container : ' . $buildId);
        //         $status = $orchestration->remove('build-' . $buildId, true);
        //     } catch (Throwable $th) {
        //         Console::error($th->getMessage());
        //     }
        // }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });


App::post('/v1/execution')
    ->desc('Create an execution')
    // execution-related
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('vars', [], new Assoc(), 'Environment variables required for the build and execution.')
    ->param('data', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.')
    // runtime-related
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function.')
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(
        function (string $runtimeId, array $vars, string $data, $timeout, string $source, string $runtime, string $baseImage, string $entrypoint, $activeRuntimes, Response $response) {
            // Prepare runtime
            if (!$activeRuntimes->exists($runtimeId)) {
                $executor = new Executor('http://localhost/v1');

                for ($i = 0; $i < 5; $i++) {
                    try {
                        $runtimeResponse = $executor->createRuntime(
                            runtimeId: $runtimeId,
                            source: $source,
                            runtime: $runtime,
                            baseImage: $baseImage,
                            vars: $vars,
                            entrypoint: $entrypoint,
                            commands: [],
                            key: App::getEnv('_APP_EXECUTOR_SECRET', '')
                        );

                        break;
                    } catch (\Exception $error) {
                        if ($i === 4) {
                            throw new Exception('Runtime could not be created in allocated time: ' . $error->getMessage(), 500);
                        }
                    }

                    \sleep(1);
                }
            }

            // Ensure runtime started
            for ($i = 0; $i < 5; $i++) {
                if ($activeRuntimes->get($runtimeId)['status'] === 'pending') {
                    Console::info('Waiting for runtime to be ready...');
                    \sleep(1);
                } else {
                    break;
                }

                if ($i === 4) {
                    throw new Exception('Runtime failed to launch in allocated time.', 500);
                }
            }

            // Ensure we have secret
            $runtime = $activeRuntimes->get($runtimeId);
            $secret = $runtime['key'];
            if (empty($secret)) {
                throw new Exception('Runtime secret not found. Please re-create the runtime.', 500);
            }

            Console::info('Executing Runtime: ' . $runtimeId);

            $executionStart = \microtime(true);

            // Prepare request to executor
            $sendExecuteRequest = function () use ($vars, $data, $runtimeId, $secret, &$executionStart, &$timeout) {
                // Restart execution timer to not could failed attempts
                $executionStart = \microtime(true);

                $statusCode = 0;
                $errNo = -1;
                $executorResponse = '';

                $timeout ??= (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900);

                $ch = \curl_init();
                $body = \json_encode([
                    'variables' => $vars,
                    'payload' => $data,
                    'timeout' => $timeout
                ]);
                \curl_setopt($ch, CURLOPT_URL, "http://" . $runtimeId . ":3000/");
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Content-Length: ' . \strlen($body),
                    'x-internal-challenge: ' . $secret,
                    'host: null'
                ]);

                $executorResponse = \curl_exec($ch);

                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

                $error = \curl_error($ch);

                $errNo = \curl_errno($ch);

                \curl_close($ch);

                return [
                    'errNo' => $errNo,
                    'error' => $error,
                    'statusCode' => $statusCode,
                    'executorResponse' => $executorResponse
                ];
            };

            // Execute function
            for ($i = 0; $i < 5; $i++) {
                [ 'errNo' => $errNo, 'error' => $error, 'statusCode' => $statusCode, 'executorResponse' => $executorResponse ] = \call_user_func($sendExecuteRequest);

                // No error
                if ($errNo === 0) {
                    break;
                }

                Console::info('Waiting for runtime to respond...');

                \sleep(1);

                if ($i === 4) {
                    throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 500);
                }
            }

            // Extract response
            $execution = [];
            $stdout = '';
            $stderr = '';
            $res = '';

            $executorResponse = json_decode($executorResponse ?? '{}', true);

            switch (true) {
                case $statusCode >= 500:
                    $stderr = $executorResponse['stderr'] ?? '';
                    $stdout = $executorResponse['stdout'] ?? '';
                    break;
                case $statusCode >= 100:
                    $stdout = $executorResponse['stdout'] ?? '';
                    $res = $executorResponse['response'] ?? '';
                    if (is_array($res)) {
                        $res = json_encode($res, JSON_UNESCAPED_UNICODE);
                    }
                    break;
                default:
                    $stderr = $executorResponse['stderr'] ?? '';
                    $stdout = $executorResponse['stdout'] ?? '';
                    break;
            }

            $executionEnd = \microtime(true);
            $executionTime = ($executionEnd - $executionStart);
            $functionStatus = ($statusCode >= 500) ? 'failed' : 'completed';

            Console::success('Function executed in ' . $executionTime . ' seconds, status: ' . $functionStatus);

            $execution = [
                'status' => $functionStatus,
                'statusCode' => $statusCode,
                'response' => \mb_strcut($res, 0, 1000000), // Limit to 1MB
                'stdout' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'duration' => $executionTime,
            ];

            // Update swoole table
            $runtime['updated'] = \time();
            $activeRuntimes->set($runtimeId, $runtime);

            // Finish request
            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

App::get('/v1/health')
    ->param('name', '', new Text(64), 'Name of the executor.')
    ->desc("Get usage stats of host machine CPU usage from 0 to 100.")
    ->inject('response')
    ->action(function (string $name, Response $response) use ($orchestrationPool) {
        $systemCores = System::getCPUCores();
        $systemUsage = System::getCPUUtilisation() / $systemCores;
        $functionsUsage = [];

        try {
            $orchestration = $orchestrationPool->get();
            $containerUsages = $orchestration->getStats(filters: [ 'label' => 'openruntimes-executor=' . $name ], cycles: 3);

            foreach ($containerUsages as $containerUsage) {
                $functionsUsage[$containerUsage['name']] = $containerUsage['cpu'] * 100;
            }
        } catch (\Exception $err) {
            // TODO: @Meldiron Handle better
            \var_dump($err);
        } finally {
            $orchestrationPool->put($orchestration);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json([
                'status' => 'pass',
                'hostUsage' => $systemUsage,
                'functionsUsage' => $functionsUsage
            ]);
    });

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

$payloadSize = 6 * (1024 * 1024); // 6MB
$workerNumber = swoole_cpu_num() * intval(App::getEnv('_APP_WORKER_PER_CORE', 6));

$http
    ->set([
        'worker_num' => $workerNumber,
        'open_http2_protocol' => true,
        // 'document_root' => __DIR__.'/../public',
        // 'enable_static_handler' => true,
        'http_compression' => true,
        'http_compression_level' => 6,
        'package_max_length' => $payloadSize,
        'buffer_output_size' => $payloadSize,
    ]);

/** Set Resources */
App::setResource('orchestrationPool', fn() => $orchestrationPool);
App::setResource('activeRuntimes', fn() => $activeRuntimes);

/** Set callbacks */
App::error()
    ->inject('utopia')
    ->inject('error')
    ->inject('request')
    ->inject('response')
    ->action(function (App $utopia, throwable $error, Request $request, Response $response) {
        $route = $utopia->match($request);
        logError($error, "httpError", $route);

        switch ($error->getCode()) {
            case 400: // Error allowed publicly
            case 401: // Error allowed publicly
            case 402: // Error allowed publicly
            case 403: // Error allowed publicly
            case 404: // Error allowed publicly
            case 406: // Error allowed publicly
            case 409: // Error allowed publicly
            case 412: // Error allowed publicly
            case 425: // Error allowed publicly
            case 429: // Error allowed publicly
            case 501: // Error allowed publicly
            case 503: // Error allowed publicly
                $code = $error->getCode();
                break;
            default:
                $code = 500; // All other errors get the generic 500 server error status code
        }

        $output = [
            'message' => $error->getMessage(),
            'code' => $error->getCode(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => $error->getTrace(),
            'version' => App::getEnv('_APP_VERSION', 'UNKNOWN')
        ];

        $response
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Expires', '0')
            ->addHeader('Pragma', 'no-cache')
            ->setStatusCode($code);

        $response->json($output);
    });

App::init()
    ->inject('request')
    ->action(function (Request $request) {
        $secretKey = $request->getHeader('x-appwrite-executor-key', '');
        if (empty($secretKey)) {
            throw new Exception('Missing executor key', 401);
        }

        if ($secretKey !== App::getEnv('_APP_EXECUTOR_SECRET', '')) {
            throw new Exception('Missing executor key', 401);
        }
    });


$http->on('start', function ($http) {
    global $orchestrationPool;
    global $activeRuntimes;

    /**
     * Warmup: make sure images are ready to run fast 🚀
     */
    $runtimes = new Runtimes('v2');
    $allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));
    $runtimes = $runtimes->getAll(true, $allowList);
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

    /**
     * Remove residual runtimes
     */
    Console::info('Removing orphan runtimes...');
    try {
        $orchestration = $orchestrationPool->get();
        $orphans = $orchestration->list(['label' => 'openruntimes-type=runtime']);
    } finally {
        $orchestrationPool->put($orchestration);
    }

    foreach ($orphans as $runtime) {
        go(function () use ($runtime, $orchestrationPool) {
            // TODO: @Meldiron Only remove containers prefixed with this executor name

            try {
                $orchestration = $orchestrationPool->get();
                $orchestration->remove($runtime->getName(), true);
                Console::success("Successfully removed {$runtime->getName()}");
            } catch (\Throwable $th) {
                Console::error('Orphan runtime deletion failed: ' . $th->getMessage());
            } finally {
                $orchestrationPool->put($orchestration);
            }
        });
    }

    /**
     * Register handlers for shutdown
     */
    @Process::signal(SIGINT, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGQUIT, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGKILL, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGTERM, function () use ($http) {
        $http->shutdown();
    });

    /**
     * Run a maintenance worker every MAINTENANCE_INTERVAL seconds to remove inactive runtimes
     */
    Timer::tick(MAINTENANCE_INTERVAL * 1000, function () use ($orchestrationPool, $activeRuntimes) {
        Console::warning("Running maintenance task ...");
        foreach ($activeRuntimes as $runtime) {
            $inactiveThreshold = \time() - App::getEnv('_APP_FUNCTIONS_INACTIVE_THRESHOLD', 60);
            if ($runtime['updated'] < $inactiveThreshold) {
                go(function () use ($runtime, $orchestrationPool, $activeRuntimes) {
                    try {
                        $orchestration = $orchestrationPool->get();
                        $orchestration->remove($runtime['name'], true);
                        $activeRuntimes->del($runtime['name']);
                        Console::success("Successfully removed {$runtime['name']}");
                    } catch (\Throwable $th) {
                        Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                    } finally {
                        $orchestrationPool->put($orchestration);
                    }
                });
            }
        }
    });
});


$http->on('beforeShutdown', function () {
    global $orchestrationPool;
    Console::info('Cleaning up containers before shutdown...');

    $orchestration = $orchestrationPool->get();
    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-type=runtime']);
    $orchestrationPool->put($orchestration);

    foreach ($functionsToRemove as $container) {
        go(function () use ($orchestrationPool, $container) {
            try {
                $orchestration = $orchestrationPool->get();
                $orchestration->remove($container->getId(), true);
                Console::info('Removed container ' . $container->getName());
            } catch (\Throwable $th) {
                Console::error('Failed to remove container: ' . $container->getName());
            } finally {
                $orchestrationPool->put($orchestration);
            }
        });
    }
});


$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

    try {
        $app->run($request, $response);
    } catch (\Throwable $th) {
        logError($th, "serverError");
        $swooleResponse->setStatusCode(500);
        $output = [
            'message' => 'Error: ' . $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace()
        ];
        $swooleResponse->end(\json_encode($output));
    }
});

$http->start();
