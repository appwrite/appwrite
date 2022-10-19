<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FunctionsProxy\Adapter\RoundRobin;
use FunctionsProxy\Adapter\UsageBased;
use Swoole\Coroutine\Http\Client;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Swoole\Http\Server;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\App;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

// Redis setup
$redisHost = App::getEnv('_APP_REDIS_HOST', '');
$redisPort = App::getEnv('_APP_REDIS_PORT', '');
$redisUser = App::getEnv('_APP_REDIS_USER', '');
$redisPass = App::getEnv('_APP_REDIS_PASS', '');
$redisAuth = '';

if ($redisUser && $redisPass) {
    $redisAuth = $redisUser . ':' . $redisPass;
}

$redisPool = new RedisPool(
    (new RedisConfig())
        ->withHost($redisHost)
        ->withPort($redisPort)
        ->withAuth($redisAuth)
        ->withDbIndex(0),
    64
);

$adapterType = App::getEnv('_APP_FUNCTIONS_PROXY_ADAPTER', 'round-robin');
$adapter = match ($adapterType) {
    'round-robin' => new RoundRobin($redisPool),
    'usage-based' => new UsageBased($redisPool)
};

Console::info("Using adpater: " . $adapterType);

function markOffline(Cache $cache, string $executorHostname, string $error, bool $forceShowError = false)
{
    $data = $cache->load('executors-' . $executorHostname, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorHostname, ['status' => 'offline', 'health' => []]);

    if (!$data || $data['status'] === 'online' || $forceShowError) {
        Console::warning('Executor "' . $executorHostname . '" went down! Message:');
        Console::warning($error);
    }
}

function markOnline(cache $cache, string $executorHostname, bool $forceShowError = false, mixed $health = [])
{
    $data = $cache->load('executors-' . $executorHostname, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorHostname, ['status' => 'online', 'health' => $health]);

    if (!$data || $data['status'] === 'offline' || $forceShowError) {
        Console::success('Executor "' . $executorHostname . '" went online.');
    }
}

// Fetch info about executors
function fetchExecutorsState(RedisPool $redisPool, bool $forceShowError = false)
{
    $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($redisPool, $executor, $forceShowError) {
            $redis = $redisPool->get();
            $cache = new Cache(new Redis($redis));

            try {
                $endpoint = 'http://' . $executor . ':3000/v1/health';

                $ch = \curl_init();

                \curl_setopt($ch, CURLOPT_URL, $endpoint);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'x-appwrite-executor-key: ' . App::getEnv('_APP_EXECUTOR_SECRET', '')
                ]);

                $executorResponse = \curl_exec($ch);
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = \curl_error($ch);

                \curl_close($ch);

                if ($statusCode === 200) {
                    markOnline($cache, $executor, $forceShowError, \json_decode($executorResponse, true));
                } else {
                    $message = 'Code: ' . $statusCode . ' with response "' . $executorResponse .  '" and error error: ' . $error;
                    markOffline($cache, $executor, $message, $forceShowError);
                }
            } catch (\Exception $err) {
                throw $err;
            } finally {
                $redisPool->put($redis);
            }
        });
    }
}

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

Console::success("Waiting for executors to start...");

\sleep(5); // Wait a little so executors can start

fetchExecutorsState($redisPool, true);

Console::log("State of executors at startup:");

go(function () use ($redisPool) {
    $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

    $redis = $redisPool->get();
    $cache = new Cache(new Redis($redis));

    foreach ($executors as $executor) {
        $data = $cache->load('executors-' . $executor, 60 * 60 * 24 * 30 * 3); // 3 months
        Console::log('Executor ' . $executor . ' is ' . ($data['status'] ?? 'unknown') . '.');
    }
});

Swoole\Event::wait();

// TODO: @Meldiron Use async-style instead
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

$run = function (SwooleRequest $request, SwooleResponse $response) use ($adapter) {
    $secretKey = $request->header['x-appwrite-executor-key'] ?? '';

    if (empty($secretKey)) {
        throw new Exception('Missing proxy key');
    }
    if ($secretKey !== App::getEnv('_APP_FUNCTIONS_PROXY_SECRET', '')) {
        throw new Exception('Missing proxy key');
    }

    $body = \json_decode($request->getContent(), true);
    $runtimeId = $body['runtimeId'] ?? null;
    $executor = $adapter->getNextExecutor($runtimeId);

    Console::success("Executing on " . $executor['hostname']);

    $client = new Client($executor['hostname'], 80);
    $client->setMethod($request->server['request_method'] ?? 'GET');
    $client->setHeaders(\array_merge($request->header, [
        'x-appwrite-executor-key' => App::getEnv('_APP_EXECUTOR_SECRET', '')
    ]));
    $client->setData($request->getContent());

    $status = $client->execute($request->server['request_uri'] ?? '/');

    $response->setStatusCode($client->getStatusCode());
    $response->header('content-type', 'application/json; charset=UTF-8');
    $response->write($client->getBody());
    $response->end();
};

$http->on('start', function () use ($redisPool) {
    // TODO: @Meldiron Allow scaling. Only do this on one machine

    // Keep updating executors state
    Timer::tick(10000, function (int $timerId) use ($redisPool) {
        fetchExecutorsState($redisPool, false);
    });
});

$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) use ($run) {
    try {
        call_user_func($run, $swooleRequest, $swooleResponse);
    } catch (\Throwable $th) {
        logError($th, "serverError");

        $output = [
            'message' => 'Error: ' . $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace()
        ];

        $swooleResponse->setStatusCode(500);
        $swooleResponse->header('content-type', 'application/json; charset=UTF-8');
        $swooleResponse->write(\json_encode($output));
        $swooleResponse->end();
    }
});

Console::success("Functions proxy is ready.");

$http->start();
