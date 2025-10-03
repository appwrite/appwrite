<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Runtime;
use Swoole\Table;
use Swoole\Timer;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
use Utopia\App;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\WebSocket\Adapter;
use Utopia\WebSocket\Server;

/**
 * @var \Utopia\Registry\Registry $register
 */
require_once __DIR__ . '/init.php';

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

// Allows overriding
if (!function_exists('getConsoleDB')) {
    function getConsoleDB(): Database
    {
        global $register;

        /** @var \Utopia\Pools\Group $pools */
        $pools = $register->get('pools');

        $dbAdapter = $pools
            ->get('console')
            ->pop()
            ->getResource()
        ;

        $database = new Database($dbAdapter, getCache());

        $database
            ->setNamespace('_console')
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', '_console');

        return $database;
    }
}

// Allows overriding
if (!function_exists('getProjectDB')) {
    function getProjectDB(Document $project): Database
    {
        global $register;

        /** @var \Utopia\Pools\Group $pools */
        $pools = $register->get('pools');

        if ($project->isEmpty() || $project->getId() === 'console') {
            return getConsoleDB();
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        $adapter = $pools
            ->get($dsn->getHost())
            ->pop()
            ->getResource();

        $database = new Database($adapter, getCache());

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn->getHost(), $sharedTables)) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getInternalId())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getInternalId());
        }

        $database
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());

        return $database;
    }
}

// Allows overriding
if (!function_exists('getCache')) {
    function getCache(): Cache
    {
        global $register;

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        $list = Config::getParam('pools-cache', []);
        $adapters = [];

        foreach ($list as $value) {
            $adapters[] = $pools
                ->get($value)
                ->pop()
                ->getResource()
            ;
        }

        return new Cache(new Sharding($adapters));
    }
}

// Allows overriding
if (!function_exists('getRedis')) {
    function getRedis(): \Redis
    {
        $host = System::getEnv('_APP_REDIS_HOST', 'localhost');
        $port = System::getEnv('_APP_REDIS_PORT', 6379);
        $pass = System::getEnv('_APP_REDIS_PASS', '');

        $redis = new \Redis();
        @$redis->pconnect($host, (int)$port);
        if ($pass) {
            $redis->auth($pass);
        }
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return $redis;
    }
}

if (!function_exists('getTimelimit')) {
    function getTimelimit(): TimeLimitRedis
    {
        return new TimeLimitRedis("", 0, 1, getRedis());
    }
}

if (!function_exists('getRealtime')) {
    function getRealtime(): Realtime
    {
        return new Realtime();
    }
}

if (!function_exists('getTelemetry')) {
    function getTelemetry(int $workerId): Utopia\Telemetry\Adapter
    {
        return new NoTelemetry();
    }
}

$realtime = getRealtime();

/**
 * Table for statistics across all workers.
 */
$stats = new Table(4096, 1);
$stats->column('projectId', Table::TYPE_STRING, 64);
$stats->column('teamId', Table::TYPE_STRING, 64);
$stats->column('connections', Table::TYPE_INT);
$stats->column('connectionsTotal', Table::TYPE_INT);
$stats->column('messages', Table::TYPE_INT);
$stats->create();

$containerId = uniqid();
$statsDocument = null;
$workerNumber = intval(System::getEnv('_APP_CPU_NUM', swoole_cpu_num())) * intval(System::getEnv('_APP_WORKER_PER_CORE', 6));

$adapter = new Adapter\Swoole(port: System::getEnv('PORT', 80));
$adapter
    ->setPackageMaxLength(64000) // Default maximum Package Size (64kb)
    ->setWorkerNumber($workerNumber);

$server = new Server($adapter);

$logError = function (Throwable $error, string $action) use ($register) {
    $logger = $register->get('logger');

    if ($logger && !$error instanceof Exception) {
        $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("realtime");
        $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());

        $log->setAction($action);

        $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        try {
            $responseCode = $logger->addLog($log);
            Console::info('Error log pushed with status code: ' . $responseCode);
        } catch (Throwable $th) {
            Console::error('Error pushing log: ' . $th->getMessage());
        }
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
};

$server->error($logError);

