<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use Swoole\ConnectionPool;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range as ValidatorRange;
use Utopia\Validator\Text;

// TODO
// Implement other endpoints - Done
// Handle shutdown - Done
// Get list of supported runtimes on startup - Done
// Pull runtimes on startup -- Done
// Move some logic to server start - Done
// Add updated property to swoole table - Done
// Clean up deployments older than X seconds - Done
// Remove orphans on startup - done
// Remove multiple request attempt to the runtime logic in executor - done
// Remove builds param from delete endpoint - done
// Shutdown callback isn't working as expected - done
// Fix error handling - done

// Fix logging
// Fix delete endpoint
// Incorporate Matej's changes in the build stage ( moving of the tar file will be performed by the runtime and not the build stage )
// Add size validators for the runtime IDs 
// Decide on logic for build and runtime containers names ( runtime-ID and build-ID)

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

/** Constants */
const MAINTENANCE_INTERVAL = 1200; // 20 minutes

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

App::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(128), 'Unique runtime ID.')
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('destination', '', new Text(0), 'Destination folder to store build files into.')
    ->param('vars', '', new Assoc(), 'Environment Variables required for the build')
    // refactor to `name`
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function')
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime')
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, string $source, string $destination, array $vars, string $runtime, string $baseImage, $orchestrationPool, $activeRuntimes, Response $response) {

        // TODO: Check if runtime already exists..
        $container = 'runtime-' . $runtimeId;

        if ($activeRuntimes->exists($container)) {
            throw new Exception('Runtime already exists.', 409);
        }

        $build = [];
        $buildId = '';
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
                throw new Exception('Failed to copy source code to temporary directory', 500);
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
            $orchestration = $orchestrationPool->get();
            $container = 'build-' . $runtimeId;
            $vars = array_map(fn ($v) => strval($v), $vars);
            $orchestration
                ->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', 0))
                ->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', 256))
                ->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', 256));
            
            $buildId = $orchestration->run(
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

            if (empty($buildId)) {
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
                throw new Exception('Failed to extract tarfile ' . $untarStderr, 500);
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
                throw new Exception('Failed to build dependencies: ' . $buildStderr, 500);
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
                throw new Exception('Failed to compress built code: ' . $compressStderr, 500);
            }

            // Check if the build was successful by checking if file exists
            if (!\file_exists($tmpBuild)) {
                throw new Exception('Something went wrong during the build process');
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

            Console::success('Build Stage completed in ' . ($buildEnd - $buildStart) . ' seconds');
        
        } catch (Throwable $th) {
            Console::error('Build failed: ' . $th->getMessage());
            throw new Exception($th->getMessage(), 500);
        } finally {
            if (!empty($buildId)) {
                $orchestration->remove($buildId, true);
            }
            $orchestrationPool->put($orchestration);
        }

        /** Create runtime server */
        try {
            $orchestration = $orchestrationPool->get();
            /**
             * Copy code files from source to a temporary location on the executor
             */
            $buffer = $device->read($outputPath);
            if(!$device->write($tmpBuild, $buffer)) {
                throw new Exception('Failed to copy built code to temporary location.', 500);
            };
    
            /** 
             * Launch Runtime 
            */
            $container = 'runtime-' . $runtimeId;
            $secret = \bin2hex(\random_bytes(16));
            $vars = \array_merge($vars, [
                'INTERNAL_RUNTIME_KEY' => $secret
            ]);
    
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
                throw new Exception('Failed to create runtime', 500);
            }

            $orchestration->networkConnect($container, App::getEnv('_APP_EXECUTOR_RUNTIME_NETWORK', 'openruntimes'));

            $executionEnd = \microtime(true);

            $activeRuntimes->set($container, [
                'id' => $id,
                'name' => $container,
                'created' => $executionTime,
                'updated' => $executionTime,
                'status' => 'Up ' . \round($executionEnd - $executionStart, 2) . 's',
                'key' => $secret,
            ]);

            Console::success('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
        } catch (\Throwable $th) {
            Console::error('Runtime Server Creation Failed: '. $th->getMessage());
            throw new Exception($th->getMessage(), 500);
        } finally {
            $orchestrationPool->put($orchestration);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($build);
    });


App::get('/v1/runtimes')
    ->desc("List currently active runtimes")
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($activeRuntimes, Response $response) {
        $runtimes = [];

        foreach($activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }

        $response
            ->setStatusCode(200)
            ->json($runtimes);
    });

