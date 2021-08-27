<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Database\Validator\UID;
use Appwrite\Event\Event;
use Appwrite\Utopia\Response\Model\Execution;
use Utopia\App;
use Utopia\Swoole\Request;
use Appwrite\Utopia\Response;
use Utopia\CLI\Console;
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Container;
use Utopia\Orchestration\Exception\Timeout as TimeoutException;
use Utopia\Config\Config;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

use function PHPUnit\Framework\isEmpty;

require_once __DIR__ . '/workers.php';

$dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
$dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
$dockerEmail = App::getEnv('DOCKERHUB_PULL_EMAIL', null);
$orchestration = new Orchestration(new DockerAPI($dockerUser, $dockerPass, $dockerEmail));

$runtimes = Config::getParam('runtimes');

Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_CURL);

// Warmup: make sure images are ready to run fast 🚀
Co\run(function() use ($runtimes, $orchestration) {
    foreach($runtimes as $runtime) {
        go(function() use ($runtime, $orchestration) {
            Console::info('Warming up '.$runtime['name'].' '.$runtime['version'].' environment...');
                
            $response = $orchestration->pull($runtime['image']);

            if ($response) {
                Console::success("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
            } else {
                Console::error("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
            }
        });
    }
});

/**
 * List function servers
 */
$executionStart = \microtime(true);

$response = $orchestration->list(['label' => 'appwrite-type=function']);

$activeFunctions = [];

foreach ($response as $value) {
    $activeFunctions[$value->getName()] = $value;
}

$executionEnd = \microtime(true);

Console::info(count($activeFunctions).' functions listed in ' . ($executionEnd - $executionStart) . ' seconds');

App::post('/v1/execute') // Define Route
    ->inject('request')
    ->param('trigger', '', new Text(1024))
    ->param('projectId', '', new Text(1024))
    ->param('executionId', '', new Text(1024), '', true)
    ->param('functionId', '', new Text(1024))
    ->param('event', '', new Text(1024), '', true)
    ->param('eventData', '', new Text(1024), '', true)
    ->param('data', '', new Text(1024), '', true)
    ->param('webhooks', [], new ArrayList(new JSON()), [], true)
    ->param('userId', '', new Text(1024), '', true)
    ->param('JWT', '', new Text(1024), '', true)
    ->inject('response')
    ->action(
        function ($trigger, $projectId, $executionId, $functionId, $event, $eventData, $data, $webhooks, $userId, $JWT, $request, $response) {
            try {
                $data = execute($trigger, $projectId, $executionId, $functionId, $event, $eventData, $data, $webhooks, $userId, $JWT);
                return $response->json($data);
            } catch (Exception $e) {
                return $response
                    ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->addHeader('Expires', '0')
                    ->addHeader('Pragma', 'no-cache')
                    ->json(['error' => $e->getMessage()]);
            }
        }
    );

App::post('/v1/tag')
    ->param('functionId', '', new UID(), 'Function unique ID.')
    ->param('tagId', '', new UID(), 'Tag unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('projectID')
    ->action(function ($functionId, $tagId, $response, $projectDB, $projectID) {
        Authorization::disable();
        $project = $projectDB->getDocument($projectID);
        $function = $projectDB->getDocument($functionId);
        $tag = $projectDB->getDocument($tagId);
        Authorization::reset();

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (empty($function->getAttribute('tag')) && !empty($schedule)) ? new CronExpression($schedule) : null;
        $next = (empty($function->getAttribute('tag')) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

        Authorization::disable();
        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            'tag' => $tag->getId(),
            'scheduleNext' => $next
        ]));
        Authorization::reset();

        // Deploy Runtime Server
        createRuntimeServer($functionId, $projectID, $tag);

        if ($next) { // Init first schedule
            ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                'projectId' => $projectID,
                'webhooks' => $project->getAttribute('webhooks', []),
                'functionId' => $function->getId(),
                'executionId' => null,
                'trigger' => 'schedule',
            ]);  // Async task reschedule
        }

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/healthz')
    ->inject('request')
    ->inject('response')
    ->action(
        function ($request, $response) {
            $response
                ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->addHeader('Expires', '0')
                ->addHeader('Pragma', 'no-cache')
                ->json(['status' => 'online']);
        }
    );

function createRuntimeServer(string $functionId, string $projectId, Document $tag) {
    global $register;
    global $orchestration;
    global $runtimes;
    global $activeFunctions;

    $db = $register->get('db');
    $cache = $register->get('cache');

    // Create new Database Instance
    $database = new Database();
    $database->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
    $database->setNamespace('app_' . $projectId);
    $database->setMocks(Config::getParam('collections', []));

    // Grab Tag Document
    Authorization::disable();
    $function = $database->getDocument($functionId);
    $tag = $database->getDocument($function->getAttribute('tag', ''));
    Authorization::reset();

    // Check if runtime is active
    $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
        ? $runtimes[$function->getAttribute('runtime', '')]
        : null;

    if ($tag->getAttribute('functionId') !== $function->getId()) {
        throw new Exception('Tag not found', 404);
    }

    if (\is_null($runtime)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_TAG' => $tag->getId(),
        'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
        'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
        'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
    ]);

    $container = 'appwrite-function-' . $tag->getId();

    if (isset($activeFunctions[$container]) && !(\substr($activeFunctions[$container]->getStatus(), 0, 2) === 'Up')) { // Remove conatiner if not online
        // If container is online then stop and remove it
        try {
            $orchestration->remove($container);
        } catch (Exception $e) {
            Console::warning('Failed to remove container: ' . $e->getMessage());
        }

        unset($activeFunctions[$container]);
    }

    // Grab Tag Files
    $tagPath = $tag->getAttribute('path', '');
    $tagPathTarget = '/tmp/project-' . $projectId . '/' . $tag->getId() . '/code.tar.gz';
    $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
    $container = 'appwrite-function-' . $tag->getId();

    if (!\is_readable($tagPath)) {
        throw new Exception('Code is not readable: ' . $tag->getAttribute('path', ''));
    }

    if (!\file_exists($tagPathTargetDir)) {
        if (!\mkdir($tagPathTargetDir, 0755, true)) {
            throw new Exception('Can\'t create directory ' . $tagPathTargetDir);
        }
    }

    if (!\file_exists($tagPathTarget)) {
        if (!\copy($tagPath, $tagPathTarget)) {
            throw new Exception('Can\'t create temporary code file ' . $tagPathTarget);
        }
    }

    /**
     * Limit CPU Usage - DONE
     * Limit Memory Usage - DONE
     * Limit Network Usage
     * Limit Storage Usage (//--storage-opt size=120m \)
     * Make sure no access to redis, mariadb, influxdb or other system services
     * Make sure no access to NFS server / storage volumes
     * Access Appwrite REST from internal network for improved performance
     */
    if (!isset($activeFunctions[$container])) { // Create contianer if not ready
        $executionStart = \microtime(true);
        $executionTime = \time();

        $orchestration->setCpus(App::getEnv('_APP_FUNCTIONS_CPUS', '1'));
        $orchestration->setMemory(App::getEnv('_APP_FUNCTIONS_MEMORY', '256'));
        $orchestration->setSwap(App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '256'));
        foreach ($vars as &$value) {
            $value = strval($value);
        }

        $id = $orchestration->run(
            image: $runtime['image'],
            name: $container,
            vars: $vars,
            labels: [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($executionTime),
                'appwrite-runtime' => $function->getAttribute('runtime', ''),
            ],
            hostname: $container,
            mountFolder: $tagPathTargetDir,
        );

        // Add to network
        $orchestration->networkConnect($container, 'appwrite_runtimes');

        $untarStdout = '';
        $untarStderr = '';

        $untarSuccess = $orchestration->execute(
            name: $container,
            command: [
                'sh',
                '-c',
                'mkdir /usr/code -p && cp /tmp/code.tar.gz /usr/code/code.tar.gz && cd /usr/code && tar -zxf /usr/code/code.tar.gz --strip 1 && rm /usr/code/code.tar.gz'
            ],
            stdout: $untarStdout,
            stderr: $untarStderr,
            timeout: 60
        );

        if (!$untarSuccess) {
            throw new Exception('Failed to extract tar: ' . $untarStderr);
        }

        $executionEnd = \microtime(true);

        $activeFunctions[$container] = new Container(
            $container,
            $id,
            'Up',
            [
                'appwrite-type' => 'function',
                'appwrite-created' => strval($executionTime),
                'appwrite-runtime' => $function->getAttribute('runtime', ''),
            ]
        );

        Console::info('Runtime Server created in ' . ($executionEnd - $executionStart) . ' seconds');
    } else {
        Console::info('Runtime server is ready to run');
    }
};