$server->onStart(function () use ($stats, $register, $containerId, &$statsDocument, $logError) {
    sleep(5); // wait for the initial database schema to be ready
    Console::success('Server started successfully');

    /**
     * Create document for this worker to share stats across Containers.
     */
    go(function () use ($register, $containerId, &$statsDocument) {
        $attempts = 0;
        $database = getConsoleDB();

        do {
            try {
                $attempts++;
                $document = new Document([
                    '$id' => ID::unique(),
                    '$collection' => ID::custom('realtime'),
                    '$permissions' => [],
                    'container' => $containerId,
                    'timestamp' => DateTime::now(),
                    'value' => '{}'
                ]);

                $statsDocument = Authorization::skip(fn () => $database->createDocument('realtime', $document));
                break;
            } catch (Throwable) {
                Console::warning("Collection not ready. Retrying connection ({$attempts})...");
                sleep(DATABASE_RECONNECT_SLEEP);
            }
        } while (true);
        $register->get('pools')->reclaim();
    });

    /**
     * Save current connections to the Database every 5 seconds.
     */
    // TODO: Remove this if check once it doesn't cause issues for cloud
    if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
        Timer::tick(5000, function () use ($register, $stats, &$statsDocument, $logError) {
            $payload = [];
            foreach ($stats as $projectId => $value) {
                $payload[$projectId] = $stats->get($projectId, 'connectionsTotal');
            }
            if (empty($payload) || empty($statsDocument)) {
                return;
            }

            try {
                $database = getConsoleDB();

                $statsDocument
                    ->setAttribute('timestamp', DateTime::now())
                    ->setAttribute('value', json_encode($payload));

                Authorization::skip(fn () => $database->updateDocument('realtime', $statsDocument->getId(), $statsDocument));
            } catch (Throwable $th) {
                call_user_func($logError, $th, "updateWorkerDocument");
            } finally {
                $register->get('pools')->reclaim();
            }
        });
    }
});

$server->onWorkerStart(function (int $workerId) use ($server, $register, $stats, $realtime, $logError) {
    Console::success('Worker ' . $workerId . ' started successfully');

    $telemetry = getTelemetry($workerId);
    $register->set('telemetry', fn () => $telemetry);
    $register->set('telemetry.connectionCounter', fn () => $telemetry->createUpDownCounter('realtime.server.open_connections'));
    $register->set('telemetry.connectionCreatedCounter', fn () => $telemetry->createCounter('realtime.server.connection.created'));
    $register->set('telemetry.messageSentCounter', fn () => $telemetry->createCounter('realtime.server.message.sent'));

    $attempts = 0;
    $start = time();

    Timer::tick(5000, function () use ($server, $register, $realtime, $stats, $logError) {
        /**
         * Sending current connections to project channels on the console project every 5 seconds.
         */
        // TODO: Remove this if check once it doesn't cause issues for cloud
        if (System::getEnv('_APP_EDITION', 'self-hosted') === 'self-hosted') {
            if ($realtime->hasSubscriber('console', Role::users()->toString(), 'project')) {
                $database = getConsoleDB();

                $payload = [];

                $list = Authorization::skip(fn () => $database->find('realtime', [
                    Query::greaterThan('timestamp', DateTime::addSeconds(new \DateTime(), -15)),
                ]));

                /**
                 * Aggregate stats across containers.
                 */
                foreach ($list as $document) {
                    foreach (json_decode($document->getAttribute('value')) as $projectId => $value) {
                        if (array_key_exists($projectId, $payload)) {
                            $payload[$projectId] += $value;
                        } else {
                            $payload[$projectId] = $value;
                        }
                    }
                }

                foreach ($stats as $projectId => $value) {
                    if (!array_key_exists($projectId, $payload)) {
                        continue;
                    }

                    $event = [
                        'project' => 'console',
                        'roles' => ['team:' . $stats->get($projectId, 'teamId')],
                        'data' => [
                            'events' => ['stats.connections'],
                            'channels' => ['project'],
                            'timestamp' => DateTime::formatTz(DateTime::now()),
                            'payload' => [
                                $projectId => $payload[$projectId]
                            ]
                        ]
                    ];

                    $server->send($realtime->getSubscribers($event), json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ]));
                }

                $register->get('pools')->reclaim();
            }
        }
        /**
         * Sending test message for SDK E2E tests every 5 seconds.
         */
        if ($realtime->hasSubscriber('console', Role::guests()->toString(), 'tests')) {
            $payload = ['response' => 'WS:/v1/realtime:passed'];

            $event = [
                'project' => 'console',
                'roles' => [Role::guests()->toString()],
                'data' => [
                    'events' => ['test.event'],
                    'channels' => ['tests'],
                    'timestamp' => DateTime::formatTz(DateTime::now()),
                    'payload' => $payload
                ]
            ];

            $server->send($realtime->getSubscribers($event), json_encode([
                'type' => 'event',
                'data' => $event['data']
            ]));
        }
    });

    while ($attempts < 300) {
        try {
            if ($attempts > 0) {
                Console::error('Pub/sub connection lost (lasted ' . (time() - $start) . ' seconds, worker: ' . $workerId . ').
                    Attempting restart in 5 seconds (attempt #' . $attempts . ')');
                sleep(5); // 5 sec delay between connection attempts
            }
            $start = time();

            /** @var \Appwrite\PubSub\Adapter $pubsub */
            $pubsub = $register->get('pools')->get('pubsub')->pop()->getResource();
            if ($pubsub->ping(true)) {
                $attempts = 0;
                Console::success('Pub/sub connection established (worker: ' . $workerId . ')');
            } else {
                Console::error('Pub/sub failed (worker: ' . $workerId . ')');
            }

            $pubsub->subscribe(['realtime'], function (mixed $redis, string $channel, string $payload) use ($server, $workerId, $stats, $register, $realtime) {
                $event = json_decode($payload, true);

                if ($event['permissionsChanged'] && isset($event['userId'])) {
                    $projectId = $event['project'];
                    $userId = $event['userId'];

                    if ($realtime->hasSubscriber($projectId, 'user:' . $userId)) {
                        $connection = array_key_first(reset($realtime->subscriptions[$projectId]['user:' . $userId]));
                        $consoleDatabase = getConsoleDB();
                        $project = Authorization::skip(fn () => $consoleDatabase->getDocument('projects', $projectId));
                        $database = getProjectDB($project);

                        $user = $database->getDocument('users', $userId);

                        $roles = Auth::getRoles($user);
                        $channels = $realtime->connections[$connection]['channels'];

                        $realtime->unsubscribe($connection);
                        $realtime->subscribe($projectId, $connection, $roles, $channels);

                        $register->get('pools')->reclaim();
                    }
                }

                $receivers = $realtime->getSubscribers($event);

                if (App::isDevelopment() && !empty($receivers)) {
                    Console::log("[Debug][Worker {$workerId}] Receivers: " . count($receivers));
                    Console::log("[Debug][Worker {$workerId}] Receivers Connection IDs: " . json_encode($receivers));
                    Console::log("[Debug][Worker {$workerId}] Event: " . $payload);
                }

                $server->send(
                    $receivers,
                    json_encode([
                        'type' => 'event',
                        'data' => $event['data']
                    ])
                );

                if (($num = count($receivers)) > 0) {
                    $register->get('telemetry.messageSentCounter')->add($num);
                    $stats->incr($event['project'], 'messages', $num);
                }
            });
        } catch (Throwable $th) {
            call_user_func($logError, $th, "pubSubConnection");

            Console::error('Pub/sub error: ' . $th->getMessage());
            $attempts++;
            sleep(DATABASE_RECONNECT_SLEEP);
            continue;
        } finally {
            $register->get('pools')->reclaim();
        }
    }

    Console::error('Failed to restart pub/sub...');
});