App::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(128), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($runtimeId, $activeRuntimes, Response $response) {

        if(!$activeRuntimes->exists($runtimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($runtimeId);

        $response
            ->setStatusCode(200)
            ->json($runtime);
    });

App::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(128), 'Runtime unique ID.', false)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, $orchestrationPool, $activeRuntimes, Response $response) {

        $container = 'runtime-' . $runtimeId;

        if(!$activeRuntimes->exists($container)) {
            throw new Exception('Runtime not found', 404);
        }

        Console::info('Deleting runtime: ' . $container);

        try {
            $orchestration = $orchestrationPool->get();
            $orchestration->remove($container, true);
            $activeRuntimes->del($container);
            Console::success('Removed runtime container: ' . $container);
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
    ->param('runtimeId', '', new Text(1024), 'The runtimeID to execute')
    ->param('path', '', new Text(0), 'Path containing the built files.', false)
    ->param('vars', '', new Assoc(), 'Environment variables required for the build', false)
    ->param('data', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function', false)
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file')
    ->param('timeout', 15, new ValidatorRange(1, 900), 'Function maximum execution time in seconds.', true)
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime', false)
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(
        function (string $runtimeId, string $path, array $vars, string $data, string $runtime, string $entrypoint, $timeout, string $baseImage, $activeRuntimes, Response $response) {

            $container = 'runtime-' . $runtimeId;

            if (!$activeRuntimes->exists($container)) {
                throw new Exception('Runtime not found. Please create the runtime.', 404);
            }

            $runtime = $activeRuntimes->get($container);
            $secret = $runtime['key'];
            if (empty($secret)) {
                throw new Exception('Runtime secret not found. Please create the runtime.', 500);
            }

            Console::info('Executing Runtime: ' . $runtimeId);
            
            $executionStart = \microtime(true);
            $stdout = '';
            $stderr = '';
            $statusCode = 0;
            $errNo = -1;
            $executorResponse = '';

            try {
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
                    'x-internal-challenge: ' . $secret,
                    'host: null'
                ]);
        
                $executorResponse = \curl_exec($ch);
        
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
                $error = \curl_error($ch);
        
                $errNo = \curl_errno($ch);
        
                \curl_close($ch);

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

                /** Update swoole table */
                $runtime['updated'] = \time();
                $activeRuntimes->set($container, $runtime);
            } catch (\Throwable $th) {
                Console::error('Runtime execution failed: ' . $th->getMessage());
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

/** Set Resources */
App::setResource('orchestrationPool', fn() => $orchestrationPool);
App::setResource('activeRuntimes', fn() => $activeRuntimes);

/** Set callbacks */
App::error(function ($error, $response) {
    // $route = $utopia->match($request);
    // logError($error, "httpError", $route);

    $output = [
        'message' => $error->getMessage(),
        'code' => $error->getCode(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $error->getTrace(),
        'version' => App::getEnv('_APP_VERSION', 'UNKNOWN'),
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($error->getCode());

    $response->json($output);
}, ['error', 'response']);

App::init(function ($request, $response) {
     $secretKey = $request->getHeader('x-appwrite-executor-key', '');
     if (empty($secretKey)) {
         throw new Exception('Missing executor key', 401);
     }

     if ($secretKey !== App::getEnv('_APP_EXECUTOR_SECRET', '')) {
        throw new Exception('Missing executor key', 401);
     }
}, ['request', 'response']);


$http->on('start', function ($http) {
    global $orchestrationPool;
    global $activeRuntimes;
    
    /** 
     * Warmup: make sure images are ready to run fast 🚀
     */
    $runtimes = new Runtimes();
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
        $orphans = $orchestration->list(['label' => 'openruntimes-type=function']);
    } catch (\Throwable $th) {
    } finally {
        $orchestrationPool->put($orchestration);
    }

    foreach ($orphans as $runtime) {
        go(function () use ($runtime, $orchestrationPool) {
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
            $inactiveThreshold = \time() - App::getEnv('OPENRUNTIMES_INACTIVE_THRESHOLD', 60);
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


$http->on('beforeShutdown', function() {
    global $orchestrationPool;
    Console::info('Cleaning up containers before shutdown...');

    $orchestration = $orchestrationPool->get();
    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-type=function']);
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
        // logError($e, "serverError");
        $swooleResponse->setStatusCode(500);
        $output = [
            'message' => 'Error: '. $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace()
        ];
        $swooleResponse->end(\json_encode($output));
    }
});

$http->start();