function execute(string $trigger, string $projectId, string $executionId, string $functionId, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): array
{
    global $activeFunctions;
    global $runtimes;

    global $register;

    $db = $register->get('db');
    $cache = $register->get('cache');

    // Create new Database Instance
    $database = new Database();
    $database->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
    $database->setNamespace('app_' . $projectId);
    $database->setMocks(Config::getParam('collections', []));

    // Grab Tag Document
    Authorization::disable();
    $function = $database->getDocument($functionId);
    $tag = $database->getDocument($function->getAttribute('tag', ''));
    Authorization::reset();

    if ($tag->getAttribute('functionId') !== $function->getId()) {
        throw new Exception('Tag not found', 404);
    }

    Authorization::disable();
    // Grab execution document if exists
    // It it doesn't exist, create a new one.
    $execution = (!empty($executionId)) ? $database->getDocument($executionId) : $database->createDocument([
        '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
        '$permissions' => [
            'read' => [],
            'write' => [],
        ],
        'dateCreated' => time(),
        'functionId' => $function->getId(),
        'trigger' => $trigger, // http / schedule / event
        'status' => 'processing', // waiting / processing / completed / failed
        'exitCode' => 0,
        'stdout' => '',
        'stderr' => '',
        'time' => 0,
    ]);

    if (false === $execution || ($execution instanceof Document && $execution->isEmpty())) {
        throw new Exception('Failed to create or read execution');
    }

    Authorization::reset();

    // Check if runtime is active
    $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
        ? $runtimes[$function->getAttribute('runtime', '')]
        : null;

    if (\is_null($runtime)) {
        throw new Exception('Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
    }

    // Process environment variables
    $vars = \array_merge($function->getAttribute('vars', []), [
        'APPWRITE_FUNCTION_ID' => $function->getId(),
        'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
        'APPWRITE_FUNCTION_TAG' => $tag->getId(),
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

    $container = 'appwrite-function-' . $tag->getId();

    if (!isset($activeFunctions[$container])) { // Create contianer if not ready
        createRuntimeServer($functionId, $projectId, $tag);
    } else {
        Console::info('Container is ready to run');
    }

    $stdout = '';
    $stderr = '';

    $executionStart = \microtime(true);

    $exitCode = 0;

    // cURL request to runtime
    $ch = \curl_init();
    \curl_setopt($ch, CURLOPT_URL, "http://".$container.":3000/");
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'path' => '/usr/code',
        'file' => 'index.js',
        'env' => $vars,
        'payload' => $data
    ]));
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_TIMEOUT, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));
    \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $executorResponse = \curl_exec($ch);

    $error = \curl_error($ch);
    if (!empty($error)) {
        throw new Exception('Curl error: ' . $error, 500);
    }

    \curl_close($ch);

    $executionData = json_decode($executorResponse, true);
    if (\is_null($executionData)) {
        throw new Exception('Failed to decode JSON response', 500);
    }
    if (isset($executionData['code'])) {
        $exitCode = $executionData['code'];
    }

    if ($exitCode === 500) {
        $stderr = $executionData['message'];
    } else {
        $stdout = $executorResponse;
    }

    $executionEnd = \microtime(true);
    $executionTime = ($executionEnd - $executionStart);
    $functionStatus = ($exitCode === 0) ? 'completed' : 'failed';

    Console::info('Function executed in ' . ($executionEnd - $executionStart) . ' seconds, status: ' . $functionStatus);

    Authorization::disable();

    $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
        'tagId' => $tag->getId(),
        'status' => $functionStatus,
        'exitCode' => $exitCode,
        'stdout' => \mb_substr($stdout, -4000), // log last 4000 chars output
        'stderr' => \mb_substr($stderr, -4000), // log last 4000 chars output
        'time' => $executionTime
    ]));

    Authorization::reset();

    if (false === $function) {
        throw new Exception('Failed saving execution to DB', 500);
    }

    $executionModel = new Execution();
    $executionUpdate = new Event('v1-webhooks', 'WebhooksV1');

    $executionUpdate
        ->setParam('projectId', $projectId)
        ->setParam('userId', $userId)
        ->setParam('webhooks', $webhooks)
        ->setParam('event', 'functions.executions.update')
        ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));

    $executionUpdate->trigger();

    $usage = new Event('v1-usage', 'UsageV1');

    $usage
        ->setParam('projectId', $projectId)
        ->setParam('functionId', $function->getId())
        ->setParam('functionExecution', 1)
        ->setParam('functionStatus', $functionStatus)
        ->setParam('functionExecutionTime', $executionTime * 1000) // ms
        ->setParam('networkRequestSize', 0)
        ->setParam('networkResponseSize', 0);

    if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
        $usage->trigger();
    }

    return [
        'status' => $functionStatus,
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'time' => $executionTime
    ];
}

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 8080);

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    global $register;

    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

    $db = $register->get('dbPool')->get();
    $redis = $register->get('redisPool')->get();

    App::setResource('db', function () use (&$db) {
        return $db;
    });

    App::setResource('cache', function () use (&$redis) {
        return $redis;
    });

    $projectId = $request->getHeader('x-appwrite-project', '');

    App::setResource('projectDB', function($db, $cache) use ($projectId) {
        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $projectDB->setNamespace('app_'.$projectId);
        $projectDB->setMocks(Config::getParam('collections', []));
    
        return $projectDB;
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
    
        Console::error('[Error] Timestamp: '.date('c', time()));
        
        if($route) {
            Console::error('[Error] Method: '.$route->getMethod());
        }
        
        Console::error('[Error] Type: '.get_class($error));
        Console::error('[Error] Message: '.$error->getMessage());
        Console::error('[Error] File: '.$error->getFile());
        Console::error('[Error] Line: '.$error->getLine());
    
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $code = $error->getCode();
        $message = $error->getMessage();
    
        //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised
    
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
            ->setStatusCode($code)
        ;
    
        $response->dynamic(new Document($output),
            $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR);
    }, ['error', 'utopia', 'request', 'response']);

    App::setResource('projectID', function() use ($projectId) {
        return $projectId;
    });

    try {
        $app->run($request, $response);
    } catch (Exception $e) {
        Console::error('There\'s a problem with ' . $request->getURI());
        $swooleResponse->end('500: Server Error');
    }
});

$http->start();