$server->onOpen(function (int $connection, SwooleRequest $request) use ($server, $register, $stats, &$realtime, $logError) {
    $app = new App('UTC');
    $request = new Request($request);
    $response = new Response(new SwooleResponse());

    Console::info("Connection open (user: {$connection})");

    App::setResource('pools', fn () => $register->get('pools'));
    App::setResource('request', fn () => $request);
    App::setResource('response', fn () => $response);

    try {
        /** @var Document $project */
        $project = $app->getResource('project');

        /*
         *  Project Check
         */
        if (empty($project->getId())) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing or unknown project ID');
        }

        if (
            array_key_exists('realtime', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['realtime']
            && !(Auth::isPrivilegedUser(Authorization::getRoles()) || Auth::isAppUser(Authorization::getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }

        $timelimit = $app->getResource('timelimit');
        $console = $app->getResource('console'); /** @var Document $console */
        $user = $app->getResource('user'); /** @var Document $user */

        /*
         * Abuse Check
         *
         * Abuse limits are connecting 128 times per minute and ip address.
         */
        $timelimit = $timelimit('url:{url},ip:{ip}', 128, 60);
        $timelimit
            ->setParam('{ip}', $request->getIP())
            ->setParam('{url}', $request->getURI());

        $abuse = new Abuse($timelimit);

        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled' && $abuse->check()) {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many requests');
        }

        /*
         * Validate Client Domain - Check to avoid CSRF attack.
         * Adding Appwrite API domains to allow XDOMAIN communication.
         * Skip this check for non-web platforms which are not required to send an origin header.
         */
        $origin = $request->getOrigin();
        $originValidator = new Origin(array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

        if (!$originValidator->isValid($origin) && $project->getId() !== 'console') {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, $originValidator->getDescription());
        }

        $roles = Auth::getRoles($user);

        $channels = Realtime::convertChannels($request->getQuery('channels', []), $user->getId());

        /**
         * Channels Check
         */
        if (empty($channels)) {
            throw new Exception(Exception::REALTIME_POLICY_VIOLATION, 'Missing channels');
        }

        $realtime->subscribe($project->getId(), $connection, $roles, $channels);

        $user = empty($user->getId()) ? null : $response->output($user, Response::MODEL_ACCOUNT);

        $server->send([$connection], json_encode([
            'type' => 'connected',
            'data' => [
                'channels' => array_keys($channels),
                'user' => $user
            ]
        ]));

        $register->get('telemetry.connectionCounter')->add(1);
        $register->get('telemetry.connectionCreatedCounter')->add(1);

        $stats->set($project->getId(), [
            'projectId' => $project->getId(),
            'teamId' => $project->getAttribute('teamId')
        ]);
        $stats->incr($project->getId(), 'connections');
        $stats->incr($project->getId(), 'connectionsTotal');
    } catch (Throwable $th) {
        call_user_func($logError, $th, "initServer");

        // Handle SQL error code is 'HY000'
        $code = $th->getCode();
        if (!is_int($code)) {
            $code = 500;
        }

        $response = [
            'type' => 'error',
            'data' => [
                'code' => $code,
                'message' => $th->getMessage()
            ]
        ];

        $server->send([$connection], json_encode($response));
        $server->close($connection, $code);

        if (App::isDevelopment()) {
            Console::error('[Error] Connection Error');
            Console::error('[Error] Code: ' . $response['data']['code']);
            Console::error('[Error] Message: ' . $response['data']['message']);
        }
    } finally {
        $register->get('pools')->reclaim();
    }
});

