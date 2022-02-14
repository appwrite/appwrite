<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Swoole\ConnectionPool;
use Swoole\Coroutine as Co;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Logger\Log;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range as ValidatorRange;
use Utopia\Validator\Text;

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
            $residueList = $orchestration->list(['label' => 'openruntimes-type=function']);
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

function execute(string $runtimeId, string $path, array $vars, string $data, string $baseImage, string $runtime, string $entrypoint, int $timeout): array
{

    Console::info('Executing Runtime: ' . $runtimeId);

    global $activeFunctions;
    $container = 'runtime-' . $runtimeId;

    /** Create a new runtime server if there's none running */
    // if (!$activeFunctions->exists($container)) {
    //     Console::info("Runtime server for $runtimeId not running. Creating new one...");
    //     createRuntimeServer($runtimeId, $path, $vars, $baseImage, $runtime);
    // }

    $key = $activeFunctions->get('runtime-' . $runtimeId, 'key');

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


// POST      /v1/runtimes
App::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(128), 'Unique runtime ID.')
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('destination', '', new Text(0), 'Destination folder to store build files into.')
    ->param('vars', '', new Assoc(), 'Environment Variables required for the build')
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function')
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime')
    ->inject('response')
    ->action(function (string $runtimeId, string $source, string $destination, array $vars, string $runtime, string $baseImage, Response $response) {

        // TODO: Check if runtime already exists..

        // TODO: Move orchestration pool and swoole table to a utopia resource
        global $orchestrationPool;
        global $activeFunctions;
        $orchestration = $orchestrationPool->get();

        $build = [];
        $id = '';
        $buildStdout = '';
        $buildStderr = '';
        $buildStart = \time();
        $buildEnd = 0;

        try {
            Console::info('Building runtime with ID : ' . $runtimeId);
            /** 
             * Temporary file paths in the executor 
             */
            $tmpSource = "/tmp/$runtimeId/code.tar.gz";
            $tmpBuildDir = "/tmp/$runtimeId/builds";
            $tmpBuild = "/tmp/$runtimeId/builds/code.tar.gz";

            /**
             * Copy code files from source to a temporary location on the executor
             */
            $device = new Local($destination);
            $buffer = $device->read($source);
            if(!$device->write($tmpSource, $buffer)) {
                throw new Exception('Failed to write source code to temporary location.', 500);
            };

            /**
             * Create a temporary folder to store builds
             */
            if (!\file_exists($tmpBuildDir)) {
                if (!@\mkdir($tmpBuildDir, 0755, true)) {
                    throw new Exception("Can't create directory : $tmpBuildDir", 500);
                }
            }

            /**
             * Create container
             */
            $container = 'build-' . $runtimeId;
            $vars = array_map(fn ($v) => strval($v), $vars);
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
                    'openruntimes-id' => $runtimeId,
                    'openruntimes-type' => 'build',
                    'openruntimes-created' => strval($buildStart),
                    'openruntimes-runtime' => $runtime,
                ],
                command: [
                    'tail',
                    '-f',
                    '/dev/null'
                ],
                hostname: $container,
                mountFolder: \dirname($tmpSource),
                volumes: [
                    "$tmpBuildDir:/usr/builds:rw"
                ]
            );

            if (empty($id)) {
                throw new Exception('Failed to create build container', 500);
            }

            /** 
             * Extract user code into build container
             */
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

            /**
             * Build code and install dependenices
             */
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

            /**
             * Repackage code and save
             */
            $compressStdout = '';
            $compressStderr = '';
            $compressSuccess = $orchestration->execute(
                name: $container,
                command: [
                    'tar', '-C', '/usr/code', '-czvf', '/usr/builds/code.tar.gz', './'
                ],
                stdout: $compressStdout,
                stderr: $compressStderr,
                timeout: 60
            );

            if (!$compressSuccess) {
                throw new Exception('Failed to compress built code: ' . $compressStderr);
            }

            // Check if the build was successful by checking if file exists
            if (!\file_exists($tmpBuild)) {
                throw new Exception('Something went wrong during the build process.');
            }

            /**
             * Move built code to expected build directory
             */
            $outputPath = $device->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

            if (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL) === Storage::DEVICE_LOCAL) {
                if (!$device->move($tmpBuild, $outputPath)) {
                    throw new Exception('Failed to move built code to storage', 500);
                }
            } else {
                if (!$device->upload($tmpBuild, $outputPath)) {
                    throw new Exception('Failed to upload built code upload to storage', 500);
                }
            }

            if ($buildStdout === '') {
                $buildStdout = 'Build Successful!';
            }

            $buildEnd = \time();
            $build = [
                'outputPath' => $outputPath,
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
        }

        if ( $build['status'] !== 'ready') {
            return $response
                ->setStatusCode(201)
                ->json($build);
        }

        /** Create runtime server */
        try {
            $container = 'runtime-' . $runtimeId;
            if ($activeFunctions->exists($container) && !(\substr($activeFunctions->get($container)['status'], 0, 2) === 'Up')) { // Remove container if not online
                // If container is online then stop and remove it
                try {
                    $orchestration->remove($container, true);
                } catch (Exception $e) {
                    throw new Exception('Failed to remove container: ' . $e->getMessage());
                }
                $activeFunctions->del($container);
            }
    
            /**
             * Copy code files from source to a temporary location on the executor
             */
            $buffer = $device->read($outputPath);
            if(!$device->write($tmpBuild, $buffer)) {
                throw new Exception('Failed to write built code to temporary location.', 500);
            };
    
            /** 
             * Launch Runtime 
            */
            $secret = \bin2hex(\random_bytes(16));
            $vars = \array_merge($vars, [
                'INTERNAL_RUNTIME_KEY' => $secret
            ]);
    
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
                        'openruntimes-id' => $runtimeId,
                        'openruntimes-type' => 'function',
                        'openruntimes-created' => strval($executionTime),
                        'openruntimes-runtime' => $runtime
                    ],
                    hostname: $container,
                    mountFolder: \dirname($tmpBuild),
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
            Console::success('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
        } catch (\Throwable $th) {
            Console::error('Runtime Server Creation Failed: '. $th->getMessage());
        }

        $orchestrationPool->put($orchestration);

        $response
            ->setStatusCode(201)
            ->json($build);
    });


