<?php

require_once __DIR__ . '/../vendor/autoload.php';


use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Swoole\Http\Server;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Timer;
use Utopia\App;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;

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

function markOffline(Cache $cache, string $executorId, string $error) {
    $data = $cache->load('executors-' . $executorId, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorId, [ 'state' => 'offline' ]);

    if(!$data || $data['state'] === 'online') {
        Console::warning('Executor "' . $executorId . '" went down! Message:');
        Console::warning($error);
    }
}

function markOnline(cache $cache, string $executorId) {
    $data = $cache->load('executors-' . $executorId, 60 * 60 * 24 * 30 * 3); // 3 months

    $cache->save('executors-' . $executorId, [ 'state' => 'online' ]);

    if(!$data || $data['state'] === 'offline') {
        Console::success('Executor "' . $executorId . '" went online.');
    }
}

// Fetch info about executors
function fetchExecutorsState(RedisPool $redisPool)
{
    $executors = \explode(',', App::getEnv('_APP_EXECUTORS', ''));

    foreach ($executors as $executor) {
        go(function () use ($redisPool, $executor) {
            $redis = $redisPool->get();
            $cache = new Cache(new Redis($redis));

            try {
                [$id, $hostname] = \explode('=', $executor);

                $endpoint = $hostname . '/health';

                $ch = \curl_init();

                \curl_setopt($ch, CURLOPT_URL, $endpoint);
                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
                \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'x-appwrite-executor-key: ' . App::getEnv('_APP_EXECUTOR_SECRET', '')
                ]);

                $executorResponse = \curl_exec($ch); // TODO: Use to save usage stats
                $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = \curl_error($ch);

                \curl_close($ch);

                if ($statusCode === 200) {
                    markOnline($cache, $id);
                } else {
                    markOffline($cache, $id, $error);
                }
            } catch (\Exception $err) {
                throw $err;
            } finally {
                $redisPool->put($redis);
            }
        });
    }

    Swoole\Event::wait();
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

// fetchExecutorsState($redisPool);
// Timer::tick(30000, fn (int $timerId, array $params) => fetchExecutorsState($params[0]), [$redisPool]);

Console::success("Functions proxy is ready.");

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

/** Set callbacks */
App::error(function ($utopia, $error, $request, $response) {
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
}, ['utopia', 'error', 'request', 'response']);

App::get('/')
    ->desc("Proxy request into executor")
    ->inject('response')
    ->action(function (Response $response) {
        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json([ 'works' => true ]);
    });

App::init(function ($request, $response) {
    $secretKey = $request->getHeader('x-appwrite-functions-proxy-key', '');

   if (empty($secretKey)) {
       throw new Exception('Missing proxy key', 401);
   }

   if ($secretKey !== App::getEnv('_APP_FUNCTIONS_PROXY_SECRET', '')) {
       throw new Exception('Missing proxy key', 401);
   }
}, ['request', 'response']);

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