$server->onMessage(function (int $connection, string $message) use ($server, $register, $realtime, $containerId) {
    try {
        $response = new Response(new SwooleResponse());
        $projectId = $realtime->connections[$connection]['projectId'];
        $database = getConsoleDB();

        if ($projectId !== 'console') {
            $project = Authorization::skip(fn () => $database->getDocument('projects', $projectId));
            $database = getProjectDB($project);
        } else {
            $project = null;
        }

        /*
         * Abuse Check
         *
         * Abuse limits are sending 32 times per minute and connection.
         */
        $timeLimit = getTimelimit('url:{url},connection:{connection}', 32, 60);

        $timeLimit
            ->setParam('{connection}', $connection)
            ->setParam('{container}', $containerId);

        $abuse = new Abuse($timeLimit);

        if ($abuse->check() && System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            throw new Exception(Exception::REALTIME_TOO_MANY_MESSAGES, 'Too many messages.');
        }

        $message = json_decode($message, true);

        if (is_null($message) || (!array_key_exists('type', $message) && !array_key_exists('data', $message))) {
            throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message format is not valid.');
        }

        switch ($message['type']) {
            case 'ping':
                $server->send([$connection], json_encode([
                    'type' => 'pong'
                ]));

                break;
            case 'authentication':
                if (!array_key_exists('session', $message['data'])) {
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Payload is not valid.');
                }

                $session = Auth::decodeSession($message['data']['session']);
                Auth::$unique = $session['id'] ?? '';
                Auth::$secret = $session['secret'] ?? '';

                $user = $database->getDocument('users', Auth::$unique);

                if (
                    empty($user->getId()) // Check a document has been found in the DB
                    || !Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret) // Validate user has valid login token
                ) {
                    // cookie not valid
                    throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Session is not valid.');
                }

                $roles = Auth::getRoles($user);
                $channels = Realtime::convertChannels(array_flip($realtime->connections[$connection]['channels']), $user->getId());
                $realtime->subscribe($realtime->connections[$connection]['projectId'], $connection, $roles, $channels);

                $user = $response->output($user, Response::MODEL_ACCOUNT);
                $server->send([$connection], json_encode([
                    'type' => 'response',
                    'data' => [
                        'to' => 'authentication',
                        'success' => true,
                        'user' => $user
                    ]
                ]));

                break;

            default:
                throw new Exception(Exception::REALTIME_MESSAGE_FORMAT_INVALID, 'Message type is not valid.');
        }
    } catch (Throwable $th) {
        $response = [
            'type' => 'error',
            'data' => [
                'code' => $th->getCode(),
                'message' => $th->getMessage()
            ]
        ];

        $server->send([$connection], json_encode($response));

        if ($th->getCode() === 1008) {
            $server->close($connection, $th->getCode());
        }
    } finally {
        $register->get('pools')->reclaim();
    }
});

$server->onClose(function (int $connection) use ($realtime, $stats, $register) {
    if (array_key_exists($connection, $realtime->connections)) {
        $stats->decr($realtime->connections[$connection]['projectId'], 'connectionsTotal');
        $register->get('telemetry.connectionCounter')->add(-1);
    }
    $realtime->unsubscribe($connection);

    Console::info('Connection close: ' . $connection);
});

$server->start();