// GET /v1/runtimes
App::get('/v1/runtimes')
    ->desc("Get the list of currently active runtimes")
    ->inject('response')
    ->action(function (Response $response) {
        // TODO : Get list of active runtimes from swoole table
        $runtimes = [];

        $response
            ->setStatusCode(200)
            ->json($runtimes);
    });

// GET /v1/runtimes/:runtimeId
App::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(128), 'Runtime unique ID.')
    ->inject('response')
    ->action(function (Response $response) {
        
        // Get a runtime by its ID
        $runtime = [];

        $response
            ->setStatusCode(200)
            ->json($runtime);
    });

// DELETE    /v1/runtimes/:runtimeId
App::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(128), 'Runtime unique ID.', false)
    ->param('buildIds', [], new ArrayList(new Text(0), 100), 'List of build IDs to delete.', false)
    ->inject('response')
    ->action(function (string $runtimeId, array $buildIds, Response $response) use ($orchestrationPool) {

        Console::info('Deleting runtime: ' . $runtimeId);
        $orchestration = $orchestrationPool->get();

        // Remove the container of the deployment
        $status = $orchestration->remove('runtime-' . $runtimeId , true);
        if ($status) {
            Console::success('Removed runtime container: ' . $runtimeId);
        } else {
            Console::error('Failed to remove runtime container: ' . $runtimeId);
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

        $orchestrationPool->put($orchestration);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });


// POST /v1/execution (get runtime as param, if 404 or 501/503, go and create a runtime first)
App::post('/v1/execution')
    ->desc('Create an execution')
    ->param('runtimeId', '', new Text(1024), 'The runtimeID to execute')
    ->param('path', '', new Text(0), 'Path to built files.', false)
    ->param('vars', '', new Assoc(), 'Environment Variables required for the build', false)
    ->param('data', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function', false)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file')
    ->param('timeout', 15, new ValidatorRange(1, 900), 'Function maximum execution time in seconds.', true)
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime', false)
    ->inject('response')
    ->action(
        function (string $runtimeId, string $path, array $vars, string $data, string $runtime, string $entrypoint, $timeout, string $baseImage, Response $response) {

            // Send both data and vars from the caller 
            $execution = execute($runtimeId, $path, $vars, $data, $baseImage, $runtime, $entrypoint, $timeout);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

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

App::error(function ($error, $utopia, $request, $response) {
    /** @var Exception $error */
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */

    if ($error instanceof PDOException) {
        throw $error;
    }

    $route = $utopia->match($request);
    // logError($error, "httpError", $route);

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

    $response->json($output);
}, ['error', 'utopia', 'request', 'response']);

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

    try {
        $app->run($request, $response);
    } catch (Exception $e) {
        // logError($e, "serverError");
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();